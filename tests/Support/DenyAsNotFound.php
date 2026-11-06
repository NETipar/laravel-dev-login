<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Tests\Support;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

final class DenyAsNotFound
{
    public function __invoke(Authenticatable $user, Request $request): Response
    {
        return Response::denyAsNotFound();
    }
}
