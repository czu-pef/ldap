<?php

declare(strict_types=1);

use CzuPef\Ldap\Ldap;
use CzuPef\Ldap\LdapContract;
use CzuPef\Ldap\LdapException;

/*
 * The parts of Ldap that can be exercised without a live directory: URI
 * building, filter templating and escaping, and configuration guards.
 */

/**
 * Reach the protected filter builder, which is where escaping happens.
 */
function buildFilter(string $name, string $query): string
{
    return (new ReflectionMethod(Ldap::class, 'filter'))->invoke(new Ldap, $name, $query);
}

it('resolves the real driver when a server is configured', function (): void {
    config(['ldap.server' => 'ldap.czu.cz']);

    expect(app(LdapContract::class))->toBeInstanceOf(Ldap::class);
});

it('builds a uri from a bare host and port', function (): void {
    config(['ldap.server' => 'ldap.czu.cz', 'ldap.port' => 636]);

    expect((new Ldap)->uri())->toBe('ldap://ldap.czu.cz:636');
});

it('lets a configured scheme win over the port', function (): void {
    config(['ldap.server' => 'ldaps://ldap.czu.cz:636', 'ldap.port' => 389]);

    expect((new Ldap)->uri())->toBe('ldaps://ldap.czu.cz:636');
});

it('escapes the query into the filter', function (): void {
    // A bare "*" would turn an exact lookup into a wildcard match, and a ")("
    // would let the caller append their own filter clauses.
    expect(buildFilter('find', 'a)(cn=*'))
        ->not->toContain('a)(cn=*)')
        ->toContain('a\29\28cn=\2a');
});

it('expands every occurrence of the placeholder', function (): void {
    expect(buildFilter('find', 'jnovak'))
        ->toBe('(|(mail=jnovak)(sAMAccountName=jnovak)(czuuic=jnovak)(czuChipLongCard=jnovak))');
});

it('fails loudly on an unconfigured filter', function (): void {
    config(['ldap.filters.find' => null]);

    buildFilter('find', 'jnovak');
})->throws(LdapException::class, 'LDAP filter [find] is not configured.');

it('fails loudly when connecting without a server', function (): void {
    config(['ldap.server' => null]);

    (new Ldap)->connection();
})->throws(LdapException::class, 'LDAP server is not configured.');
