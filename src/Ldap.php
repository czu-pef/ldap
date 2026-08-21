<?php

declare(strict_types=1);

namespace CzuPef\Ldap;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LDAP\Connection;
use Throwable;

class Ldap implements LdapContract
{
    /**
     * The long-lived connection bound as the service account.
     */
    protected ?Connection $connection = null;

    public function attempt(string $login, string $password): ?LdapUser
    {
        if (! $user = $this->find($login)) {
            return null;
        }

        // A directory entry with no DN cannot be bound as; treat it as a failed
        // login rather than letting checkCredentials() throw on a login form.
        if ($user->dn === '') {
            return null;
        }

        if (! $this->checkCredentials($user->dn, $password)) {
            return null;
        }

        return $user;
    }

    public function checkCredentials(string $dn, string $password): bool
    {
        if (trim($dn) === '') {
            throw new InvalidArgumentException('DN cannot be empty: an empty DN turns a simple bind into an anonymous one, which succeeds regardless of the password.');
        }

        if ($password === '') {
            throw new InvalidArgumentException('Password cannot be empty: LDAP treats an empty password as an anonymous bind, which succeeds.');
        }

        // Bind on a throwaway connection. Binding as the user on the shared
        // service-account connection would leave every later search running
        // under that user's identity.
        $connection = $this->connect();

        // ldap_bind() emits a PHP warning on invalid credentials, which is a
        // normal outcome here rather than an error.
        set_error_handler(static fn (): bool => true);

        try {
            return ldap_bind($connection, $dn, $password);
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
            @ldap_unbind($connection);
        }
    }

    public function find(string $query): ?LdapUser
    {
        $entries = $this->run($this->filter('find', $query));

        // Anything but exactly one match is ambiguous and must not authenticate.
        if (($entries['count'] ?? 0) !== 1) {
            return null;
        }

        return LdapUser::fromLdapResult($entries[0]);
    }

    public function search(string $query, int $limit = 10): Collection
    {
        $entries = $this->run($this->filter('search', $query), limit: $limit);

        return $this->hydrate($entries);
    }

    public function cursor(?string $filter = null, ?array $attributes = null): Generator
    {
        $connection = $this->connection();
        $filter ??= $this->filter('all');
        $attributes ??= $this->attributes();
        $pageSize = (int) config('ldap.page_size', 500);
        $cookie = '';

        do {
            $result = ldap_search(
                ldap: $connection,
                base: $this->baseDn(),
                filter: $filter,
                attributes: $attributes,
                controls: [[
                    'oid' => LDAP_CONTROL_PAGEDRESULTS,
                    'value' => ['size' => $pageSize, 'cookie' => $cookie],
                ]],
            );

            if (! $result) {
                throw new LdapException('LDAP search failed: '.ldap_error($connection));
            }

            ldap_parse_result(
                $connection,
                $result,
                error_code: $errorCode,
                error_message: $errorMessage,
                controls: $controls,
            );

            if ($errorCode) {
                throw new LdapException("LDAP error {$errorCode}: {$errorMessage}");
            }

            yield from $this->hydrate(ldap_get_entries($connection, $result));

            // The server hands back a cookie pointing at the next page; an
            // empty cookie means this was the last one.
            $cookie = $controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '';
        } while ((string) $cookie !== '');
    }

    public function chunk(int $size, Closure $callback, ?string $filter = null, ?array $attributes = null): int
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Chunk size must be at least 1.');
        }

        $buffer = [];
        $page = 0;
        $total = 0;

        foreach ($this->cursor($filter, $attributes) as $user) {
            $buffer[] = $user;

            if (count($buffer) < $size) {
                continue;
            }

            $total += count($buffer);

            if ($callback(new Collection($buffer), ++$page) === false) {
                return $total;
            }

            $buffer = [];
        }

        if ($buffer !== []) {
            $total += count($buffer);
            $callback(new Collection($buffer), ++$page);
        }

        return $total;
    }

    /**
     * Run a one-shot (unpaged) search.
     *
     * @return array<mixed>
     */
    protected function run(string $filter, int $limit = 0): array
    {
        $connection = $this->connection();

        // A server-side size limit is expected whenever $limit is set, and it
        // surfaces as a PHP warning plus LDAP_SIZELIMIT_EXCEEDED (4) alongside
        // the partial results we actually want.
        $result = @ldap_search(
            ldap: $connection,
            base: $this->baseDn(),
            filter: $filter,
            attributes: $this->attributes(),
            sizelimit: $limit,
        );

        if (! $result) {
            throw new LdapException('LDAP search failed: '.ldap_error($connection));
        }

        return ldap_get_entries($connection, $result);
    }

    /**
     * @param  array<mixed>  $entries
     * @return Collection<int, LdapUser>
     */
    protected function hydrate(array $entries): Collection
    {
        return (new Collection($entries))
            // ldap_get_entries() mixes a scalar 'count' key in with the rows.
            ->filter(static fn (mixed $entry): bool => is_array($entry) && isset($entry['dn']))
            ->map(static fn (array $entry): LdapUser => LdapUser::fromLdapResult($entry))
            ->values();
    }

    /**
     * Build a configured filter, escaping the caller's input into it.
     */
    protected function filter(string $name, ?string $query = null): string
    {
        $template = config("ldap.filters.{$name}");

        if (! is_string($template) || $template === '') {
            throw new LdapException("LDAP filter [{$name}] is not configured.");
        }

        if ($query === null) {
            return $template;
        }

        return str_replace('{query}', ldap_escape($query, '', LDAP_ESCAPE_FILTER), $template);
    }

    /**
     * @return list<string>
     */
    protected function attributes(): array
    {
        return array_values((array) config('ldap.attributes', []));
    }

    protected function baseDn(): string
    {
        $baseDn = config('ldap.base_dn');

        if (! is_string($baseDn) || $baseDn === '') {
            throw new LdapException('LDAP base DN is not configured.');
        }

        return $baseDn;
    }

    /**
     * The shared connection, bound as the service account.
     */
    public function connection(): Connection
    {
        if ($this->connection instanceof Connection) {
            return $this->connection;
        }

        $connection = $this->connect();

        set_error_handler(static fn (): bool => true);

        try {
            $bound = ldap_bind($connection, config('ldap.username'), config('ldap.password'));
        } catch (Throwable) {
            $bound = false;
        } finally {
            restore_error_handler();
        }

        if (! $bound) {
            throw new LdapException('Invalid LDAP service account username or password.');
        }

        return $this->connection = $connection;
    }

    /**
     * Open a new, unbound connection to the server.
     */
    protected function connect(): Connection
    {
        if (! config('ldap.server')) {
            throw new LdapException('LDAP server is not configured.');
        }

        $connection = ldap_connect($this->uri());

        if (! $connection) {
            throw new LdapException('Could not connect to the LDAP server.');
        }

        if (! ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3)) {
            throw new LdapException('Failed to set the LDAP connection to protocol v3.');
        }

        // Active Directory answers searches with referrals that the PHP client
        // cannot follow; chasing them breaks paged results.
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, (int) config('ldap.timeout', 5));

        return $connection;
    }

    /**
     * ldap_connect() takes a single URI; its two-argument host/port form is
     * deprecated since PHP 8.3. This reproduces what that form built
     * internally: a configured scheme wins and the port is ignored, otherwise
     * ldap:// is prefixed and the port appended.
     */
    public function uri(): string
    {
        $server = (string) config('ldap.server');

        if (str_contains($server, '://')) {
            return $server;
        }

        return sprintf('ldap://%s:%s', $server, config('ldap.port'));
    }
}
