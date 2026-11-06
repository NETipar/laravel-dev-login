<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use NETipar\DevLogin\DevLoginServiceProvider;
use Workbench\Database\Factories\UserFactory;

it('prints the dev login URL by id', function (): void {
    $user = UserFactory::new()->create();

    $this->artisan('dev-login:url', ['user' => (string) $user->id])
        ->expectsOutput(route('dev.login', ['user' => $user->id]))
        ->assertExitCode(0);
});

it('looks the user up by e-mail when the argument has an @, and prints the user_key value', function (): void {
    $user = UserFactory::new()->create(['email' => 'agent@demo.test']);

    $this->artisan('dev-login:url', ['user' => 'agent@demo.test'])
        ->expectsOutput('http://localhost/dev/login/'.$user->id)
        ->assertExitCode(0);

    config(['dev-login.user_key' => 'email']);

    $this->artisan('dev-login:url', ['user' => 'agent@demo.test'])
        ->expectsOutput('http://localhost/dev/login/agent@demo.test')
        ->assertExitCode(0);
});

it('adds --to as the redirect parameter', function (): void {
    $user = UserFactory::new()->create();

    $this->artisan('dev-login:url', ['user' => (string) $user->id, '--to' => '/servers/12'])
        ->expectsOutput(route('dev.login', ['user' => $user->id, 'to' => '/servers/12']))
        ->assertExitCode(0);
});

it('fails with exit code 1 for an unknown user, an unsafe --to, a disabled ?to= and a stateless guard', function (): void {
    $user = UserFactory::new()->create();

    $this->artisan('dev-login:url', ['user' => '9999'])->assertExitCode(1);
    $this->artisan('dev-login:url', ['user' => 'nobody@demo.test'])->assertExitCode(1);
    $this->artisan('dev-login:url', ['user' => (string) $user->id, '--to' => '//evil.test'])->assertExitCode(1);

    config(['dev-login.redirect_parameter' => null]);
    $this->artisan('dev-login:url', ['user' => (string) $user->id, '--to' => '/x'])->assertExitCode(1);

    config(['dev-login.guard' => 'api']);
    $this->artisan('dev-login:url', ['user' => (string) $user->id])->assertExitCode(1);
});

it('fails with exit code 1 while the gate is closed', function (): void {
    $this->reboot(['dev-login.enabled' => false]);

    $this->artisan('dev-login:url', ['user' => '1'])->assertExitCode(1);
});

it('fails with exit code 1 in production while the block is on', function (): void {
    $this->reboot(['dev-login.enabled' => true], 'production');

    $this->artisan('dev-login:url', ['user' => '1'])->assertExitCode(1);
});

it('adds a Dev Login section to about', function (): void {
    Artisan::call('about', ['--json' => true, '--only' => 'dev_login']);

    /** @var array{dev_login: array<string, string>} $about */
    $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($about['dev_login'])->toBe([
        'enabled' => 'yes',
        'blocked(production)' => 'no',
        'route' => '/dev/login/{user}',
        'guard' => 'default (web)',
        'allowed_ips' => 'any',
    ]);
});

it('shows blocked (production) in about and no route', function (): void {
    $this->reboot(['dev-login.enabled' => true, 'dev-login.guard' => 'accounts', 'dev-login.allowed_ips' => ['10.0.0.0/8']], 'production');

    Artisan::call('about', ['--json' => true, '--only' => 'dev_login']);

    /** @var array{dev_login: array<string, string>} $about */
    $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($about['dev_login'])->toBe([
        'enabled' => 'yes',
        'blocked(production)' => 'yes',
        'route' => 'not registered',
        'guard' => 'accounts',
        'allowed_ips' => '10.0.0.0/8',
    ]);
});

it('publishes the config under the dev-login-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(DevLoginServiceProvider::class, 'dev-login-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/dev-login.php')
        ->and(config('dev-login.enabled'))->toBeTrue();
});
