# czu-pef/ldap

CZU LDAP integration for Laravel: password verification, user lookup, and a
streaming export for bulk synchronisation.

Requires PHP 8.3+, Laravel 13 and `ext-ldap`.

---

## Install

The package lives in a private repository, so each project needs a
`repositories` entry before requiring it:

```jsonc
// composer.json
"repositories": [
    {
        "type": "vcs",
        "url": "git@github.com:czu-pef/ldap.git"
    }
]
```

```bash
composer require czu-pef/ldap
```

Composer needs credentials that can read `czu-pef/ldap` — an SSH key on the
`czu-pef` account for local work, and a personal access token with `repo` scope
in CI (`composer config --global --auth github-oauth.github.com <token>`).

The service provider is auto-discovered. Publish the config only if you need to
change a filter or the base DN:

```bash
php artisan vendor:publish --tag=ldap-config
```

```dotenv
LDAP_SERVER=
LDAP_USERNAME=
LDAP_PASSWORD=...
```

**Leave `LDAP_SERVER` empty and the container resolves `LdapFake` instead**, so
local checkouts and CI work without reaching the directory.

In production this fallback is refused: with `APP_ENV=production` and no
`LDAP_SERVER`, resolving the driver throws `LdapException` rather than letting
the fake accept any login.

---

## Usage

```php
use CzuPef\Ldap\Facades\Ldap;
use CzuPef\Ldap\LdapUser;
```

### Log a user in

Point your auth provider at the `ldap` driver and `Auth::attempt()` does the
whole thing — bind against the directory, then create or refresh the local row:

```php
// config/auth.php
'providers' => [
    'users' => [
        'driver' => 'ldap',
        'model' => App\Models\User::class,
    ],
],
```

```php
if (! Auth::attempt(['login' => $request->login, 'password' => $request->password])) {
    // Wrong password or no such user — deliberately indistinguishable.
    throw ValidationException::withMessages(['login' => __('auth.failed')]);
}
```

Any credential key other than `password` is treated as the login, so `login`,
`email` and `uic` all work — the directory decides. Behind it, `find()` accepts
anything a person might type: email, `sAMAccountName`, UIC, or chip card number.

Three things worth knowing:

- **A failed login never leaves a row behind.** The local model is resolved but
  not saved until the bind succeeds.
- **An expired account cannot log in**, even if the directory would still accept
  its password.
- **Sessions do not touch LDAP.** Once logged in, the local row is the source of
  identity, so `retrieveById()` and remember tokens go straight to your database.

Which local column matches which directory field is configured in
`config('ldap.auth')`:

```php
'auth' => [
    'key' => 'uic',          // local column
    'ldap_key' => 'uic',     // LdapUser property
    'sync' => [
        'email' => 'email',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
    ],
],
```

When a field map is not enough, take over the projection entirely. Register this
from a service provider, not `config/ldap.php` — a closure in config breaks
`php artisan config:cache`:

```php
use CzuPef\Ldap\Auth\LdapUserProvider;

LdapUserProvider::syncUsing(function (LdapUser $entry, User $user): User {
    $user->fill([
        'email' => $entry->email,
        'first_name' => $entry->first_name,
        'last_name' => $entry->last_name,
    ]);

    $user->faculty()->associate(Faculty::fromDn($entry->dn));

    return $user;
});
```

### Verify a password without the guard

If you are not using Laravel's auth at all:

```php
$entry = Ldap::attempt($request->string('login'), $request->string('password'));
```

Returns the `LdapUser` on success and `null` on failure, with no local model
involved.

### Look someone up

```php
$user = Ldap::find('jnovak@czu.cz');   // ?LdapUser — exact match, null unless exactly one hit
$users = Ldap::search('novak', 20);    // Collection<int, LdapUser> — fuzzy, for pickers
```

### Read attributes the package does not model

Every `LdapUser` keeps its raw entry, so you never have to fork the package for
one extra field:

```php
$user->attribute('workforceid');
```

Add the attribute to `config('ldap.attributes')` first, or the server will not
return it.

### Stream all ~80k users

`cursor()` is the streaming primitive: it follows the server's paging cookie and
yields one `LdapUser` at a time, so memory stays flat regardless of directory
size. `chunk()` is a thin wrapper for batched writes.

```php
Ldap::chunk(500, function (Collection $users, int $page): void {
    DB::table('ldap_users_cache')->upsert(
        $users->map(fn (LdapUser $user) => [
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        ])->all(),
        uniqueBy: ['email'],
        update: ['first_name', 'last_name'],
    );
});
```

Return `false` from the callback to stop early. Both methods take an optional
filter and attribute list; requesting fewer attributes is faster, and the fields
you leave out come back `null`:

