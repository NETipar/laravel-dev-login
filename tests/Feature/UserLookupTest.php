<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use NETipar\DevLogin\Tests\Support\UuidAccount;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

it('answers 404 for a user that does not exist', function (): void {
    $this->get('/dev/login/9999')->assertNotFound();

    $this->assertGuest();
});

it('looks the user up by e-mail when user_key is email', function (): void {
    config(['dev-login.user_key' => 'email']);
    $user = UserFactory::new()->create(['email' => 'a@b.test']);

    $this->get('/dev/login/a@b.test')->assertRedirect();
    $this->assertAuthenticatedAs($user);

    $this->get('/dev/login/nobody@b.test')->assertNotFound();
});

it('works with a UUID-keyed model on another session guard', function (): void {
    config(['dev-login.guard' => 'accounts']);
    $account = UuidAccount::query()->create([
        'name' => 'Uuid',
        'email' => 'uuid@demo.test',
        'password' => 'secret',
    ]);

    expect(Str::isUuid($account->getKey()))->toBeTrue();

    $this->get('/dev/login/'.$account->getKey())->assertRedirect();

    $this->assertAuthenticatedAs($account, 'accounts');
    $this->assertGuest('web');
});

it('refuses a guard that is not stateful with a clear exception', function (): void {
    config(['dev-login.guard' => 'api']);
    $user = UserFactory::new()->create();

    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/dev/login/'.$user->id))
        ->toThrow(InvalidArgumentException::class, 'needs a stateful (session) guard');
});

it('uses the configured guard instead of the default one', function (): void {
    config([
        'dev-login.guard' => 'accounts',
        'auth.defaults.guard' => 'web',
    ]);
    $user = UserFactory::new()->create();

    // the "accounts" provider holds UUID accounts only: a numeric user id is unknown there
    $this->get('/dev/login/'.$user->id)->assertNotFound();
});

it('reads the raw {user} value even when the app binds the route parameter', function (): void {
    Route::model('user', User::class);
    $user = UserFactory::new()->create();

    $this->get('/dev/login/'.$user->id)->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('does not send a non-numeric id to the database when the key is an integer', function (): void {
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get('/dev/login/not-a-number')->assertNotFound();

    expect(DB::getQueryLog())->toBe([]);
});
