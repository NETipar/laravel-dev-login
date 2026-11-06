<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use NETipar\DevLogin\DevLogin;
use Workbench\Database\Factories\UserFactory;

it('registers the route only while the switch is on and production does not block it', function (?bool $enabled, string $env, bool $block): void {
    $config = ['dev-login.block_in_production' => $block];

    if ($enabled !== null) {
        $config['dev-login.enabled'] = $enabled;
    }

    $this->reboot($config, $env);

    $open = $enabled === true && ! ($block && $env === 'production');

    expect(Route::has('dev.login'))->toBe($open)
        ->and(DevLogin::gateOpen())->toBe($open);
})->with([
    'on, local, block' => [true, 'local', true],
    'on, local, no block' => [true, 'local', false],
    'on, production, block' => [true, 'production', true],
    'on, production, no block' => [true, 'production', false],
    'off, local, block' => [false, 'local', true],
    'off, production, no block' => [false, 'production', false],
    'missing, local, block' => [null, 'local', true],
    'missing, production, no block' => [null, 'production', false],
]);

it('answers an unknown URL 404 to guests and signed-in users while the switch is off', function (): void {
    $this->reboot(['dev-login.enabled' => false], 'local');

    $user = UserFactory::new()->create();

    $this->get('/dev/login/'.$user->id)->assertNotFound();
    $this->actingAs(UserFactory::new()->create())->get('/dev/login/'.$user->id)->assertNotFound();
});

it('honours the configured path, route name and domain', function (): void {
    $this->reboot([
        'dev-login.enabled' => true,
        'dev-login.path' => 'qa/in/{user}',
        'dev-login.route_name' => 'qa.in',
        'dev-login.domain' => 'qa.example.test',
    ]);

    expect(Route::has('dev.login'))->toBeFalse()
        ->and(route('qa.in', ['user' => 1]))->toBe('http://qa.example.test/qa/in/1');
});
