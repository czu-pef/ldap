<?php

declare(strict_types=1);

namespace CzuPef\Ldap;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * In-memory stand-in used whenever LDAP_SERVER is empty.
 *
 * By default it invents a user for any login you ask for, so a fresh checkout
 * can log in without touching the directory. Point it at real data with
 * LdapFake::resolveUsersUsing() when your tests need lookups to agree with
 * rows already in the database:
 *
 *     LdapFake::resolveUsersUsing(fn () => User::all()->map(fn ($user) => LdapUser::fake([
 *         'email' => $user->email,
 *         'uic' => $user->uic,
 *         'first_name' => $user->first_name,
 *         'last_name' => $user->last_name,
 *     ])));
 */
class LdapFake implements LdapContract
{
    /**
     * Login that always resolves to "no such user".
     */
    public const MISSING_LOGIN = 'fail';

    /**
     * @var (Closure(): iterable<LdapUser>)|null
     */
    protected static ?Closure $resolver = null;

    /**
     * @var Collection<int, LdapUser>|null
     */
    protected ?Collection $users = null;

    /**
     * Supply the pool of users this fake should answer from.
     *
     * The callback runs once per instance and is memoised, so it may hit the
     * database. Pass null to go back to inventing users on demand.
     *
     * @param  (Closure(): iterable<LdapUser>)|null  $callback
     */
    public static function resolveUsersUsing(?Closure $callback): void
    {
        static::$resolver = $callback;
    }

    /**
     * Forget the pool. Call this from your test case tearDown.
     */
    public static function clearResolvedUsers(): void
    {
        static::$resolver = null;
    }

    /**
     * @return Collection<int, LdapUser>
     */
    public function users(): Collection
    {
        return $this->users ??= new Collection(
            static::$resolver ? (static::$resolver)() : []
        );
    }

    public function attempt(string $login, string $password): ?LdapUser
    {
        if (! $user = $this->find($login)) {
            return null;
        }

        if ($user->dn === '') {
            return null;
        }

        return $this->checkCredentials($user->dn, $password) ? $user : null;
    }

    public function checkCredentials(string $dn, string $password): bool
    {
        // Mirrored from Ldap so a project cannot pass its tests against the fake
        // and then hit these guards for the first time in production.
        if (trim($dn) === '') {
            throw new InvalidArgumentException('DN cannot be empty: an empty DN turns a simple bind into an anonymous one, which succeeds regardless of the password.');
        }

        if ($password === '') {
            throw new InvalidArgumentException('Password cannot be empty: LDAP treats an empty password as an anonymous bind, which succeeds.');
        }

        return $password === (string) config('ldap.fake.password', 'heslo123');
    }

    public function find(string $query): ?LdapUser
    {
        // An empty query would otherwise invent a user with an empty login.
        if ($query === '' || $query === static::MISSING_LOGIN) {
            return null;
        }

        // Missing identifiers are null and filtered out, so they never match.
        $match = $this->users()->first(fn (LdapUser $user): bool => in_array($query, array_filter([
            $user->email,
            $user->login,
            $user->dn,
            $user->uic === null ? null : (string) $user->uic,
        ]), strict: true));

        if ($match) {
            return $match;
        }

        // No pool configured (or no hit in it): invent a user so that any login
        // works, which is what the local-development flow relies on.
        return static::$resolver === null ? $this->invent($query) : null;
    }

    public function search(string $query, int $limit = 10): Collection
    {
        if ($query === static::MISSING_LOGIN) {
            return new Collection;
        }

        $needle = Str::lower($query);

        $matches = $this->users()
            ->filter(fn (LdapUser $user): bool => Str::contains(
                Str::lower($user->name().' '.$user->uic),
                $needle,
            ))
            ->values();

        if ($matches->isEmpty() && static::$resolver === null) {
            $matches = new Collection([$this->invent($query)]);
        }

        return $matches->take($limit)->values();
    }

    public function cursor(?string $filter = null, ?array $attributes = null): Generator
    {
        $users = $this->users();

        if ($users->isEmpty() && static::$resolver === null) {
            $users = new Collection(
                array_map(fn (): LdapUser => LdapUser::fake(), range(1, (int) config('ldap.fake.users', 50)))
            );
        }

        yield from $users->values();
    }

    public function chunk(int $size, Closure $callback, ?string $filter = null, ?array $attributes = null): int
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Chunk size must be at least 1.');
        }

        $page = 0;
        $total = 0;

        foreach ((new Collection(iterator_to_array($this->cursor($filter, $attributes))))->chunk($size) as $chunk) {
            $total += $chunk->count();

            if ($callback($chunk->values(), ++$page) === false) {
                break;
            }
        }

        return $total;
    }

    /**
     * Build a user that agrees with whatever identifier was asked for.
     */
    protected function invent(string $query): LdapUser
    {
        $domain = (string) config('ldap.fake.email_domain', 'czu.cz');

        return LdapUser::fake(match (true) {
            (bool) filter_var($query, FILTER_VALIDATE_EMAIL) => [
                'email' => $query,
                'login' => Str::before($query, '@'),
            ],
            is_numeric($query) => [
                'uic' => (int) $query,
            ],
            default => [
                'login' => $query,
                'email' => "{$query}@{$domain}",
            ],
        });
    }
}
