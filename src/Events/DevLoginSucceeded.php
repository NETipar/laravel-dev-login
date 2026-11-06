<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Dispatched right after Laravel's Login event, in the same request. The request is deliberately
 * not part of the event (a queued listener could not serialize it); read request() in a sync listener.
 * $login is the login's name: 'default' or a key of the logins config.
 */
final readonly class DevLoginSucceeded
{
    public function __construct(
        public Authenticatable $user,
        public string $guard,
        public string $login = 'default',
    ) {}
}
