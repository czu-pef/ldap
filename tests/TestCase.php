<?php

declare(strict_types=1);

namespace CzuPef\Ldap\Tests;

use CzuPef\Ldap\Auth\LdapUserProvider;
use CzuPef\Ldap\LdapFake;
use CzuPef\Ldap\LdapServiceProvider;
use CzuPef\Ldap\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        LdapFake::clearResolvedUsers();
        LdapUserProvider::syncUsing(null);

        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LdapServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users', [
            'driver' => 'ldap',
            'model' => User::class,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('uic')->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }
}
