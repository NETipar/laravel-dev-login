<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Support;

use InvalidArgumentException;

/**
 * Builds the profiles from config on every call. Never touches a guard or user provider.
 *
 * @internal
 */
final class LoginProfiles
{
    public const string DEFAULT = 'default';

    /** What a logins entry gets for a key it leaves out (it does not inherit the top-level values). */
    private const array DEFAULTS = [
        'path' => 'dev/login/{user}',
        'middleware' => ['web', 'throttle:60,1'],
        'user_key' => 'id',
        'redirect_parameter' => 'to',
        'allowed_ips' => [],
        'enabled' => true,
    ];

    /**
     * All profiles by name, the default one first.
     *
     * @return array<string, LoginProfile>
     */
    public function all(): array
    {
        $profiles = [self::DEFAULT => $this->default()];

        foreach (config()->array('dev-login.logins', []) as $name => $values) {
            if (! is_string($name) || $name === '' || ! is_array($values)) {
                throw new InvalidArgumentException('Every dev-login.logins entry must be a profile name pointing to an array.');
            }

            if ($name === self::DEFAULT) {
                throw new InvalidArgumentException('The dev-login profile name [default] is reserved for the top-level config; pick another name in dev-login.logins.');
            }

            $profiles[$name] = $this->login($name, $values);
        }

        $this->assertNoDuplicates($profiles);

        return $profiles;
    }

    public function find(string $name): ?LoginProfile
    {
        return $this->all()[$name] ?? null;
    }

    public function default(): LoginProfile
    {
        $global = $this->globalSwitches();

        return $this->make(self::DEFAULT, [
            'guard' => config('dev-login.guard'),
            'domain' => config('dev-login.domain'),
            'path' => config('dev-login.path'),
            'route_name' => config('dev-login.route_name'),
            'middleware' => config('dev-login.middleware'),
            'user_key' => config('dev-login.user_key'),
            'authorize' => config('dev-login.authorize'),
            'redirect' => config('dev-login.redirect'),
            'redirect_parameter' => config('dev-login.redirect_parameter'),
            'tenant' => config('dev-login.tenant'),
        ], enabled: true, block: $global['block'], reject: $global['reject']);
    }

    /**
     * IPs or ranges that apply to every profile; empty = any.
     *
     * @return list<string>
     */
    public function globalAllowedIps(): array
    {
        return $this->strings(config()->array('dev-login.allowed_ips', []), 'dev-login.allowed_ips');
    }

    /**
     * The profiles that get a route, domain-bound ones first: on Laravel 12 a domainless route
     * registered earlier would catch every host.
     *
     * @return list<LoginProfile>
     */
    public function open(): array
    {
        $open = array_values(array_filter($this->all(), fn (LoginProfile $profile): bool => $profile->open()));

        usort($open, fn (LoginProfile $a, LoginProfile $b): int => ($a->domain === null) <=> ($b->domain === null));

        return $open;
    }

    /**
     * Two routes with the same host and path (or name) would leave one login unreachable.
     *
     * @param  array<string, LoginProfile>  $profiles
     */
    private function assertNoDuplicates(array $profiles): void
    {
        $seen = [];
        $names = [];

        foreach ($profiles as $profile) {
            $key = strtolower($profile->domain ?? '').'/'.trim($profile->path, '/');

            if (isset($seen[$key])) {
                throw new InvalidArgumentException("The dev-login logins [{$seen[$key]}] and [{$profile->name}] both use the path [{$profile->path}] on ".($profile->domain ?? 'any host').'. Give one of them its own path or domain.');
            }

            if (isset($names[$profile->routeName])) {
                throw new InvalidArgumentException("The dev-login logins [{$names[$profile->routeName]}] and [{$profile->name}] share the route name [{$profile->routeName}].");
            }

            $seen[$key] = $profile->name;
            $names[$profile->routeName] = $profile->name;
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function login(string $name, array $values): LoginProfile
    {
        $global = $this->globalSwitches();

        return $this->make(
            $name,
            $values,
            enabled: $this->flag($name, $values, 'enabled', self::DEFAULTS['enabled']),
            block: $this->flag($name, $values, 'block_in_production', false) || $global['block'],
            reject: $this->flag($name, $values, 'reject_cross_site', false) || $global['reject'],
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function make(string $name, array $values, bool $enabled, bool $block, bool $reject): LoginProfile
    {
        $middleware = $values['middleware'] ?? null;

        return new LoginProfile(
            name: $name,
            guard: $this->optional($values, 'guard'),
            domain: $this->optional($values, 'domain'),
            path: $this->required($values, 'path', self::DEFAULTS['path']),
            routeName: $this->required($values, 'route_name', $name === self::DEFAULT ? 'dev.login' : 'dev.login.'.$name),
            middleware: $this->strings(is_array($middleware) ? $middleware : self::DEFAULTS['middleware'], 'middleware'),
            userKey: $this->required($values, 'user_key', self::DEFAULTS['user_key']),
            authorize: $this->optional($values, 'authorize'),
            redirect: $this->optional($values, 'redirect'),
            // a key that is present but null turns the parameter off
            redirectParameter: array_key_exists('redirect_parameter', $values)
                ? $this->optional($values, 'redirect_parameter')
                : self::DEFAULTS['redirect_parameter'],
            allowedIps: $name === self::DEFAULT ? [] : $this->strings($values['allowed_ips'] ?? self::DEFAULTS['allowed_ips'], "dev-login.logins.{$name}.allowed_ips"),
            tenant: $this->optional($values, 'tenant'),
            enabled: $enabled,
            blockInProduction: $block,
            rejectCrossSite: $reject,
        );
    }

    /**
     * The switches that apply to every profile and that a profile can only narrow.
     *
     * @return array{block: bool, reject: bool}
     */
    private function globalSwitches(): array
    {
        return [
            'block' => config()->boolean('dev-login.block_in_production', true),
            'reject' => config()->boolean('dev-login.reject_cross_site', true),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function optional(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function required(array $values, string $key, string $default): string
    {
        return $this->optional($values, $key) ?? $default;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function flag(string $login, array $values, string $key, bool $default): bool
    {
        $value = $values[$key] ?? $default;

        return is_bool($value) ? $value : throw new InvalidArgumentException("dev-login.logins.{$login}.{$key} must be true or false.");
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value, string $key = ''): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("The dev-login config value [{$key}] must be an array.");
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
