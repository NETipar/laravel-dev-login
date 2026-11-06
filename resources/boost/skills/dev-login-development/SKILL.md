---
name: dev-login-development
description: >
  Sign a browser in as any user of a Laravel application with the
  netipar/laravel-dev-login package, for manual, agent and browser testing
  (agent-browser, Playwright): the switch-gated GET dev login URL, the
  dev-login:url command, the authorize hook, the audit events and the
  dev_login Context marker.
license: MIT
metadata:
  author: NETipar
---

# Dev Login — Laravel integration

Use this skill when a local development Laravel app (a trusted dev machine; `block_in_production` only matches `APP_ENV=production`, so never a shared or staging server) needs a one-URL browser sign-in
as an arbitrary user (passkey-only apps, role/ACL testing, agents that must open an
authenticated page). It is a full authentication bypass for development: it is
installed with `--dev`, switched off by default, and never open in production.

## Primary Goal

Get an authenticated browser session with `php artisan dev-login:url`, and wire the
app's own rules (who may be signed in, auditing) through config strings and
`Context`, never through package classes.

## Workflow

### 1. Install and switch on (local only)

```bash
composer require --dev netipar/laravel-dev-login
php artisan vendor:publish --tag=dev-login-config   # optional
```

`.env` of the development machine only:

```dotenv
DEV_LOGIN_ENABLED=true
```

Without the switch the route does not exist (not in `route:list`, `404` for everyone).
`APP_ENV` never opens it; with `block_in_production` (default `true`) it is never
registered in the production environment.

### 2. Sign in

```bash
php artisan dev-login:url 12                                   # by user_key (default: id)
php artisan dev-login:url agent@demo.test --to=/servers/12     # an argument with @ is looked up by e-mail
```

The command prints the URL (`/dev/login/{user}`); open it in the browser. It signs the
browser in (dropping any previous session), then redirects to `?to=` (a same-host
relative path only), else the `redirect` config, else `fortify.home`, else `/`.
A closed gate or an unknown user exits with code `1`.

### 3. Configure (`config/dev-login.php`)

- `allowed_ips`: IPs/CIDR ranges for a shared dev box (others get `404`).
- `guard`: the session guard to sign in on (null = default). `user_key`: `id` or e.g. `email`.
- `redirect`: route name or path. `middleware`, `path`, `route_name`, `domain`.
- `authorize`: the class name of a plain invokable (no package interface, so the class
  loads without the package in production):

```php
final class AuthorizeActiveUser
{
    public function __invoke(Authenticatable $user, Request $request): bool|Response
    {
        return $user->isActive()
            ? Response::allow()
            : Response::deny('This account is deactivated.'); // the message becomes the 403 text
    }
}
```

### 4. Audit (optional, package-reference-free)

- The sign-in runs with `Context::get('dev_login') === true`: read it in a `Login` listener.
- Refusals: `Event::listen(\NETipar\DevLogin\Events\DevLoginRefused::class, …)` is safe even when the package is
  not installed: `use` plus `::class` is only a string and never loads the class. The event carries `$user`, `$guard`,
  `?$message`; `DevLoginSucceeded` carries `$user`, `$guard`. Event discovery and `event:cache` only store the class name.

### 5. Check

`php artisan about` shows a "Dev Login" section (enabled, blocked, route, guard, allowed IPs).

## References

- `config/dev-login.php`, `php artisan dev-login:url --help`, `php artisan about`
- Test it: set `config(['dev-login.enabled' => true])` before the app boots (Testbench `defineEnvironment`), then `get('/dev/login/'.$user->id)->assertRedirect()` and `assertAuthenticatedAs($user)`.

## Anti-Patterns

- Enabling it on a server that is reachable by others without `allowed_ips`, or in production.
- Loading package classes from code that runs in production (`extends`, `implements`, `new`, static calls, or the `DevLogin::CONTEXT_KEY` constant): `--no-dev` deploys would fatal. `Foo::class` as a plain string is fine.
- Opening it with `APP_ENV=local`: the switch is the only opener.
- Building the route cache or `bootstrap/cache` on a dev machine and deploying it.
- Using it as impersonation ("switch back") — it is not; it signs in as the given user and forgets the previous one.
