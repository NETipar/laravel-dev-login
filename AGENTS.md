# Dev Login

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `netipar/laravel-dev-login`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Dev Login Specifics

- The package is a full authentication bypass for development. Every change to the gate (`DevLogin::gateOpen()`), the controller's refusal order or the redirect resolution is security-relevant and needs a test for the refused case.
- One switch opens the route: `dev-login.enabled` (`DEV_LOGIN_ENABLED`, default `false`). `APP_ENV` may only narrow it (`block_in_production`), never open it. While the gate is closed the route is not registered at all; the controller re-checks the gate for a stale route cache.
- Consumers install it with `composer require --dev`. Nothing in the consuming app's runtime code may need a package class: hooks are class-name strings in `config/dev-login.php` (plain invokables, no package interface), and the "this was a dev login" marker travels in Laravel's `Context` (`dev_login`). Do not add a static boot-time registration API.
- Users are resolved through the guard's user provider (`retrieveById()` / `retrieveByCredentials()`), never through route model binding.
- Config key and publish tag use the `dev-login` slug (`config/dev-login.php`, `dev-login-config`), not the `laravel-dev-login` composer slug.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.
