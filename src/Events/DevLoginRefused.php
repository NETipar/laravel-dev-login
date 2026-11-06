<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Dispatched when the authorize hook refuses the user; $message is the one passed to Response::deny(), if any.
 */
final readonly class DevLoginRefused
{
    public function __construct(
        public Authenticatable $user,
        public string $guard,
        public ?string $message,
    ) {}
}
