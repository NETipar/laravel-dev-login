<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Auth\AuthorizeActiveUser;
use Workbench\App\Models\User;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'auth.providers.users.model' => User::class,
            'dev-login.enabled' => true,
            'dev-login.redirect' => '/',
            'dev-login.authorize' => AuthorizeActiveUser::class,
        ]);
    }
}
