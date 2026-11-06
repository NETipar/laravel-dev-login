<?php

declare(strict_types=1);

namespace NETipar\DevLogin;

/**
 * The gate: one place, called by the provider (route registration), the controller (stale route cache)
 * and the artisan command. Deliberately no static registration API: the consuming app installs the
 * package with `require --dev`, so no app code that runs in production may reference this class.
 */
final class DevLogin
{
    /** The Laravel Context key set before the sign-in; consumers read the plain string 'dev_login'. */
    public const string CONTEXT_KEY = 'dev_login';

    /**
     * enabled && ! (block_in_production && production): APP_ENV may only narrow, never open.
     */
    public static function gateOpen(): bool
    {
        if (! config()->boolean('dev-login.enabled', false)) {
            return false;
        }

        return ! (config()->boolean('dev-login.block_in_production', true) && app()->isProduction());
    }

    /**
     * Whether the production block is what keeps an enabled gate closed.
     */
    public static function blockedInProduction(): bool
    {
        return config()->boolean('dev-login.enabled', false)
            && config()->boolean('dev-login.block_in_production', true)
            && app()->isProduction();
    }
}
