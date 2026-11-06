<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Support;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use InvalidArgumentException;
use Stringable;

/**
 * Finds the user through the guard's own user provider.
 *
 * @internal
 */
final class UserResolver
{
    public function guardName(LoginProfile $profile): string
    {
        return $profile->guard ?? config()->string('auth.defaults.guard');
    }

    public function guard(string $name): SessionGuard
    {
        $guard = app(AuthFactory::class)->guard($name);

        if (! $guard instanceof SessionGuard) {
            throw new InvalidArgumentException(
                "Dev login needs a stateful (session) guard, but the guard [{$name}] is not one. Set the guard of the dev login profile to a session guard.",
            );
        }

        return $guard;
    }

    public function find(LoginProfile $profile, SessionGuard $guard, string $value): ?Authenticatable
    {
        $key = $profile->userKey;
        $provider = $guard->getProvider();

        if ($key !== 'id') {
            return $provider->retrieveByCredentials([$key => $value]);
        }

        // a non-numeric value can't match an int key; PostgreSQL would throw instead of finding nothing
        if ($provider instanceof EloquentUserProvider && $provider->createModel()->getKeyType() === 'int' && ! ctype_digit($value)) {
            return null;
        }

        return $provider->retrieveById($value);
    }

    public function findByEmail(SessionGuard $guard, string $email): ?Authenticatable
    {
        return $guard->getProvider()->retrieveByCredentials(['email' => $email]);
    }

    /**
     * The value that goes into the URL for this user, per the profile's user_key.
     */
    public function urlValue(LoginProfile $profile, Authenticatable $user): string
    {
        $key = $profile->userKey;

        $value = $key === 'id' ? $user->getAuthIdentifier() : data_get($user, $key);

        return is_int($value) || is_string($value) || $value instanceof Stringable
            ? (string) $value
            : throw new InvalidArgumentException("The user has no usable [{$key}] value for the dev login URL.");
    }
}
