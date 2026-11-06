# Release Notes

## [Unreleased](https://github.com/NETipar/laravel-dev-login/compare/v0.1.0...HEAD)

## [v0.1.0](https://github.com/NETipar/laravel-dev-login/commits/v0.1.0) - 2026-11-06

First release.

Sign the browser in as any user with one URL, for manual, Playwright and AI-agent testing. The route does not exist until `DEV_LOGIN_ENABLED=true`, and by default it is never registered under `APP_ENV=production`.

### Features

- `GET /dev/login/{user}` signs in by id, or by any attribute set in `user_key`, through the guard's own user provider.
- `?to=` sends the user to a one-off target after the sign-in. Only same-host relative paths are accepted.
- The `authorize` hook decides who may be signed in. It is a plain invokable class, so no package interface is involved.
- The `DevLoginSucceeded` and `DevLoginRefused` events, and the `dev_login` Context marker, for audit logs.
- Built-in protection:
  - `allowed_ips` (IP addresses and CIDR ranges);
  - a `403` for a sign-in started from another site (`Sec-Fetch-Site: cross-site`);
  - prefetch requests get a `204`, so nobody is signed in;
  - throttling;
  - `no-store` and `noindex` headers on every response.
  
- Named logins (`logins`) for several guards and hosts, for example a tenant subdomain and an admin host:
  - each login has its own route;
  - logins do not inherit the top-level keys;
  - the global switches can only be narrowed per login.
  
- The guard and its user provider are looked up while the request runs, after the login's middleware, so a tenant middleware can switch the database first.
- `php artisan dev-login:url {user}`, with these options:
  - `--login` picks the login;
  - `--host` sets the host of the URL;
  - `--tenant` runs the login's `tenant` hook;
  - `--to` adds the one-off target.
  
- `php artisan about` has a Dev Login section.
- A bundled Laravel Boost skill.

Requires PHP 8.3+ and Laravel 12 or 13. Install with `composer require --dev netipar/laravel-dev-login`.
