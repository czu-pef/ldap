<?php

declare(strict_types=1);

use CzuPef\Ldap\Auth\LdapUserProvider;
use CzuPef\Ldap\Facades\Ldap;
use CzuPef\Ldap\LdapUser;
use CzuPef\Ldap\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * Seed the fake directory with one entry and return it.
 *
 * @param  array<string, mixed>  $overrides
 */
function seedDirectory(array $overrides = []): LdapUser
{
    $entry = LdapUser::fake(array_merge([
        'uic' => 123456,
        'login' => 'jnovak',
        'email' => 'jnovak@czu.cz',
        'first_name' => 'Jan',
        'last_name' => 'Novak',
    ], $overrides));

    Ldap::fake([$entry]);

    return $entry;
}

it('creates the local user on a successful attempt', function (): void {
    seedDirectory();

    expect(Auth::attempt(['login' => 'jnovak', 'password' => 'heslo123']))->toBeTrue();

    $user = Auth::user();

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->exists)->toBeTrue()
        ->and((int) $user->uic)->toBe(123456)
        ->and($user->email)->toBe('jnovak@czu.cz')
        ->and($user->first_name)->toBe('Jan');

    $this->assertDatabaseCount('users', 1);
});

it('accepts any identifier the directory matches', function (string $key, string|int $value): void {
    seedDirectory();

    expect(Auth::attempt([$key => $value, 'password' => 'heslo123']))->toBeTrue();
})->with([
    'login' => ['login', 'jnovak'],
    'email' => ['email', 'jnovak@czu.cz'],
    'uic' => ['uic', 123456],
]);

it('leaves no local row behind when the password is wrong', function (): void {
    seedDirectory();

    expect(Auth::attempt(['login' => 'jnovak', 'password' => 'wrong']))->toBeFalse();

    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});

it('leaves no local row behind for an unknown login', function (): void {
    seedDirectory();

    expect(Auth::attempt(['login' => 'nobody', 'password' => 'heslo123']))->toBeFalse();

    $this->assertDatabaseCount('users', 0);
});

it('refuses an empty password', function (): void {
    seedDirectory();

    expect(Auth::attempt(['login' => 'jnovak', 'password' => '']))->toBeFalse();

    $this->assertDatabaseCount('users', 0);
});

it('refuses an expired account', function (): void {
    seedDirectory(['expires' => now()->subDay()]);

    expect(Auth::attempt(['login' => 'jnovak', 'password' => 'heslo123']))->toBeFalse();

    $this->assertDatabaseCount('users', 0);
});

it('refreshes an existing local user rather than duplicating it', function (): void {
    User::create([
        'uic' => 123456,
        'email' => 'old.address@czu.cz',
        'first_name' => 'Jan',
        'last_name' => 'Stary',
    ]);

    seedDirectory(['last_name' => 'Novy']);

    expect(Auth::attempt(['login' => 'jnovak', 'password' => 'heslo123']))->toBeTrue()
        ->and(Auth::user()?->last_name)->toBe('Novy')
        ->and(Auth::user()?->email)->toBe('jnovak@czu.cz');

    $this->assertDatabaseCount('users', 1);
});

it('keeps the session alive through the local row alone', function (): void {
    seedDirectory();
    Auth::attempt(['login' => 'jnovak', 'password' => 'heslo123']);

    $id = Auth::id();
    Auth::logout();
    $this->assertGuest();

    // retrieveById() must not need the directory at all.
    Auth::loginUsingId($id);

    expect(Auth::id())->toBe($id);
});

it('lets syncUsing take over the projection', function (): void {
    LdapUserProvider::syncUsing(function (LdapUser $entry, Model $user): Model {
        $user->forceFill([
            'email' => $entry->email,
            'first_name' => strtoupper($entry->first_name),
            'last_name' => $entry->last_name,
        ]);

        return $user;
    });

    seedDirectory();

    expect(Auth::attempt(['login' => 'jnovak', 'password' => 'heslo123']))->toBeTrue()
        ->and(Auth::user()?->first_name)->toBe('JAN');
});

it('does not overwrite a local column the directory entry lacks', function (): void {
    User::create([
        'uic' => 123456,
        'email' => 'jnovak@czu.cz',
        'first_name' => 'Jan',
        'last_name' => 'Novak',
    ]);

    seedDirectory(['email' => null, 'last_name' => 'Novy']);

    expect(Auth::attempt(['login' => 'jnovak', 'password' => 'heslo123']))->toBeTrue()
        ->and(Auth::user()?->email)->toBe('jnovak@czu.cz')
        ->and(Auth::user()?->last_name)->toBe('Novy');

    $this->assertDatabaseCount('users', 1);
});

it('does not match a local row by an empty key', function (?string $key): void {
    config(['ldap.auth.key' => 'email', 'ldap.auth.ldap_key' => 'email']);

    User::create(['uic' => 111111, 'email' => $key, 'first_name' => 'Someone', 'last_name' => 'Else']);

    seedDirectory(['email' => $key]);

    expect(Auth::attempt(['login' => 'jnovak', 'password' => 'heslo123']))->toBeTrue()
        ->and(Auth::user()?->first_name)->toBe('Jan')
        ->and(Auth::user()?->uic)->not->toBe(111111);

    $this->assertDatabaseCount('users', 2);
    expect(User::where('first_name', 'Someone')->value('uic'))->toBe(111111);
})->with([
    'null' => [null],
    'empty string' => [''],
]);