```php
foreach (Ldap::cursor(attributes: ['mail', 'givenname', 'sn']) as $user) {
    // $user->email, ->first_name, ->last_name are populated;
    // ->login, ->uic and ->cn were not requested, so they are null.
}
```

The package deliberately ships **no migration, model, command or job** for this.
Every project caches a different shape, so persistence stays yours — wire the
snippet above into your own artisan command or scheduled job.

> Prefer `upsert()` over `truncate()` + `insertOrIgnore()`: truncating leaves the
> table empty for the duration of the run and drops the whole cache if the job
> fails halfway. If you do need removals, track seen keys and delete the
> leftovers after the loop.

---

## Testing

Without `LDAP_SERVER` the fake is already active and invents a user for any
login, with password `heslo123` (`config('ldap.fake.password')`). The login
`fail` never resolves.

```php
$this->post('/login', ['login' => 'jnovak', 'password' => 'heslo123'])
    ->assertRedirect('/dashboard');
```

When lookups must agree with rows already in your database, seed the pool. With
a pool configured the fake stops inventing users and only answers from it:

```php
use CzuPef\Ldap\Facades\Ldap;
use CzuPef\Ldap\LdapUser;

Ldap::fake(fn () => User::all()->map(fn (User $user) => LdapUser::fake([
    'email' => $user->email,
    'uic' => $user->uic,
    'first_name' => $user->first_name,
    'last_name' => $user->last_name,
])));
```

The package never references your models; the pool is the only way data gets in.

---

## Upgrading from 1.x

2.0 makes "the directory did not return this attribute" explicit.

- `LdapUser::$login`, `$email`, `$first_name`, `$last_name` and `$cn` are now
  `?string`. `null` means the entry lacks the attribute (or it is empty); 1.x
  returned `''`, which was indistinguishable from a real value. `$uic` already
  behaved this way. `$dn` is unchanged and still a `string`.
- Handle `null` before writing these to non-nullable or unique columns, and
  before using them in a query — `where('email', $user->email)` with a `null`
  now becomes `IS NULL` rather than `= ''`.
- The default `auth.sync` map skips `null` properties, so a directory entry
  missing an attribute no longer overwrites the local column. A custom
  `syncUsing()` callback receives the entry as-is and must handle `null` itself.
- `name()` still returns a `string`, joining whichever of the first and last
  name are present, or `''` when neither is.

---

## Migrating an existing project

1. Delete `app/Support/Ldap` (or `app/Utilities/Ldap`) and drop the manual
   `LdapServiceProvider` registration from `bootstrap/providers.php`.
2. Delete `config/ldap.php` unless you customised it; the package merges its own.
3. Replace imports:

   | Old | New |
   |---|---|
   | `App\Support\Ldap\LdapContract` | `CzuPef\Ldap\LdapContract` |
   | `App\Support\Ldap\LdapUser` | `CzuPef\Ldap\LdapUser` |
   | `App\Support\Ldap\LdapException` | `CzuPef\Ldap\LdapException` |
   | `App\Utilities\Ldap\…` | `CzuPef\Ldap\…` |

4. Rename the call sites:

   | Old | New | Note |
   |---|---|---|
   | `getUserWithCredenials()` | `attempt()` | typo fixed |
   | `search()` returning `?LdapUser` | `find()` | see below |
   | `search()` returning `Collection` | `search()` | unchanged |
   | `getLdapConnection()` | `connection()` | rarely called directly |
   | `Ldap::ldapTimestampToCarbon()` | `LdapUser::parseAccountExpires()` | now returns `?CarbonImmutable` |
   | `cacheLdapUsersToLocalDatabase()` | `chunk()` + your own writes | see above |

   The controller block that called `getUserWithCredenials()` and then
   `updateOrCreate()` + `Auth::login()` can usually be deleted outright in
   favour of the `ldap` auth provider.

   The `search()` rename is the one to watch. Where it used to return a single
   `?LdapUser` it now returns a `Collection`, so those call sites must become
   `find()`. The return-type change makes stale ones fail loudly rather than
   silently, but grep for it anyway.

5. `$user->expires` is now `?CarbonInterface` and is **null when the account
   never expires**. Previously AD's sentinel values produced a nonsense date, so
   any `$user->expires->isPast()` needs `$user->expired()` or a null check.

---

## Development

```bash
composer install
composer test      # pest
composer lint      # pint
composer analyse   # phpstan / larastan level 6
```

Contributing needs **PHP 8.4** even though the package itself runs on 8.3:
Pest 5 requires PHP `^8.4`, PHPUnit 13 and Symfony 8. Consumers are unaffected —
`composer install --no-dev` has no such floor.

The LDAP-specific integration paths (`cursor`, `find`, `search` against a real
directory) are not covered by the suite — they need a live server. What is
covered is everything that historically broke: timestamp conversion, filter
escaping and templating, URI building, configuration guards, and the whole fake.
