<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

Route::get('/', function (): string {
    $links = User::query()->orderBy('id')->get()->map(
        fn (User $user): string => sprintf(
            '<li><a href="%s">%s</a> (%s)%s</li>',
            e(route('dev.login', ['user' => $user->id])),
            e($user->name),
            e($user->email),
            $user->active ? '' : ' - deactivated, the authorize hook refuses it',
        ),
    )->implode('');

    $current = auth()->user();

    return '<h1>Dev Login workbench</h1>'
        .'<p>Signed in as: <strong>'.e($current instanceof User ? $current->name : 'nobody').'</strong></p>'
        .'<ul>'.$links.'</ul>';
});
