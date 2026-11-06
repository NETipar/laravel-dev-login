<?php

declare(strict_types=1);

namespace NETipar\DevLogin;

/**
 * Whether the dev login route may exist. No static API on purpose: apps install the package
 * with --dev, so production code must never reference it.
 */
final class DevLogin
{
    /** The Laravel Context key set before the sign-in; consumers read the plain string 'dev_login'. */
    public const string CONTEXT_KEY = 'dev_login';

    /**
     * APP_ENV can keep the gate closed, never open it.
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
