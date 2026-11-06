<?php

declare(strict_types=1);

use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use NETipar\DevLogin\Events\DevLoginRefused;
use NETipar\DevLogin\Events\DevLoginSucceeded;
use NETipar\DevLogin\Support\LoginProfiles;
use NETipar\DevLogin\Tests\Support\DenyAll;
use NETipar\DevLogin\Tests\Support\SwitchToTenantDatabase;
use Workbench\Database\Factories\UserFactory;

it('signs each host in on its own guard only', function (): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.domain' => 'app.example.test',
        'dev-login.logins' => ['staff' => ['domain' => 'staff.example.test', 'guard' => 'staff']],
    ]);
    $user = UserFactory::new()->create();

    $this->get('http://staff.example.test/dev/login/'.$user->id)->assertRedirect();
    $this->assertAuthenticatedAs($user, 'staff');
    $this->assertGuest('web');

    auth()->guard('staff')->logout();
    $this->flushSession();

    $this->get('http://app.example.test/dev/login/'.$user->id)->assertRedirect();
    $this->assertAuthenticatedAs($user, 'web');
    $this->assertGuest('staff');
});

// Laravel 13's RouteCollection already matches domain routes first; the registration order matters on Laravel 12.
it('does not let a domainless default profile swallow a domain-bound login with the same path (Laravel 12 ordering)', function (): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.logins' => ['staff' => ['domain' => 'staff.example.test', 'guard' => 'staff']],
    ]);
    $user = UserFactory::new()->create();

    $this->get('http://staff.example.test/dev/login/'.$user->id)->assertRedirect();

    $this->assertAuthenticatedAs($user, 'staff');
    $this->assertGuest('web');
});

it('reads {user} by name when the domain has a parameter of its own', function (): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.logins' => ['tenant' => ['domain' => '{tenant}.example.test']],
    ]);
    $user = UserFactory::new()->create();

    $this->get('http://acme.example.test/dev/login/'.$user->id)->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect(route('dev.login.tenant', ['tenant' => 'acme', 'user' => 7]))->toBe('http://acme.example.test/dev/login/7');
});

it('finds the user in the database a profile middleware switched to while the request runs', function (): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.logins' => ['tenant' => [
            'domain' => '{tenant}.example.test',
            'middleware' => ['web', SwitchToTenantDatabase::class],
        ]],
    ]);
    $this->createTenantDatabase();
    DB::connection('tenant')->table('users')->insert(['id' => 5, 'name' => 'Tom', 'email' => 'tom@acme.test', 'password' => 'x']);

    $this->get('http://acme.example.test/dev/login/5')->assertRedirect();

    expect(auth()->guard()->id())->toBe(5);
});

it('closes a profile with enabled false: no route, 404', function (): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.logins' => ['staff' => ['enabled' => false, 'path' => 'staff/in/{user}']],
    ]);
    $user = UserFactory::new()->create();

    expect(Route::has('dev.login.staff'))->toBeFalse()
        ->and(Route::has('dev.login'))->toBeTrue();

    $this->get('/staff/in/'.$user->id)->assertNotFound();
    $this->assertGuest();
});

it('does not open a profile while the global switch is off', function (): void {
    $this->reboot([
        'dev-login.enabled' => false,
        'dev-login.logins' => ['staff' => ['enabled' => true, 'path' => 'staff/in/{user}']],
    ]);
    $user = UserFactory::new()->create();

    expect(Route::has('dev.login.staff'))->toBeFalse();

    $this->get('/staff/in/'.$user->id)->assertNotFound();
});

it('answers 404 from the controller when a profile is closed after its route was registered', function (): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.logins' => ['staff' => ['path' => 'staff/in/{user}']],
    ]);
    $user = UserFactory::new()->create();

    config(['dev-login.logins.staff.enabled' => false]);
    $this->get('/staff/in/'.$user->id)->assertNotFound();

    config(['dev-login.logins' => []]);
    $this->get('/staff/in/'.$user->id)->assertNotFound();
    $this->assertGuest();
});

