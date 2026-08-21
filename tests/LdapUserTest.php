<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use CzuPef\Ldap\LdapUser;

it('maps an ldap entry', function (): void {
    $user = LdapUser::fromLdapResult([
        'dn' => 'CN=Jan Novak,OU=_Production,DC=czu,DC=cz',
        'cn' => ['Jan Novak', 'count' => 1],
        'givenname' => ['Jan', 'count' => 1],
        'sn' => ['Novak', 'count' => 1],
        'mail' => ['jnovak@czu.cz', 'count' => 1],
        'samaccountname' => ['jnovak', 'count' => 1],
        'czuuic' => ['123456', 'count' => 1],
        'workforceid' => ['W-1', 'count' => 1],
        'accountexpires' => ['133801632000000000', 'count' => 1],
    ]);

    expect($user->login)->toBe('jnovak')
        ->and($user->email)->toBe('jnovak@czu.cz')
        ->and($user->name())->toBe('Jan Novak')
        ->and($user->uic)->toBe(123456)
        ->and($user->attribute('workforceid'))->toBe('W-1')
        ->and($user->expires?->toIso8601String())->toBe('2025-01-01T00:00:00+00:00');
});

it('tolerates a narrow attribute set', function (): void {
    $user = LdapUser::fromLdapResult([
        'dn' => 'CN=Jan Novak,OU=_Production,DC=czu,DC=cz',
        'mail' => ['jnovak@czu.cz', 'count' => 1],
        'givenname' => ['Jan', 'count' => 1],
        'sn' => ['Novak', 'count' => 1],
    ]);

    expect($user->email)->toBe('jnovak@czu.cz')
        ->and($user->login)->toBeNull()
        ->and($user->cn)->toBeNull()
        ->and($user->uic)->toBeNull()
        ->and($user->expires)->toBeNull()
        ->and($user->expired())->toBeFalse();
});

it('maps an attribute that is present but empty to null', function (): void {
    $user = LdapUser::fromLdapResult([
        'dn' => 'CN=Jan Novak,OU=_Production,DC=czu,DC=cz',
        'mail' => ['', 'count' => 1],
    ]);

    expect($user->email)->toBeNull();
});

it('builds a name from whichever parts are present', function (?string $first, ?string $last, string $name): void {
    $user = new LdapUser(dn: 'CN=x', login: null, email: null, first_name: $first, last_name: $last);

    expect($user->name())->toBe($name);
})->with([
    'first only' => ['Jan', null, 'Jan'],
    'last only' => [null, 'Novak', 'Novak'],
    'neither' => [null, null, ''],
]);

/*
 * The ((1970-1601)*365.242190)*86400 approximation these implementations used
 * drifted ~8h50m from the exact 11644473600s epoch offset.
 */
it('converts accountExpires exactly', function (): void {
    expect(LdapUser::parseAccountExpires('133801632000000000')?->toIso8601String())
        ->toBe('2025-01-01T00:00:00+00:00');
});

it('treats sentinel values as never expiring', function (int|string|null $value): void {
    expect(LdapUser::parseAccountExpires($value))->toBeNull();
})->with([
    'zero' => ['0'],
    'int max' => ['9223372036854775807'],
    'missing' => [null],
    'garbage' => ['not-a-number'],
]);

it('reports expiry', function (): void {
    expect(LdapUser::fake(['expires' => CarbonImmutable::now()->subDay()])->expired())->toBeTrue()
        ->and(LdapUser::fake(['expires' => CarbonImmutable::now()->addDay()])->expired())->toBeFalse()
        ->and(LdapUser::fake(['expires' => null])->expired())->toBeFalse();
});

it('builds a consistent fake', function (): void {
    $user = LdapUser::fake(['first_name' => 'Žofie', 'last_name' => 'Dvořák']);

    expect($user->login)->toBe('zdvorak')
        ->and($user->email)->toBe('zdvorak@czu.cz');
});

it('keeps an explicit null in a fake', function (): void {
    expect(LdapUser::fake(['email' => null])->email)->toBeNull();
});

it('still derives the other fields when a fake has a null login', function (): void {
    $user = LdapUser::fake(['login' => null, 'first_name' => 'Jan', 'last_name' => 'Novak']);

    expect($user->login)->toBeNull()
        ->and($user->dn)->toBe('CN=Jan Novak,'.config('ldap.base_dn'))
        ->and($user->email)->toBe('jnovak@czu.cz')
        ->and($user->cn)->toBe('Jan Novak');
});

it('still derives the other fields when a fake has no name', function (): void {
    $user = LdapUser::fake(['first_name' => null, 'last_name' => null]);

    expect($user->first_name)->toBeNull()
        ->and($user->last_name)->toBeNull()
        ->and($user->login)->not->toBeEmpty()
        ->and($user->dn)->toStartWith("CN={$user->login},")
        ->and($user->email)->toBe("{$user->login}@czu.cz")
        ->and($user->cn)->toBe($user->login);
});
