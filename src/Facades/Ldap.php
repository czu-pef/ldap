<?php

declare(strict_types=1);

namespace CzuPef\Ldap\Facades;

use Closure;
use CzuPef\Ldap\LdapContract;
use CzuPef\Ldap\LdapFake;
use CzuPef\Ldap\LdapUser;
use Illuminate\Support\Facades\Facade;

/**
 * @method static LdapUser|null find(string $query)
 * @method static \Illuminate\Support\Collection<int, LdapUser> search(string $query, int $limit = 10)
 * @method static LdapUser|null attempt(string $login, string $password)
 * @method static bool checkCredentials(string $dn, string $password)
 * @method static \Generator<int, LdapUser> cursor(string|null $filter = null, list<string>|null $attributes = null)
 * @method static int chunk(int $size, Closure $callback, string|null $filter = null, list<string>|null $attributes = null)
 *
 * @see LdapContract
 */
class Ldap extends Facade
{
    /**
     * Force the fake driver for the rest of the test, optionally seeding its pool.
     *
     * @param  iterable<LdapUser>|(Closure(): iterable<LdapUser>)|null  $users
     */
    public static function fake(iterable|Closure|null $users = null): LdapFake
    {
        LdapFake::resolveUsersUsing(match (true) {
            $users instanceof Closure => $users,
            $users === null => null,
            default => static fn (): iterable => $users,
        });

        $fake = new LdapFake;

        // swap() rebinds LdapContract in the container as well as the facade.
        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return LdapContract::class;
    }
}
