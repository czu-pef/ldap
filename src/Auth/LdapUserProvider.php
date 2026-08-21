<?php

declare(strict_types=1);

namespace CzuPef\Ldap\Auth;

use Closure;
use CzuPef\Ldap\LdapContract;
use CzuPef\Ldap\LdapUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Authenticates against LDAP and projects the directory entry onto a local
 * Eloquent model, so `Auth::attempt(['login' => ..., 'password' => ...])`
 * works without any controller-side glue.
 *
 * Everything that does not involve a password — retrieving by id, remember
 * tokens — is delegated to the wrapped Eloquent provider, because once a
 * session exists the local row is the source of identity.
 */
class LdapUserProvider implements UserProvider
{
    /**
     * Overrides the configured field map for projecting an LdapUser.
     *
     * @var (Closure(LdapUser, Model): Model)|null
     */
    protected static ?Closure $sync = null;

    /**
     * The entry that retrieveByCredentials() just looked up, and the model it
     * produced. The guard always calls validateCredentials() immediately
     * afterwards with that same model, which is the only way to carry the DN
     * between the two calls.
     */
    protected ?LdapUser $pendingEntry = null;

    protected ?Authenticatable $pendingUser = null;

    /**
     * @param  array<string, mixed>  $config  the `providers.*` entry from config/auth.php
     */
    public function __construct(
        protected LdapContract $ldap,
        protected UserProvider $eloquent,
        protected array $config = [],
    ) {}

    /**
     * Project a directory entry onto the local model yourself.
     *
     * Register this from a service provider rather than config/ldap.php — a
     * closure in config breaks `php artisan config:cache`.
     *
     *     LdapUserProvider::syncUsing(function (LdapUser $entry, User $user) {
     *         $user->fill([...]);
     *         $user->department()->associate(...);
     *
     *         return $user;
     *     });
     *
     * @param  (Closure(LdapUser, Model): Model)|null  $callback
     */
    public static function syncUsing(?Closure $callback): void
    {
        static::$sync = $callback;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $this->pendingEntry = null;
        $this->pendingUser = null;

        if (($identifier = $this->identifierFrom($credentials)) === null) {
            return null;
        }

        if (! $entry = $this->ldap->find($identifier)) {
            return null;
        }

        // Resolve — but deliberately do not save. A failed password must not
        // leave a local row behind for someone who never authenticated.
        $user = $this->project($entry);

        // newModel() rejects a model that is not Authenticatable, but a custom
        // syncUsing() callback could still hand back something else.
        if (! $user instanceof Authenticatable) {
            return null;
        }

        $this->pendingEntry = $entry;
        $this->pendingUser = $user;

        return $user;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        $password = $credentials['password'] ?? null;

        if (! is_string($password) || $password === '') {
            return false;
        }

        // Only trust the entry looked up for this exact model instance.
        if ($this->pendingUser !== $user || ! $this->pendingEntry instanceof LdapUser) {
            return false;
        }

        $entry = $this->pendingEntry;

        if ($entry->dn === '' || $entry->expired()) {
            return false;
        }

        if (! $this->ldap->checkCredentials($entry->dn, $password)) {
            return false;
        }

        // Authenticated: now the local row may be created or refreshed.
        if ($user instanceof Model) {
            $user->save();
        }

        return true;
    }

    /**
     * LDAP owns the password, so there is never a local hash to rehash.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        //
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->eloquent->retrieveById($identifier);
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return $this->eloquent->retrieveByToken($identifier, $token);
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        $this->eloquent->updateRememberToken($user, $token);
    }

    /**
     * Find the existing local row for an entry, or build an unsaved one, and
     * bring its attributes up to date with the directory.
     */
    protected function project(LdapUser $entry): Model
    {
        $model = $this->newModel();

        $key = (string) $this->config('auth.key', 'uic');
        $value = $entry->{$this->config('auth.ldap_key', 'uic')};

        // An empty key would match every local row whose column is also empty,
        // and would be written into a unique column, so it counts as missing.
        if ($value === '') {
            $value = null;
        }

        $user = $value === null
            ? null
            : $model->newQuery()->where($key, $value)->first();

        $user ??= $model->newInstance([$key => $value]);

        if (static::$sync instanceof Closure) {
            return (static::$sync)($entry, $user);
        }

        foreach ((array) $this->config('auth.sync', []) as $column => $property) {
            // A missing attribute means the directory didn't say, not that the
            // value was removed, so it must not wipe what the local row holds.
            if (($attribute = $entry->{$property}) === null) {
                continue;
            }

            $user->setAttribute($column, $attribute);
        }

        return $user;
    }

    protected function newModel(): Model
    {
        $class = $this->config['model'] ?? $this->config('auth.model');

        if (! is_string($class) || ! is_a($class, Model::class, allow_string: true) || ! is_a($class, Authenticatable::class, allow_string: true)) {
            throw new InvalidArgumentException('The ldap auth provider needs a `model` in its config/auth.php entry that extends Model and implements Authenticatable.');
        }

        return new $class;
    }

    /**
     * The login the user typed. Any credential key other than the password
     * works, so `email`, `login` and `uic` are all equivalent.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function identifierFrom(array $credentials): ?string
    {
        if (is_string($field = $this->config('auth.credential_key'))) {
            $value = $credentials[$field] ?? null;

            return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        }

        foreach ($credentials as $key => $value) {
            if (str_contains($key, 'password') || ! is_scalar($value)) {
                continue;
            }

            if ((string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("ldap.{$key}", $default);
    }
}
