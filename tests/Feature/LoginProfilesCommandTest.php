<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use NETipar\DevLogin\Tests\Support\ActivateAcmeTenant;
use NETipar\DevLogin\Tests\Support\ReturnsRelativeUrl;
use Workbench\Database\Factories\UserFactory;

it('builds the URL of a login on its own domain', function (): void {
    $this->bootLogins(['staff' => ['domain' => 'staff.example.test', 'guard' => 'staff']]);
    $user = UserFactory::new()->create();

    $this->artisan('dev-login:url', ['user' => (string) $user->id, '--login' => 'staff'])
        ->expectsOutput('http://staff.example.test/dev/login/'.$user->id)
        ->assertExitCode(0);
});

it('uses the login\'s own redirect parameter and user key', function (): void {
    $this->bootLogins(['staff' => ['path' => 'staff/{user}', 'user_key' => 'email', 'redirect_parameter' => 'next']]);
    $user = UserFactory::new()->create(['email' => 'a@demo.test']);

    $this->artisan('dev-login:url', ['user' => 'a@demo.test', '--login' => 'staff', '--to' => '/x'])
        ->expectsOutput('http://localhost/staff/a@demo.test?next=%2Fx')
        ->assertExitCode(0);

    $this->bootLogins(['staff' => ['path' => 'staff/{user}', 'redirect_parameter' => null]]);

    $this->artisan('dev-login:url', ['user' => (string) $user->id, '--login' => 'staff', '--to' => '/x'])->assertExitCode(1);
});

it('fails for an unknown or a closed login', function (): void {
    $this->bootLogins(['staff' => ['enabled' => false, 'path' => 'staff/{user}']]);

    $this->artisan('dev-login:url', ['user' => '1', '--login' => 'unknown'])
        ->expectsOutputToContain('default, staff')
        ->assertExitCode(1);
    $this->artisan('dev-login:url', ['user' => '1', '--login' => 'staff'])->assertExitCode(1);
});

it('puts --host in the URL and checks it', function (): void {
    $this->bootLogins(['tenant' => ['domain' => '{tenant}.example.test']]);
    $user = UserFactory::new()->create();

    $this->artisan('dev-login:url', ['user' => (string) $user->id, '--login' => 'tenant', '--host' => 'acme.example.test:8443'])
        ->expectsOutput('http://acme.example.test:8443/dev/login/'.$user->id)
        ->assertExitCode(0);

    foreach (['evil.test/x', 'a b', 'http://x.test', '-x.test'] as $host) {
        $this->artisan('dev-login:url', ['user' => (string) $user->id, '--login' => 'tenant', '--host' => $host])->assertExitCode(1);
    }
});

it('asks for --host or --tenant when the domain has parameters', function (): void {
    $this->bootLogins(['tenant' => ['domain' => '{tenant}.example.test']]);
    $user = UserFactory::new()->create();

    $this->artisan('dev-login:url', ['user' => (string) $user->id, '--login' => 'tenant'])->assertExitCode(1);
});

it('refuses --host together with --tenant', function (): void {
    $this->bootLogins(['tenant' => ['domain' => '{tenant}.example.test', 'tenant' => ActivateAcmeTenant::class]]);

    $this->artisan('dev-login:url', ['user' => '1', '--login' => 'tenant', '--host' => 'a.test', '--tenant' => 'acme'])->assertExitCode(1);
});

it('runs the tenant hook first and finds a user that exists only in the tenant database', function (): void {
    $this->bootLogins(['tenant' => ['domain' => '{tenant}.example.test', 'tenant' => ActivateAcmeTenant::class]]);
    $this->createTenantDatabase();
    DB::connection('tenant')->table('users')->insert(['id' => 5, 'name' => 'Tom', 'email' => 'tom@acme.test', 'password' => 'x']);

    $this->artisan('dev-login:url', ['user' => '5', '--login' => 'tenant', '--tenant' => 'acme'])
        ->expectsOutput('https://acme.example.test/dev/login/5')
        ->assertExitCode(0);
});

