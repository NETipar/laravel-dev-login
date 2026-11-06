<?php

declare(strict_types=1);

namespace NETipar\DevLogin;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use NETipar\DevLogin\Console\DevLoginUrlCommand;
use NETipar\DevLogin\Http\Controllers\DevLoginController;
use NETipar\DevLogin\Http\Middleware\AddResponseHeaders;
use NETipar\DevLogin\Support\LoginProfile;
use NETipar\DevLogin\Support\LoginProfiles;

final class DevLoginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dev-login.php', 'dev-login');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/dev-login.php' => config_path('dev-login.php'),
            ], 'dev-login-config');

            $this->commands([DevLoginUrlCommand::class]);
        }

        AboutCommand::add('Dev Login', fn (): array => $this->aboutData());

        if (DevLogin::gateOpen() && ! $this->app->routesAreCached()) {
            $this->registerRoutes(new LoginProfiles);
        }
    }

    /**
     * One route per open profile. The route carries the profile name for the controller.
     */
    private function registerRoutes(LoginProfiles $profiles): void
    {
        foreach ($profiles->open() as $profile) {
            $attributes = ['middleware' => [AddResponseHeaders::class, ...$profile->middleware]];

            if ($profile->domain !== null) {
                $attributes['domain'] = $profile->domain;
            }

            Route::group($attributes, function () use ($profile): void {
                Route::get($profile->path, [
                    'uses' => DevLoginController::class,
                    'as' => $profile->routeName,
                    'dev_login' => $profile->name,
                ]);
            });
        }
    }

    /**
     * @return array<string, string>
     */
    private function aboutData(): array
    {
        $profiles = new LoginProfiles;
        $default = $profiles->default();
        $ips = $profiles->globalAllowedIps();

        $rows = [
            'Enabled' => config()->boolean('dev-login.enabled', false) ? 'yes' : 'no',
            'Blocked (production)' => DevLogin::blockedInProduction() ? 'yes' : 'no',
            'Route' => DevLogin::gateOpen() ? ($default->domain ?? '').'/'.ltrim($default->path, '/') : 'not registered',
            'Guard' => $this->guardLabel($default),
            'Allowed IPs' => $ips === [] ? 'any' : implode(', ', $ips),
        ];

        foreach ($profiles->all() as $name => $profile) {
            if (! $profile->isDefault()) {
                $rows['Login ('.$name.')'] = implode(' | ', [
                    $profile->state(),
                    'guard '.$this->guardLabel($profile),
                    $profile->domain ?? 'any host',
                    '/'.ltrim($profile->path, '/'),
                ]);
            }
        }

        return $rows;
    }

    private function guardLabel(LoginProfile $profile): string
    {
        return $profile->guard ?? 'default ('.config()->string('auth.defaults.guard').')';
    }
}