it('blocks a profile in production on its own and keeps the others', function (): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.block_in_production' => false,
        'dev-login.logins' => ['staff' => ['block_in_production' => true, 'path' => 'staff/in/{user}']],
    ], 'production');

    expect(Route::has('dev.login.staff'))->toBeFalse()
        ->and(Route::has('dev.login'))->toBeTrue();
});

it('lets a profile switch cross-site rejection on, never off', function (): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.reject_cross_site' => false,
        'dev-login.logins' => ['strict' => ['reject_cross_site' => true, 'path' => 'strict/{user}']],
    ]);
    $user = UserFactory::new()->create();
    $crossSite = ['Sec-Fetch-Site' => 'cross-site'];

    $this->withHeaders($crossSite)->get('/strict/'.$user->id)->assertForbidden();
    $this->assertGuest();
    $this->withHeaders($crossSite)->get('/dev/login/'.$user->id)->assertRedirect();

    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.reject_cross_site' => true,
        'dev-login.logins' => ['lax' => ['reject_cross_site' => false, 'path' => 'lax/{user}']],
    ]);
    $user = UserFactory::new()->create();

    $this->withHeaders($crossSite)->get('/lax/'.$user->id)->assertForbidden();
    $this->assertGuest();
});

it('checks the global allowed_ips and the profile\'s own list, both must pass', function (array $global, array $own, bool $passes): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.allowed_ips' => $global,
        'dev-login.logins' => ['staff' => ['allowed_ips' => $own, 'path' => 'staff/in/{user}']],
    ]);
    $user = UserFactory::new()->create();

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->get('/staff/in/'.$user->id);

    if ($passes) {
        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    } else {
        $response->assertNotFound();
        $this->assertGuest();
    }
})->with([
    'both let it in' => [['10.0.0.0/8'], ['10.1.2.3'], true],
    'no lists at all' => [[], [], true],
    'only the global list set, passes' => [['10.0.0.0/8'], [], true],
    'global list refuses' => [['192.168.0.0/16'], ['10.1.2.3'], false],
    'own list refuses' => [['10.0.0.0/8'], ['192.168.0.0/16'], false],
    'only the own list set, refuses' => [[], ['192.168.0.0/16'], false],
]);

it('rejects a logins entry named default', function (): void {
    config(['dev-login.logins' => ['default' => ['guard' => 'staff']]]);

    (new LoginProfiles)->all();
})->throws(InvalidArgumentException::class, 'reserved');

it('gives a login the built-in defaults, not the top-level values', function (): void {
    config([
        'dev-login.path' => 'top/{user}',
        'dev-login.user_key' => 'email',
        'dev-login.guard' => 'accounts',
        'dev-login.logins' => ['staff' => []],
    ]);

    $staff = (new LoginProfiles)->find('staff');

    expect($staff)->not->toBeNull()
        ->and($staff?->path)->toBe('dev/login/{user}')
        ->and($staff?->routeName)->toBe('dev.login.staff')
        ->and($staff?->userKey)->toBe('id')
        ->and($staff?->guard)->toBeNull()
        ->and($staff?->middleware)->toBe(['web', 'throttle:60,1'])
        ->and($staff?->redirectParameter)->toBe('to')
        ->and($staff?->enabled)->toBeTrue();
});

it('puts the profile name in the events', function (): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.logins' => [
            'staff' => ['guard' => 'staff', 'path' => 'staff/in/{user}'],
            'locked' => ['path' => 'locked/{user}', 'authorize' => DenyAll::class],
        ],
    ]);
    $user = UserFactory::new()->create();
    $succeeded = [];
    $refused = [];

    Event::listen(DevLoginSucceeded::class, function (DevLoginSucceeded $event) use (&$succeeded): void {
        $succeeded[] = $event->login;
    });
    Event::listen(DevLoginRefused::class, function (DevLoginRefused $event) use (&$refused): void {
        $refused[] = $event->login;
    });

    $this->get('/dev/login/'.$user->id);
    $this->get('/staff/in/'.$user->id);
    $this->get('/locked/'.$user->id)->assertForbidden();

    expect($succeeded)->toBe(['default', 'staff'])
        ->and($refused)->toBe(['locked']);
});

