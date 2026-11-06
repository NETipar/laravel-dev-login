<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use NETipar\DevLogin\Http\Controllers\DevLoginController;
use NETipar\DevLogin\Tests\Support\DenyAll;
use Workbench\Database\Factories\UserFactory;

it('answers 404 outside allowed_ips and signs in from a matching CIDR range', function (): void {
    config(['dev-login.allowed_ips' => ['10.0.0.0/8', '203.0.113.7']]);
    $user = UserFactory::new()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.5'])->get('/dev/login/'.$user->id)->assertNotFound();
    $this->assertGuest();

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->get('/dev/login/'.$user->id)->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('pins the refusal order with an existing user: what hides the route comes first', function (): void {
    $user = UserFactory::new()->create();
    $blocked = ['REMOTE_ADDR' => '192.168.1.5'];
    config(['dev-login.allowed_ips' => ['10.0.0.0/8']]);

    // blocked IP beats the authorize hook (404, not 403)
    config(['dev-login.authorize' => DenyAll::class]);
    $this->withServerVariables($blocked)->get('/dev/login/'.$user->id)->assertNotFound();

    // blocked IP beats prefetch (404, not 204)
    $this->withServerVariables($blocked)->withHeaders(['Sec-Purpose' => 'prefetch'])->get('/dev/login/'.$user->id)->assertNotFound();

    // blocked IP beats cross-site (404, not 403)
    $this->withServerVariables($blocked)->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->get('/dev/login/'.$user->id)->assertNotFound();

    // prefetch beats cross-site (204, not 403)
    config(['dev-login.allowed_ips' => []]);
    $this->withHeaders(['Sec-Purpose' => 'prefetch', 'Sec-Fetch-Site' => 'cross-site'])->get('/dev/login/'.$user->id)->assertNoContent();

    $this->assertGuest();
});

it('answers a prefetch with 204 and signs nobody in', function (string $header, string $value): void {
    $user = UserFactory::new()->create();

    $this->withHeaders([$header => $value])->get('/dev/login/'.$user->id)->assertNoContent();

    $this->assertGuest();
})->with([
    'Sec-Purpose' => ['Sec-Purpose', 'prefetch'],
    'Sec-Purpose with prerender' => ['Sec-Purpose', 'prefetch;prerender'],
    'Purpose' => ['Purpose', 'prefetch'],
]);

it('refuses a cross-site navigation with 403 and lets the other Sec-Fetch-Site values through', function (?string $site, bool $signedIn): void {
    $user = UserFactory::new()->create();
    $headers = $site === null ? [] : ['Sec-Fetch-Site' => $site];

    $response = $this->withHeaders($headers)->get('/dev/login/'.$user->id);

    if ($signedIn) {
        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    } else {
        $response->assertForbidden();
        $this->assertGuest();
    }
})->with([
    'cross-site' => ['cross-site', false],
    'none' => ['none', true],
    'same-origin' => ['same-origin', true],
    'same-site' => ['same-site', true],
    'header missing' => [null, true],
]);

it('lets a cross-site navigation in when reject_cross_site is off', function (): void {
    config(['dev-login.reject_cross_site' => false]);
    $user = UserFactory::new()->create();

    $this->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->get('/dev/login/'.$user->id)->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('puts the no-store, noindex and no-referrer headers on every kind of response', function (): void {
    config(['dev-login.authorize' => null, 'dev-login.allowed_ips' => []]);
    $user = UserFactory::new()->create();

    $responses = [
        'redirect' => $this->get('/dev/login/'.$user->id),
        'unknown user' => $this->get('/dev/login/9999'),
        'prefetch' => $this->withHeaders(['Sec-Purpose' => 'prefetch'])->get('/dev/login/'.$user->id),
        'cross-site' => $this->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->get('/dev/login/'.$user->id),
    ];

    config(['dev-login.allowed_ips' => ['10.0.0.0/8']]);
    $responses['blocked ip'] = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.5'])->get('/dev/login/'.$user->id);

    foreach ($responses as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private')
            ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
    }
});

it('answers 429 above the throttle limit, with the headers', function (): void {
    $this->reboot(['dev-login.enabled' => true, 'dev-login.middleware' => ['web', 'throttle:2,1']]);

    $this->get('/dev/login/9999')->assertNotFound();
    $this->get('/dev/login/9999')->assertNotFound();

    $limited = $this->get('/dev/login/9999')->assertStatus(429);

    expect($limited->headers->get('Cache-Control'))->toContain('no-store')
        ->and($limited->headers->get('X-Robots-Tag'))->toBe('noindex');
});

it('answers 404 from the controller when a stale route survives a closed gate', function (): void {
    $this->reboot(['dev-login.enabled' => false]);

    // what a route cache built while the gate was open leaves behind
    Route::get('dev/login/{user}', DevLoginController::class)->middleware('web');

    $user = UserFactory::new()->create();

    $this->get('/dev/login/'.$user->id)->assertNotFound();
    $this->assertGuest();
});

it('answers 404 from the controller for a stale route in a production boot, though the switch is on', function (): void {
    $this->reboot(['dev-login.enabled' => true], 'production');

    expect(Route::has('dev.login'))->toBeFalse();

    Route::get('dev/login/{user}', DevLoginController::class)->middleware('web');

    $this->get('/dev/login/1')->assertNotFound();
    $this->assertGuest();
});