it('fails with --tenant when the login has no hook or the hook returns no absolute URL', function (): void {
    $this->bootLogins([
        'plain' => ['path' => 'plain/{user}'],
        'broken' => ['path' => 'broken/{user}', 'tenant' => ReturnsRelativeUrl::class],
        'missing' => ['path' => 'missing/{user}', 'tenant' => 'No\\Such\\Hook'],
    ]);
    $user = UserFactory::new()->create();

    $this->artisan('dev-login:url', ['user' => (string) $user->id, '--login' => 'plain', '--tenant' => 'acme'])->assertExitCode(1);
    $this->artisan('dev-login:url', ['user' => (string) $user->id, '--login' => 'broken', '--tenant' => 'acme'])->assertExitCode(1);
    $this->artisan('dev-login:url', ['user' => (string) $user->id, '--login' => 'missing', '--tenant' => 'acme'])
        ->expectsOutputToContain('No\\Such\\Hook')
        ->assertExitCode(1);
});

it('uses the top-level tenant hook for the default login', function (): void {
    $this->bootLogins([], ['dev-login.tenant' => ActivateAcmeTenant::class]);
    $this->createTenantDatabase();
    DB::connection('tenant')->table('users')->insert(['id' => 9, 'name' => 'Tom', 'email' => 'tom@acme.test', 'password' => 'x']);

    $this->artisan('dev-login:url', ['user' => '9', '--tenant' => 'acme'])
        ->expectsOutput('https://acme.example.test/dev/login/9')
        ->assertExitCode(0);
});

it('puts a parameter-free domain in the URL from the APP_URL scheme', function (): void {
    $this->bootLogins(['admin' => ['domain' => 'admin.example.test']], ['app.url' => 'https://app.example.test']);
    $user = UserFactory::new()->create();

    $this->artisan('dev-login:url', ['user' => (string) $user->id, '--login' => 'admin'])
        ->expectsOutput('https://admin.example.test/dev/login/'.$user->id)
        ->assertExitCode(0);
});

it('adds a row per login to about', function (): void {
    $this->bootLogins([
        'staff' => ['domain' => 'staff.example.test', 'guard' => 'staff'],
        'off' => ['enabled' => false, 'path' => 'off/{user}'],
    ]);

    Artisan::call('about', ['--json' => true, '--only' => 'dev_login']);

    /** @var array{dev_login: array<string, string>} $about */
    $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($about['dev_login'])->toHaveCount(7)
        ->and($about['dev_login']['login(staff)'])->toBe('open | guard staff | staff.example.test | /dev/login/{user}')
        ->and($about['dev_login']['login(off)'])->toBe('closed | guard default (web) | any host | /off/{user}');
});

it('shows the domain of the default login in the about route', function (): void {
    $this->bootLogins([], ['dev-login.domain' => '{tenant}.example.test']);

    Artisan::call('about', ['--json' => true, '--only' => 'dev_login']);

    /** @var array{dev_login: array<string, string>} $about */
    $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($about['dev_login']['route'])->toBe('{tenant}.example.test/dev/login/{user}');
});

it('checks the host of the printed URL: syntax, trailing newline and the login\'s domain', function (): void {
    $this->bootLogins([
        'tenant' => ['domain' => '{tenant}.example.test', 'tenant' => ActivateAcmeTenant::class],
        'free' => ['path' => 'free/{user}'],
    ]);
    $user = (string) UserFactory::new()->create()->id;

    $this->artisan('dev-login:url', ['user' => $user, '--login' => 'tenant', '--host' => "acme.example.test\n"])->assertExitCode(1);
    $this->artisan('dev-login:url', ['user' => $user, '--login' => 'tenant', '--host' => 'acme.evil.test'])->assertExitCode(1);
    $this->artisan('dev-login:url', ['user' => $user, '--login' => 'tenant', '--host' => 'a.b.example.test'])->assertExitCode(1);
    $this->artisan('dev-login:url', ['user' => $user, '--login' => 'tenant', '--host' => 'ACME.Example.test:8080'])->assertExitCode(0);
    $this->artisan('dev-login:url', ['user' => $user, '--login' => 'free', '--host' => 'anything.test'])->assertExitCode(0);
    // the hook activates the tenant, but its host has to fit the domain too
    $this->artisan('dev-login:url', ['user' => $user, '--login' => 'tenant', '--tenant' => 'acme.evil'])->assertExitCode(1);
});
