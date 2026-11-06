<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Support;

/**
 * One way to sign in: the default profile (the top-level config) or an entry of the logins config.
 *
 * @internal
 */
final readonly class LoginProfile
{
    /**
     * @param  list<string>  $middleware
     * @param  list<string>  $allowedIps  this profile's own list; the global one is checked on top of it
     */
    public function __construct(
        public string $name,
        public ?string $guard,
        public ?string $domain,
        public string $path,
        public string $routeName,
        public array $middleware,
        public string $userKey,
        public ?string $authorize,
        public ?string $redirect,
        public ?string $redirectParameter,
        public array $allowedIps,
        public ?string $tenant,
        public bool $enabled,
        public bool $blockInProduction,
        public bool $rejectCrossSite,
    ) {}

    public function isDefault(): bool
    {
        return $this->name === LoginProfiles::DEFAULT;
    }

    /**
     * The global switch and this profile's own switch are both on, and production does not block it.
     */
    public function open(): bool
    {
        return $this->enabledEverywhere() && ! $this->blockedInProduction();
    }

    /**
     * Whether the production block is what keeps an otherwise enabled profile closed.
     */
    public function blockedInProduction(): bool
    {
        return $this->enabledEverywhere() && $this->blockInProduction && app()->isProduction();
    }

    public function state(): string
    {
        return match (true) {
            $this->open() => 'open',
            $this->blockedInProduction() => 'blocked (production)',
            default => 'closed',
        };
    }

    /**
     * The route parameters the domain asks for, like "tenant" in {tenant}.example.test.
     *
     * @return list<string>
     */
    public function domainParameters(): array
    {
        if ($this->domain === null) {
            return [];
        }

        preg_match_all('/\{(\w+)\??\}/', $this->domain, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Whether a host (a port is ignored) fits the domain pattern; each {param} is one label. No domain accepts any host.
     */
    public function matchesHost(string $host): bool
    {
        if ($this->domain === null) {
            return true;
        }

        $pattern = '';

        foreach (preg_split('/(\{\w+\??\})/', $this->domain, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $pattern .= preg_match('/^\{\w+\??\}$/', $part) === 1 ? '[^.]+' : preg_quote($part, '/');
        }

        return preg_match('/^'.$pattern.'$/Di', explode(':', $host)[0]) === 1;
    }

    private function enabledEverywhere(): bool
    {
        return $this->enabled && config()->boolean('dev-login.enabled', false);
    }
}
