<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use NETipar\DevLogin\Http\Controllers\DevLoginController;

Route::get(config()->string('dev-login.path'), DevLoginController::class)
    ->name(config()->string('dev-login.route_name'));
