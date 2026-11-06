<?php

declare(strict_types=1);

namespace NETipar\DevLogin;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use NETipar\DevLogin\Console\DevLoginUrlCommand;
use NETipar\DevLogin\Http\Middleware\AddResponseHeaders;

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

        if (DevLogin::gateOpen()) {
            $this->registerRoute();
        }
    }

    private function registerRoute(): void
    {
        $middleware = array_values(array_filter(
            config()->array('dev-login.middleware', []),
            is_string(...),
        ));

        $attributes = ['middleware' => [AddResponseHeaders::class, ...$middleware]];

        $domain = config('dev-login.domain');

        if (is_string($domain) && $domain !== '') {
            $attributes['domain'] = $domain;
        }

        Route::group($attributes, function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/dev-login.php');
        });
    }

    /**
     * @return array<string, string>
     */
    private function aboutData(): array
    {
        $guard = config('dev-login.guard');
        $ips = array_values(array_filter(config()->array('dev-login.allowed_ips', []), is_string(...)));

        return [
            'Enabled' => config()->boolean('dev-login.enabled', false) ? 'yes' : 'no',
            'Blocked (production)' => DevLogin::blockedInProduction() ? 'yes' : 'no',
            'Route' => DevLogin::gateOpen() ? '/'.ltrim(config()->string('dev-login.path'), '/') : 'not registered',
            'Guard' => is_string($guard) && $guard !== '' ? $guard : 'default ('.config()->string('auth.defaults.guard').')',
            'Allowed IPs' => $ips === [] ? 'any' : implode(', ', $ips),
        ];
    }
}
