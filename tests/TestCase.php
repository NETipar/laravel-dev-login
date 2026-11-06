<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use NETipar\DevLogin\DevLoginServiceProvider;
use NETipar\DevLogin\Tests\Support\UuidAccount;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Models\User;

abstract class TestCase extends Orchestra
{
    /**
     * Config applied before the package boots (the gate is read at boot, so it cannot be set in a test body).
     * Default: the gate is open.
     *
     * @var array<string, mixed>
     */
    public static array $bootConfig = ['dev-login.enabled' => true];

    public static string $bootEnvironment = 'testing';

    /**
     * Boot a fresh application with the given pre-boot config and APP_ENV.
     *
     * @param  array<string, mixed>  $config
     */
    protected function reboot(array $config, string $environment = 'testing'): void
    {
        self::$bootConfig = $config;
        self::$bootEnvironment = $environment;

        $this->refreshApplication();
        $this->defineDatabaseMigrations();
    }

    protected function tearDown(): void
    {
        self::$bootConfig = ['dev-login.enabled' => true];
        self::$bootEnvironment = 'testing';

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DevLoginServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app->detectEnvironment(fn (): string => self::$bootEnvironment);

        $app['config']->set([
            // always the in-memory database, never the file one `composer build` leaves in the Testbench skeleton
            'database.default' => 'testing',
            'cache.default' => 'array',
            'session.driver' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'auth.providers.users.model' => User::class,
            'auth.providers.accounts' => ['driver' => 'eloquent', 'model' => UuidAccount::class],
            'auth.guards.accounts' => ['driver' => 'session', 'provider' => 'accounts'],
            'auth.guards.api' => ['driver' => 'token', 'provider' => 'users'],
            ...self::$bootConfig,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Tables are created directly (no migrate command: it asks for confirmation in production boots).
        if (self::$bootEnvironment === 'production') {
            return;
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('uuid_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function defineRoutes($router): void
    {
        Route::get('/dashboard', fn (): string => 'dashboard')->name('dashboard');
    }
}
