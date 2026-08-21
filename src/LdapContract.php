<?php

declare(strict_types=1);

namespace CzuPef\Ldap;

use Closure;
use Generator;
use Illuminate\Support\Collection;

interface LdapContract
{
    /**
     * Find the single user matching an exact identifier (email, login, UIC or chip card).
     *
     * Returns null when the directory matches zero users or more than one.
     */
    public function find(string $query): ?LdapUser;

    /**
     * Search users by name or UIC.
     *
     * @return Collection<int, LdapUser>
     */
    public function search(string $query, int $limit = 10): Collection;

    /**
     * Find a user by identifier and verify their password in one step.
     *
     * Returns null when the user does not exist or the password is wrong;
     * the caller cannot tell the two apart, which is what you want on a
     * login screen.
     */
    public function attempt(string $login, string $password): ?LdapUser;

    /**
     * Verify a password by binding as the given DN.
     *
     * @throws \InvalidArgumentException when the DN or the password is empty.
     *                                   Either one turns a simple bind into an
     *                                   anonymous bind, which succeeds.
     */
    public function checkCredentials(string $dn, string $password): bool;

    /**
     * Stream every user matching a filter, transparently following paged results.
     *
     * @param  string|null  $filter  defaults to config('ldap.filters.all')
     * @param  list<string>|null  $attributes  defaults to config('ldap.attributes')
     * @return Generator<int, LdapUser>
     */
    public function cursor(?string $filter = null, ?array $attributes = null): Generator;

    /**
     * Stream every user matching a filter in batches.
     *
     * Return false from the callback to stop early.
     *
     * @param  Closure(Collection<int, LdapUser>, int): (bool|null|void)  $callback
     * @param  list<string>|null  $attributes
     * @return int number of users passed to the callback
     */
    public function chunk(int $size, Closure $callback, ?string $filter = null, ?array $attributes = null): int;
}
