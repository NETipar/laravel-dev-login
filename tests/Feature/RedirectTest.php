<?php

declare(strict_types=1);

use Workbench\Database\Factories\UserFactory;

it('redirects to the configured route name, the configured path, fortify.home or the root', function (?string $redirect, ?string $home, string $expected): void {
    config(['dev-login.redirect' => $redirect, 'fortify.home' => $home]);
    $user = UserFactory::new()->create();

    $this->get('/dev/login/'.$user->id)->assertRedirect($expected);
})->with([
    'route name' => ['dashboard', null, '/dashboard'],
    'path' => ['/servers', null, '/servers'],
    'fortify home' => [null, '/home', '/home'],
    'root' => [null, null, '/'],
]);

it('follows ?to= only for same-host relative paths', function (string $to, string $expected): void {
    config(['dev-login.redirect' => '/default']);
    $user = UserFactory::new()->create();

    $this->get('/dev/login/'.$user->id.'?'.http_build_query(['to' => $to]))->assertRedirect($expected);
})->with([
    'relative path' => ['/servers/12', '/servers/12'],
    'absolute url' => ['https://evil.test', '/default'],
    'protocol-relative' => ['//evil.test', '/default'],
    'backslash' => ['/\\evil.test', '/default'],
    'tab between slashes' => ["/\t/evil.test", '/default'],
    'no leading slash' => ['servers', '/default'],
]);

it('ignores ?to= when the redirect parameter is turned off', function (): void {
    config(['dev-login.redirect' => '/default', 'dev-login.redirect_parameter' => null]);
    $user = UserFactory::new()->create();

    $this->get('/dev/login/'.$user->id.'?to=/servers')->assertRedirect('/default');
});
