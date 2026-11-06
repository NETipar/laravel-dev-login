<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

final class DenyAll
{
    public function __invoke(Authenticatable $user, Request $request): bool
    {
        return false;
    }
}
