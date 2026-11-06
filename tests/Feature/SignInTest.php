<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use NETipar\DevLogin\Events\DevLoginSucceeded;
use Workbench\Database\Factories\UserFactory;

it('signs the user in, redirects, and marks the request through Context before the Login event', function (): void {
    $user = UserFactory::new()->create();
    $marker = null;
    $succeeded = [];

    Event::listen(Login::class, function () use (&$marker): void {
        $marker = Context::get('dev_login');
    });
    Event::listen(DevLoginSucceeded::class, function (DevLoginSucceeded $event) use (&$succeeded): void {
        $succeeded[] = $event;
    });

    $this->get('/dev/login/'.$user->id)->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect($marker)->toBeTrue()
        ->and($succeeded)->toHaveCount(1)
        ->and($succeeded[0]->guard)->toBe('web')
        ->and($succeeded[0]->user->is($user))->toBeTrue();
});

it('switches users: the previous one is logged out and its session data does not carry over', function (): void {
    $old = UserFactory::new()->create();
    $new = UserFactory::new()->create();
    $loggedOut = [];

    Event::listen(Logout::class, function (Logout $event) use (&$loggedOut): void {
        $loggedOut[] = $event->user;
    });

    $this->actingAs($old)
        ->withSession(['auth.password_confirmed_at' => time(), 'leftover' => 'x'])
        ->get('/dev/login/'.$new->id)
        ->assertRedirect();

    $this->assertAuthenticatedAs($new);
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse()
        ->and(session()->has('leftover'))->toBeFalse()
        ->and($loggedOut)->toHaveCount(1)
        ->and($loggedOut[0]?->getAuthIdentifier())->toBe($old->id);
});

it('marks only the dev login, not a Login the previous remember-me cookie fires on the way', function (): void {
    // no throttle middleware: it would resolve the remembered user before the controller runs
    $this->reboot(['dev-login.enabled' => true, 'dev-login.middleware' => ['web']]);

    $old = UserFactory::new()->create(['remember_token' => Str::random(60)]);
    $new = UserFactory::new()->create();

    // let the guard build a genuine recaller cookie, then forget that sign-in
    $guard = app(AuthFactory::class)->guard('web');
    $guard->login($old, true);
    $recaller = $guard->getCookieJar()->getQueuedCookies()[0];
    Auth::forgetGuards();
    session()->flush();

    $markers = [];
    Event::listen(Login::class, function (Login $event) use (&$markers): void {
        $markers[$event->user->getAuthIdentifier()] = Context::get('dev_login');
    });

    $this->withCookie($recaller->getName(), $recaller->getValue())->get('/dev/login/'.$new->id)->assertRedirect();

    $this->assertAuthenticatedAs($new);
    expect($markers)->toHaveKey($old->id)
        ->and($markers[$old->id])->toBeNull()
        ->and($markers[$new->id])->toBeTrue();
});
