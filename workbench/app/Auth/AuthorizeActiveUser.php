<?php

declare(strict_types=1);

namespace Workbench\App\Auth;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Workbench\App\Models\User;

/**
 * The shape of an `authorize` hook: a plain invokable, no package interface.
 */
final class AuthorizeActiveUser
{
    public function __invoke(Authenticatable $user, Request $request): Response
    {
        return $user instanceof User && ! $user->active
            ? Response::deny('This account is deactivated.')
            : Response::allow();
    }
}
