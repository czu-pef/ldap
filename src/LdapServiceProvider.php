<?php

declare(strict_types=1);

namespace CzuPef\Ldap;

use CzuPef\Ldap\Auth\LdapUserProvider;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class LdapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ldap.php', 'ldap');

        $this->app->singleton(LdapContract::class, static function ($app): LdapContract {
            if (config('ldap.server')) {
                return $app->make(Ldap::class);
            }

            // The fake invents a user for any login and accepts one shared
            // password, so falling back to it in production would turn the
            // login form into an open door. Thrown on resolution rather than
            // in register(), so a misconfigured deploy still boots and runs
            // artisan instead of failing with an unrelated error.
            if ($app->environment('production')) {
                throw new LdapException('LDAP server is not configured: set LDAP_SERVER. The fake LDAP driver is refused in production.');
            }

            return $app->make(LdapFake::class);
        });

        $this->app->alias(LdapContract::class, 'ldap');
    }

    public function boot(): void
    {
        Auth::provider('ldap', fn ($app, array $config): LdapUserProvider => new LdapUserProvider(
            ldap: $app->make(LdapContract::class),
            eloquent: new EloquentUserProvider($app->make(Hasher::class), $config['model']),
            config: $config,
        ));

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ldap.php' => $this->app->configPath('ldap.php'),
            ], 'ldap-config');
        }
    }
}