it('throws on a non-array allowed_ips, global or in a login', function (): void {
    config(['dev-login.allowed_ips' => '10.0.0.0/8']);
    expect(fn () => (new LoginProfiles)->globalAllowedIps())->toThrow(InvalidArgumentException::class);

    config(['dev-login.allowed_ips' => [], 'dev-login.logins' => ['staff' => ['path' => 's/{user}', 'allowed_ips' => '10.0.0.0/8']]]);
    expect(fn () => (new LoginProfiles)->all())->toThrow(InvalidArgumentException::class);

    config(['dev-login.logins' => ['staff' => ['path' => 's/{user}']]]);
    expect((new LoginProfiles)->find('staff')?->allowedIps)->toBe([]);
});

it('refuses a request when the global allowed_ips is not an array', function (): void {
    config(['dev-login.allowed_ips' => '10.0.0.0/8']);
    $user = UserFactory::new()->create();

    $this->withoutExceptionHandling()->get('/dev/login/'.$user->id);
})->throws(InvalidArgumentException::class);

it('throws on two logins with the same host and path, or the same route name', function (array $logins, string $message): void {
    config(['dev-login.logins' => $logins]);

    expect(fn () => (new LoginProfiles)->all())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'builtin path vs the default' => [['a' => []], 'dev/login/{user}'],
    'same path, trimmed' => [['a' => ['path' => '/x/{user}/'], 'b' => ['path' => 'x/{user}']], 'x/{user}'],
    'same domain and path' => [['a' => ['domain' => 'h.test'], 'b' => ['domain' => 'H.test']], 'both use the path'],
    'same route name' => [['a' => ['path' => 'a/{user}', 'route_name' => 'r'], 'b' => ['path' => 'b/{user}', 'route_name' => 'r']], 'route name'],
]);

it('allows the same path on different domains', function (): void {
    config(['dev-login.logins' => ['a' => ['domain' => 'a.test'], 'b' => ['domain' => 'b.test']]]);

    expect(array_keys((new LoginProfiles)->all()))->toBe(['default', 'a', 'b']);
});

it('throws on a non-boolean enabled, block_in_production or reject_cross_site in a login', function (string $key): void {
    config(['dev-login.logins' => ['staff' => ['path' => 's/{user}', $key => 'false']]]);

    (new LoginProfiles)->all();
})->with(['enabled', 'block_in_production', 'reject_cross_site'])->throws(InvalidArgumentException::class);

it('keeps a login closed in production when only the login says block_in_production false', function (): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.block_in_production' => true,
        'dev-login.logins' => ['staff' => ['block_in_production' => false, 'path' => 'staff/in/{user}']],
    ], 'production');

    expect(Route::has('dev.login.staff'))->toBeFalse();
});

it('answers 404 for a login when the global switch is turned off after boot', function (): void {
    $this->bootLogins(['staff' => ['path' => 'staff/in/{user}']]);
    $user = UserFactory::new()->create();

    config(['dev-login.enabled' => false]);

    $this->get('/staff/in/'.$user->id)->assertNotFound();
    $this->assertGuest();
});

it('keeps the profile name on the route through route caching', function (): void {
    $this->bootLogins(['staff' => ['path' => 'staff/in/{user}']]);
    Route::get('/cache-safe', fn (): string => 'ok');

    $cached = Route::getRoutes()->compile();
    $compiled = (new CompiledRouteCollection($cached['compiled'], $cached['attributes']))->setRouter(app('router'))->setContainer(app());

    expect($compiled->getByName('dev.login.staff')?->getAction('dev_login'))->toBe('staff');
});
