<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use NETipar\DevLogin\Events\DevLoginRefused;
use NETipar\DevLogin\Tests\Support\AllowAll;
use NETipar\DevLogin\Tests\Support\DenyAll;
use NETipar\DevLogin\Tests\Support\DenyAsNotFound;
use NETipar\DevLogin\Tests\Support\DenyWithMessage;
use NETipar\DevLogin\Tests\Support\DenyWithStatus;
use NETipar\DevLogin\Tests\Support\NotInvokable;
use NETipar\DevLogin\Tests\Support\ReturnsInt;
use NETipar\DevLogin\Tests\Support\ReturnsNull;
use NETipar\DevLogin\Tests\Support\ReturnsString;
use Workbench\App\Auth\AuthorizeActiveUser;
use Workbench\Database\Factories\UserFactory;

it('refuses with 403 when the hook returns false, keeps the visitor a guest and dispatches DevLoginRefused', function (): void {
    config(['dev-login.authorize' => DenyAll::class]);
    $user = UserFactory::new()->create();
    $refused = [];

    Event::listen(DevLoginRefused::class, function (DevLoginRefused $event) use (&$refused): void {
        $refused[] = $event;
    });

    $this->get('/dev/login/'.$user->id)->assertForbidden();

    $this->assertGuest();
    expect($refused)->toHaveCount(1)
        ->and($refused[0]->message)->toBeNull()
        ->and($refused[0]->guard)->toBe('web')
        ->and($refused[0]->user->is($user))->toBeTrue()
        ->and(Context::has('dev_login'))->toBeFalse();
});

it('shows the Response::deny() message on the 403 and passes it to the event', function (): void {
    config(['dev-login.authorize' => DenyWithMessage::class]);
    $user = UserFactory::new()->create();
    $message = null;

    Event::listen(DevLoginRefused::class, function (DevLoginRefused $event) use (&$message): void {
        $message = $event->message;
    });

    $this->get('/dev/login/'.$user->id)->assertForbidden()->assertSee('This account is deactivated.');

    $this->assertGuest();
    expect($message)->toBe('This account is deactivated.');
});

it('lets a hook that allows the user through, and refuses the deactivated one', function (): void {
    config(['dev-login.authorize' => AuthorizeActiveUser::class]);

    $this->get('/dev/login/'.UserFactory::new()->inactive()->create()->id)->assertForbidden();
    $this->assertGuest();

    $active = UserFactory::new()->create();
    $this->get('/dev/login/'.$active->id)->assertRedirect();
    $this->assertAuthenticatedAs($active);
});

it('lets a hook returning true through', function (): void {
    config(['dev-login.authorize' => AllowAll::class]);
    $user = UserFactory::new()->create();

    $this->get('/dev/login/'.$user->id)->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('fails closed when the hook returns something that is not true', function (): void {
    config(['dev-login.authorize' => ReturnsNull::class]);

    $this->get('/dev/login/'.UserFactory::new()->create()->id)->assertForbidden();
    $this->assertGuest();
});

it('rejects a hook class that is not invokable', function (): void {
    config(['dev-login.authorize' => NotInvokable::class]);
    $user = UserFactory::new()->create();

    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/dev/login/'.$user->id))->toThrow(UnexpectedValueException::class, 'must be invokable');
    $this->assertGuest();
});

it('fails closed on truthy values that are not true', function (string $hook): void {
    config(['dev-login.authorize' => $hook]);

    $this->get('/dev/login/'.UserFactory::new()->create()->id)->assertForbidden();
    $this->assertGuest();
})->with([
    'int 1' => [ReturnsInt::class],
    'string yes' => [ReturnsString::class],
]);

it('answers with the status of the hook response and still dispatches DevLoginRefused', function (string $hook, int $status, ?string $message): void {
    config(['dev-login.authorize' => $hook]);
    $user = UserFactory::new()->create();
    $refused = [];

    Event::listen(DevLoginRefused::class, function (DevLoginRefused $event) use (&$refused): void {
        $refused[] = $event;
    });

    $this->get('/dev/login/'.$user->id)->assertStatus($status);

    $this->assertGuest();
    expect($refused)->toHaveCount(1)
        ->and($refused[0]->message)->toBe($message);
})->with([
    'denyAsNotFound' => [DenyAsNotFound::class, 404, null],
    'denyWithStatus' => [DenyWithStatus::class, 409, 'Frozen account.'],
    'deny' => [DenyAll::class, 403, null],
]);
