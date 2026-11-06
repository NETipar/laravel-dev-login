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
 * Resolves guard, user provider and user from the config; never route model binding.
 */
final class UserResolver
{
    public function guardName(): string
    {
        $configured = config('dev-login.guard');

        return is_string($configured) && $configured !== ''
            ? $configured
            : config()->string('auth.defaults.guard');
    }

    public function guard(string $name): SessionGuard
    {
        $guard = app(AuthFactory::class)->guard($name);

        if (! $guard instanceof SessionGuard) {
            throw new InvalidArgumentException(
                "Dev login needs a stateful (session) guard, but the guard [{$name}] is not one. Set dev-login.guard to a session guard.",
            );
        }

        return $guard;
    }

    /**
     * Look the user up by the configured user_key: 'id' = retrieveById(), any other attribute = retrieveByCredentials().
     */
    public function find(SessionGuard $guard, string $value): ?Authenticatable
    {
        $key = config()->string('dev-login.user_key');
        $provider = $guard->getProvider();

        if ($key !== 'id') {
            return $provider->retrieveByCredentials([$key => $value]);
        }

        // an integer key never matches text: answer "unknown" instead of sending it to the database (an error on PostgreSQL)
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
     * The value that goes into the URL for this user, per the configured user_key.
     */
    public function urlValue(Authenticatable $user): string
    {
        $key = config()->string('dev-login.user_key');

        $value = $key === 'id' ? $user->getAuthIdentifier() : data_get($user, $key);

        return is_int($value) || is_string($value) || $value instanceof Stringable
            ? (string) $value
            : throw new InvalidArgumentException("The user has no usable [{$key}] value for the dev login URL.");
    }
}
