<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Dispatched when the `authorize` hook refuses the user. The message is the one of Response::deny().
 */
final readonly class DevLoginRefused
{
    public function __construct(
        public Authenticatable $user,
        public string $guard,
        public ?string $message,
    ) {}
}
