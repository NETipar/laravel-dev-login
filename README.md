<picture>
  <source media="(prefers-color-scheme: dark)" srcset="art/banner.svg">
  <img alt="laravel-dev-login" src="art/banner.svg">
</picture>

# Dev Login for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/netipar/laravel-dev-login.svg?style=flat-square)](https://packagist.org/packages/netipar/laravel-dev-login)
[![Tests](https://img.shields.io/github/actions/workflow/status/NETipar/laravel-dev-login/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/NETipar/laravel-dev-login/actions?query=workflow%3Atests)
[![Total Downloads](https://img.shields.io/packagist/dt/netipar/laravel-dev-login.svg?style=flat-square)](https://packagist.org/packages/netipar/laravel-dev-login)

A sign-in URL for browser testing: `GET /dev/login/{user}` signs the browser in as any user your application lets in, without a passkey, password or e-mail. One switch turns it on (`DEV_LOGIN_ENABLED`, off by default), and while it is off the route **does not exist**.

> **Security warning: this package is a full authentication bypass.**
> Whoever can reach the URL can become any user. It bypasses passwords, 2FA and passkeys by design. It is meant for local and trusted development machines **only**.
>
> - Install it with `composer require --dev`, never as a runtime dependency.
> - Enable it only in your own, untracked `.env`. Never enable it in `.env.example`, `phpunit.xml`, CI or on any server that holds real data.
> - Never deploy a config cache or route cache that was built while it was enabled (see [Deployment notes](#deployment-notes)).

## Contents

- [Why Dev Login](#why-dev-login)
- [Requirements](#requirements)
- [Quickstart](#quickstart)
- [Usage](#usage)
- [The gate](#the-gate)
- [Behaviour](#behaviour)
- [Hooks](#hooks)
- [Redirect](#redirect)
- [Guard and user lookup](#guard-and-user-lookup)
- [Multiple guards and hosts](#multiple-guards-and-hosts)
- [Multi-tenant apps](#multi-tenant-apps)
- [Security notes](#security-notes)
- [Deployment notes](#deployment-notes)
- [Artisan](#artisan)
- [Why not an existing package?](#why-not-an-existing-package)
- [Testing](#testing)
- [Configuration](#configuration)

## Why Dev Login

- **Passkey-only apps are hard to test in a browser.** Playwright, AI agents and manual testing have no simple way past a passkey prompt.
- **Role and permission testing.** Switch between users with one click and see what each of them can reach.
- **AI agents can sign in with a URL**, no secret needed: `php artisan dev-login:url agent@demo.test --to=/servers/12` prints a link that lands on the target page.
- **Real rules, real session.** A user your application would not let in stays out (the `authorize` hook), and every dev login is marked, so your audit log can tell it apart from a real one.

## Requirements

- PHP **8.3+**
- Laravel **12 or 13**

## Quickstart

**1. Install as a dev dependency.**

```bash
composer require --dev netipar/laravel-dev-login
php artisan vendor:publish --tag=dev-login-config
```

**2. Turn it on in your own `.env`** (the untracked one on your machine):

```dotenv
DEV_LOGIN_ENABLED=true
```

Keep `DEV_LOGIN_ENABLED=false` (or absent) in `.env.example` and `phpunit.xml`.

**3. Open the URL.**

```
https://your-app.test/dev/login/1
```

The browser is signed in as the user with id `1` and redirected.

## Usage

### The URL

`GET /dev/login/{user}` signs in as `{user}`. By default `{user}` is the user's **id** as the guard's user provider knows it (integer, UUID or ULID); set [`user_key`](#guard-and-user-lookup) to look users up by e-mail or another attribute. The default id keeps e-mail addresses out of access logs and browser history.

Add `?to=` for a one-off landing page (same-host relative paths only):

```
/dev/login/1?to=/servers/12
```

### Get a link from the command line

```bash
php artisan dev-login:url agent@demo.test --to=/servers/12
```

If the argument contains `@` the user is found by e-mail, otherwise by your `user_key`. The command prints the finished URL (built from `APP_URL`); it does not sign anybody in. See [Artisan](#artisan).

### Playwright, agent-browser and AI agents

Give the printed URL to the browser tool and open it. The session cookie is set on the redirect, so every following request is signed in.

To switch users, open another dev login URL. The previous user is signed out (a `Logout` event fires) and the old session is thrown away, so nothing from it carries over, not even a password or passkey confirmation.

## The gate

- `DEV_LOGIN_ENABLED` is the **only** thing that turns the route on. `APP_ENV` is not used for that, because an unedited `.env.example` often says `APP_ENV=local`.
- `APP_ENV` can only turn it off: with `block_in_production` (on by default) there is no route under `APP_ENV=production`, even with the switch on.
- While it is off, the route is not registered at all: it is not in `route:list`, Wayfinder generates nothing for it, and the URL answers `404` like any unknown URL.
- The controller checks the switch again on every request, so a route cache built while it was on cannot keep the route working.

## Behaviour

| Case | Response |
|---|---|
| Enabled, user may be signed in | The old session is dropped, the user is signed in and redirected (`302`); Laravel's `Login` event and `DevLoginSucceeded` fire, and `Context` gets `dev_login = true` |
| Enabled, somebody else is already signed in | The same, after the old user is signed out (`Logout` event); nothing from the old session carries over |
| Enabled, the `authorize` hook refuses | `403` with the hook's `Response::deny()` message (or a generic one), the visitor stays a guest, `DevLoginRefused` fires |
| Enabled, user does not exist | `404` |
| Enabled, `allowed_ips` is set and the request IP is not in it | `404` |
| Enabled, prefetch request (`Sec-Purpose: prefetch` / `Purpose: prefetch`) | `204`, nobody is signed in, so link previews and prefetching cannot sign anyone in |
| Enabled, `Sec-Fetch-Site: cross-site` (opened from another site) | `403`, nobody is signed in. A typed URL, a bookmark or an agent (`none`), `same-origin`, `same-site` and a missing header (curl) pass |
| Enabled, per-minute request limit exceeded | `429` (`throttle` is in the default middleware list) |
| Enabled, `?to=` is a relative path on this host | Redirects there after the sign-in; any other target is ignored |
| `DEV_LOGIN_ENABLED` missing or `false` (any `APP_ENV`) | No route: `404` like any unknown URL |
| Enabled, but `APP_ENV=production` and `block_in_production` is on (default) | No route; `php artisan about` shows `Blocked (production): yes` |
| A route cache built while the switch was on, switch now off | `404` from the controller |

The checks that give nothing away (`404`) run first: the switch, then `allowed_ips`. Only then come the prefetch and `Sec-Fetch-Site` checks, the user lookup and the `authorize` hook.

Every response carries `Cache-Control: no-store, private`, `X-Robots-Tag: noindex` and `Referrer-Policy: no-referrer`.

## Hooks

The package is a dev dependency, so after `composer install --no-dev` its classes are gone. Code in your application that runs in production must therefore never call a package class, and the package has no static API to call. Hooks are class names in the config, and the dev login marker travels in Laravel's own `Context`.

### Who may sign in: `authorize`

Set `authorize` to the class name of a **plain invokable class in your application**. It receives the user and the request and returns a `bool` or an `Illuminate\Auth\Access\Response`:

```php
<?php

namespace App\DevLogin;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

class AuthorizeActiveUser
{
    public function __invoke(Authenticatable $user, Request $request): bool|Response
    {
        // Your own application rule, here an example method on your User model.
        return $user->isActive()
            ? true
            : Response::deny('This account is deactivated. Ask an admin to reactivate it.');
    }
}
```

```php
// config/dev-login.php
'authorize' => \App\DevLogin\AuthorizeActiveUser::class,
```

There is no package interface to implement on purpose: an `implements` would fail to load on a `--no-dev` deploy.

- Return `true` to let the user in, `false` to refuse.
- Return `Response::deny('…')` to refuse with your own message; `Response::denyAsNotFound()` and `denyWithStatus(…)` answer with their own status.
- Anything else (`1`, `'yes'`, `null`) is a refusal.
- `null` (the default) means everyone may be signed in.

### Events

Two events let your application write its own audit trail:

- `NETipar\DevLogin\Events\DevLoginSucceeded(Authenticatable $user, string $guard, string $login)`: fired after Laravel's `Login` event, in the same request.
- `NETipar\DevLogin\Events\DevLoginRefused(Authenticatable $user, string $guard, ?string $message, string $login)`

`$login` is the name of the login that was used (`default`, or a key of `logins`).

The request is not part of the events, because a queued listener could not serialize it; a synchronous listener reads it from `request()`.

Listening to them is safe even where the package is not installed: `DevLoginRefused::class` is only a string and does not load the class, and event discovery and `event:cache` store nothing but that name.

```php
use Illuminate\Support\Facades\Event;

// AppServiceProvider::boot()
Event::listen(\NETipar\DevLogin\Events\DevLoginRefused::class, function ($event) {
    // e.g. write a "sign-in refused" audit row for $event->user, using request() for the details
});
```

### Marking dev logins in your audit log: `Context`

Right before `Auth::login()`, the controller sets `Context::add('dev_login', true)`. In your own `Login` listener, check it **without referencing any package class**:

```php
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Context;

class RecordLogin
{
    public function handle(Login $event): void
    {
        $method = Context::get('dev_login') === true
            ? 'Dev login link'
            : 'Passkey'; // whatever your normal method is

        // write the audit row with $method
    }
}
```

Use the plain string `'dev_login'`, not the package's `DevLogin::CONTEXT_KEY` constant, for the same `--no-dev` reason. The log lines of that request and the jobs dispatched from it carry the marker too.

## Redirect

After a successful sign-in the target is resolved in this order:

1. The `?to=` query parameter (name set by `redirect_parameter`; `null` turns it off), if it is a same-host relative path.
2. The `redirect` config: a route name (when `Route::has()` knows it), otherwise a path.
3. If `redirect` is `null`: `config('fortify.home')`, or `/` when that is not set.

A `?to=` that points anywhere else (an absolute URL, `//host`, another host) is ignored.

## Guard and user lookup

- `guard`: the session guard to sign in on. `null` means the default guard. A guard that is not stateful is an error.
- `user_key`: how `{user}` is looked up through the guard's user provider. `'id'` (the default) uses `retrieveById()`, any other attribute (for example `'email'`) uses `retrieveByCredentials([attribute => value])`.

The user is found through the guard's own user provider from the raw URL value, so any provider and key type works, and a `Route::model('user', …)` in your app does not interfere.

With a non-default `guard`, dropping the old session also signs the visitor out of your other guards, without a `Logout` event for them.

```php
// config/dev-login.php
'user_key' => 'email', // then /dev/login/agent@demo.test works
```

## Multiple guards and hosts

The top-level keys are the `default` login. `logins` adds more, each with its own route. For example, company users sign in on their tenant subdomain with the `web` guard, and staff on a separate admin host with the `staff` guard:

```php
// config/dev-login.php
'guard' => 'web',
'domain' => '{tenant}.example.test',

'logins' => [
    'staff' => [
        'guard' => 'staff',
        'domain' => 'admin.example.test',
        'authorize' => App\Dev\StaffOnly::class,
    ],
],
```

That gives two routes:

- `dev.login` on `acme.example.test/dev/login/{user}`;
- `dev.login.staff` on `admin.example.test/dev/login/{user}`.

How the logins relate:

- **No inheritance.** A login in `logins` does not inherit the top-level values. A key it leaves out gets the package default: path `dev/login/{user}`, route name `dev.login.<name>`, middleware `web` + `throttle:60,1`, the default guard, `user_key` `id`, no `authorize` hook, `?to=` on. The `authorize` hook, the middleware and the user key usually differ per guard, and a value inherited without anyone noticing could let the wrong people in.
- **`default` is taken.** A `logins.default` entry is a configuration error.
- **Shared gate.** `enabled`, `block_in_production`, `reject_cross_site` and `allowed_ips` at the top level apply to every login. A login can only narrow them:
  - `'enabled' => false` removes its route;
  - `'reject_cross_site' => true` and `'block_in_production' => true` add the protection when the top level has it off;
  - its own `allowed_ips` must let the request through as well.

**Registration order.** Logins with a domain are registered before the ones without, so two logins can share a path. On Laravel 12 a login without a domain would otherwise answer on every host, including `admin.example.test`; Laravel 13 matches domain routes first anyway. Two logins on the same domain and path, or with the same route name, are a configuration error.

## Multi-tenant apps

The guard and its user provider are looked up while the request runs, after the login's middleware. The middleware that picks the tenant (switches the database connection, the session store, and calls `app('auth')->forgetGuards()`) must therefore run before the user lookup. Global middleware always does. Route middleware runs in the order of the login's `middleware` list:

```php
'middleware' => ['web', App\Http\Middleware\IdentifyTenant::class, 'throttle:60,1'],
```

The session is taken from the request, so a session store swapped by the tenant middleware is the one that gets invalidated and regenerated.

`dev-login:url` has no request to pick the tenant from. `--tenant` runs the login's `tenant` hook instead: an invokable class in your app that switches to the tenant and returns its base URL. The user is looked up after it, in that tenant. With [spatie/laravel-multitenancy](https://github.com/spatie/laravel-multitenancy):

```php
namespace App\Dev;

use App\Models\Tenant;

final class ActivateTenant
{
    public function __invoke(string $tenant): string
    {
        $current = Tenant::where('domain', "{$tenant}.example.test")->firstOrFail()->makeCurrent();

        return 'https://'.$current->domain;
    }
}
```

```php
// config/dev-login.php
'tenant' => App\Dev\ActivateTenant::class,
```

```bash
php artisan dev-login:url 12 --tenant=acme
# https://acme.example.test/dev/login/12
```

The hook is a plain class, like `authorize`: no package interface, so the config still loads without the package.

## Security notes

- **Full bypass, by design.** The package skips 2FA, passkeys and passwords. The `verified` middleware (e-mail verification) and every other route middleware still apply after the sign-in.
- **It signs in with GET**, on purpose, so an agent can sign in with a single URL. The main risk is therefore login CSRF, and it is defended in layers:
  - `<img>` / `fetch` from another site: Laravel's default `SameSite=Lax` session cookie means a cross-site sub-request gets no session.
  - Navigation started from another site (link, `window.open`): `Sec-Fetch-Site: cross-site` gets a `403` (`reject_cross_site`, on by default).
  - Link previews, prefetch and speculation rules: `204`, nobody is signed in.
  - Shared development servers: `allowed_ips` and `throttle`.
- **`allowed_ips`** takes IP addresses and CIDR ranges and is checked against `$request->ip()`. Behind a proxy, trust **only that proxy**: with `trustProxies(at: '*')` on a directly reachable host, a forged `X-Forwarded-For` gets past it.
- **`APP_ENV` never opens the route**, it can only close it (`block_in_production`).
- **The code is not even present in production**, because the package is a `require-dev` dependency.
- What remains is a config or route cache built while the switch was on and copied to a server: see [Deployment notes](#deployment-notes).

## Deployment notes

- **Build the config cache and the route cache on the target server**, never on your development machine, and never while `DEV_LOGIN_ENABLED=true`.
- **`composer install --no-dev` regenerates the package manifest** (`bootstrap/cache/packages.php`, via `package:discover`). If a deploy copies a `bootstrap/cache` directory that was built on a development machine, the manifest still lists the package's service provider, which then points at a class that is not installed. **Never ship a dev-built `bootstrap/cache`.**
- The published `config/dev-login.php` is safe without the package (it contains only `env()` calls and strings), so it can stay in the repository and be deployed with `--no-dev`.
- After upgrading the package, rebuild a local route cache (`php artisan route:cache` or `route:clear`): a route cached by an older version gives a `404`.
- Keep `DEV_LOGIN_ENABLED` at `false` or absent in `.env.example`, `phpunit.xml` and on every server. Only your own local `.env` turns it on.

## Artisan

```bash
php artisan dev-login:url {user} {--login=default} {--host=} {--tenant=} {--to=}
```

Prints a dev login URL; it does not sign anyone in.

- If the `{user}` argument contains `@`, the user is looked up by e-mail; otherwise by the login's `user_key`.
- `--login=` picks the login (default: `default`). The user is looked up on that login's guard.
- The host of the URL:
  - without a domain on the login, it comes from `APP_URL`;
  - with a fixed domain (`admin.example.test`), it is that domain with the scheme of `APP_URL`;
  - a domain with parameters (`{tenant}.example.test`) needs `--host` or `--tenant`.
- `--host=acme.example.test` puts that host in the URL, with the scheme of `APP_URL`.
- `--tenant=acme` runs the login's `tenant` hook (see [Multi-tenant apps](#multi-tenant-apps)). Without a hook it is an error. `--tenant` and `--host` cannot be used together.
- `--to=` adds the one-off redirect target.
- If the gate is closed or blocked, the login is closed, or the login does not exist, the command stops with a clear error and exit code `1`.

```bash
php artisan about
```

Shows a **Dev Login** section with: `Enabled`, `Blocked (production)`, `Route`, `Guard` and `Allowed IPs`. With `logins`, each extra login adds a row:

```
Login (staff) .... open | guard staff | admin.example.test | /dev/login/{user}
```

The state is `open`, `closed` or `blocked (production)`.

## Why not an existing package?

As of November 2026 we found no maintained package that combines a switch with a route that does not exist while off, a plain GET URL and a command for agents, a "who may sign in" hook, an audit marker, and wiring that is safe as a dev dependency.

| Package | Why it does not cover this |
|---|---|
| `spatie/laravel-login-link` | Built on a button component (Blade, Vue, React; works with Inertia) and a POST route: there is no plain URL an agent can open. `APP_ENV` opens it (`allowed_environments`); no authorize hook, no audit event. |
| `alexeyplodenko/laravel-agent-auth` | The closest idea (agents, `agent:url`, signed URL, `--redirect`), but `APP_ENV=local` turns it on, it also has a static-token mode, and it supports Laravel 11 and 12. |
| `byrcsc/laravel-dev-login` | A chooser page with fixed profiles (user + guard + tenant), intentionally POST-only: no agent URL, not an arbitrary user. |
| `caesardev/laravel-login-command` | `login:link {id\|email}` prints a signed URL; Laravel 12 and older only, no switch, hook or event. |
| `josrom/laravel-developer-logins` | Buttons on the login page, Fortify-dependent, predefined users. |
| `fahadyousafmahar/slash-login` | `GET /login/{id}`, the same URL shape, but Laravel 11 and older and an `APP_ENV` gate. |
| `ageekdev/dev-login` | A separate developer account for tools (Telescope, log viewer), not "sign in as user X". |
| `octopyid/laravel-impersonate`, `404labfr/laravel-impersonate` and similar | Production impersonation features with a different security model (switching back, permissions); not a development tool. |
| `dutchcodingcompany/filament-developer-logins` | Filament-specific login buttons. |

## Testing

```bash
composer test
```

Runs PHPStan, Pint, the type coverage check and the Pest suite.

## Configuration

`config/dev-login.php` (publish tag `dev-login-config`):

```php
<?php

declare(strict_types=1);

return [
    // The only switch that turns the route on. Off (the default), the route does not exist.
    // APP_ENV is not used for this: an unedited .env.example often says APP_ENV=local.
    'enabled' => (bool) env('DEV_LOGIN_ENABLED', false),

    // No route under APP_ENV=production, even with the switch on.
    'block_in_production' => true,

    // IPs or CIDR ranges; empty allows any. Behind a proxy, trust only that proxy,
    // or X-Forwarded-For can be forged. A request from elsewhere gets a 404.
    'allowed_ips' => [],

    // The path must contain the {user} parameter.
    'path' => 'dev/login/{user}',
    'route_name' => 'dev.login',
    // 60 requests a minute: plenty for switching users, a brake on a shared dev box.
    'middleware' => ['web', 'throttle:60,1'],
    'domain' => null,

    // Refuse a sign-in started from another site (Sec-Fetch-Site: cross-site), against login CSRF.
    // 'none' (typed URL, bookmark, agent navigation), same-origin, same-site and a missing header pass.
    'reject_cross_site' => true,

    // The session guard to sign in on; null = the default guard. A non-stateful guard is an error.
    'guard' => null,

    // How {user} is looked up through the guard's user provider: 'id' = retrieveById(),
    // any other attribute (e.g. 'email') = retrieveByCredentials([attribute => value]).
    'user_key' => 'id',

    // Who may be signed in: null = everyone, or the class name of an invokable
    // __invoke(Authenticatable $user, Request $request): bool|Illuminate\Auth\Access\Response.
    // Do not implement a package interface in it: the class would not load without the package.
    'authorize' => null,

    // Where to go after signing in: a route name, a path, or null = config('fortify.home') / '/'.
    'redirect' => null,

    // Query parameter for a one-off target (?to=/servers/12); only same-host relative paths. null = off.
    'redirect_parameter' => 'to',

    // Multi-tenant apps: the invokable class that dev-login:url --tenant=<value> runs.
    // __invoke(string $tenant): string — it switches to that tenant and returns its base URL
    // (scheme + host, e.g. https://acme.example.test). null = --tenant is not available.
    'tenant' => null,

    // More logins by name, each with its own route (dev.login.<name>); "default" is the one above.
    // A login does not inherit the keys above: what it leaves out gets the package default.
    // enabled, block_in_production, reject_cross_site and allowed_ips stay global; a login can only narrow them.
    // See "Multiple guards and hosts" and "Multi-tenant apps" in the README.
    //
    // 'logins' => [
    //     'tenant' => [
    //         'domain' => '{tenant}.example.test',
    //         'tenant' => App\Tenancy\ActivateTenant::class,
    //     ],
    //     'admin' => [
    //         'domain' => 'admin.example.test',
    //         'guard' => 'admin',
    //         'user_key' => 'email',
    //     ],
    // ],
    'logins' => [],
];
```

---

- [CHANGELOG.md](CHANGELOG.md): release history
- [SECURITY.md](.github/SECURITY.md): supported versions and reporting
- [CONTRIBUTING.md](.github/CONTRIBUTING.md): development setup

Licensed under the [MIT license](LICENSE.md).
