<?php

declare(strict_types=1);

use CzuPef\Ldap\Facades\Ldap;
use CzuPef\Ldap\Ldap as LdapDriver;
use CzuPef\Ldap\LdapContract;
use CzuPef\Ldap\LdapException;
use CzuPef\Ldap\LdapFake;
use CzuPef\Ldap\LdapUser;
use Illuminate\Support\Collection;

it('is resolved by the container when no server is configured', function (): void {
    config(['ldap.server' => null]);

    expect(app(LdapContract::class))->toBeInstanceOf(LdapFake::class);
});

it('invents a user for any login', function (): void {
    $fake = new LdapFake;

    expect($fake->find('jnovak')?->email)->toBe('jnovak@czu.cz')
        ->and($fake->find('jan.novak@pef.czu.cz')?->email)->toBe('jan.novak@pef.czu.cz')
        ->and($fake->find('123456')?->uic)->toBe(123456);
});

it('refuses to fall back to the fake in production', function (): void {
    config(['ldap.server' => null]);
    app()->detectEnvironment(fn (): string => 'production');

    app(LdapContract::class);
})->throws(LdapException::class, 'LDAP server is not configured');

it('still resolves the real driver in production when a server is configured', function (): void {
    config(['ldap.server' => 'ldap.czu.cz']);
    app()->detectEnvironment(fn (): string => 'production');

    expect(app(LdapContract::class))->toBeInstanceOf(LdapDriver::class);
});

it('never resolves the fail login', function (): void {
    expect((new LdapFake)->find(LdapFake::MISSING_LOGIN))->toBeNull();
});

it('checks the configured password', function (): void {
    $fake = new LdapFake;

    expect($fake->checkCredentials('cn=x', 'heslo123'))->toBeTrue()
        ->and($fake->checkCredentials('cn=x', 'wrong'))->toBeFalse()
        ->and($fake->attempt('jnovak', 'wrong'))->toBeNull()
        ->and($fake->attempt('jnovak', 'heslo123')?->login)->toBe('jnovak');
});

it('rejects an empty password rather than binding anonymously', function (): void {
    (new LdapFake)->checkCredentials('cn=x', '');
})->throws(InvalidArgumentException::class);

it('rejects an empty dn rather than binding anonymously', function (): void {
    (new LdapFake)->checkCredentials('   ', 'heslo123');
})->throws(InvalidArgumentException::class);

it('refuses to authenticate a user without a dn', function (): void {
    $fake = Ldap::fake([LdapUser::fake(['login' => 'jnovak', 'dn' => ''])]);

    expect($fake->attempt('jnovak', 'heslo123'))->toBeNull();
});

it('answers lookups from a seeded pool and hides everyone else', function (): void {
    $fake = Ldap::fake([
        LdapUser::fake(['first_name' => 'Jan', 'last_name' => 'Novak', 'uic' => 111111]),
        LdapUser::fake(['first_name' => 'Eva', 'last_name' => 'Cerna', 'uic' => 222222]),
    ]);

    expect($fake->find('jnovak@czu.cz')?->uic)->toBe(111111)
        ->and($fake->find('222222')?->uic)->toBe(222222)
        // With a pool configured the fake stops inventing users.
        ->and($fake->find('nobody'))->toBeNull()
        ->and($fake->search('cern')->map->name()->all())->toBe(['Eva Cerna']);
});

it('replaces the bound instance when faked through the facade', function (): void {
    Ldap::fake([LdapUser::fake(['first_name' => 'Jan', 'last_name' => 'Novak'])]);

    expect(Ldap::find('jnovak@czu.cz')?->name())->toBe('Jan Novak')
        ->and(app(LdapContract::class))->toBeInstanceOf(LdapFake::class);
});

it('chunks the pool', function (): void {
    $fake = Ldap::fake(
        Collection::times(25, fn (int $i): LdapUser => LdapUser::fake(['uic' => 100000 + $i]))->all()
    );

    $pages = [];

    $total = $fake->chunk(10, function (Collection $users, int $page) use (&$pages): void {
        $pages[$page] = $users->count();
    });

    expect($total)->toBe(25)
        ->and($pages)->toBe([1 => 10, 2 => 10, 3 => 5]);
});

it('stops chunking when the callback returns false', function (): void {
    $fake = Ldap::fake(Collection::times(25, fn (): LdapUser => LdapUser::fake())->all());

    expect($fake->chunk(10, fn (): bool => false))->toBe(10);
});

it('never matches an empty query', function (): void {
    expect((new LdapFake)->find(''))->toBeNull();

    $fake = Ldap::fake([
        LdapUser::fake(['email' => null, 'uic' => null]),
        LdapUser::fake(['login' => null, 'uic' => null]),
    ]);

    expect($fake->find(''))->toBeNull();
});
