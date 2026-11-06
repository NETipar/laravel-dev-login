<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Dispatched when the authorize hook refuses the user; $message is the one passed to Response::deny(), if any.
 * $login is the login's name: 'default' or a key of the logins config.
 */
final readonly class DevLoginRefused
{
    public function __construct(
        public Authenticatable $user,
        public string $guard,
        public ?string $message,
        public string $login = 'default',
    ) {}
}
