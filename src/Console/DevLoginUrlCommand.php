<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use NETipar\DevLogin\DevLogin;
use NETipar\DevLogin\Support\LoginProfile;
use NETipar\DevLogin\Support\LoginProfiles;
use NETipar\DevLogin\Support\RedirectTarget;
use NETipar\DevLogin\Support\UserResolver;

final class DevLoginUrlCommand extends Command
{
    protected $signature = 'dev-login:url
        {user : The user key (see user_key of the login), or an e-mail address}
        {--login=default : The login (profile) to build the URL for}
        {--host= : The host to put in the URL, for a domain with parameters (acme.example.test)}
        {--tenant= : A tenant value: runs the login\'s tenant hook, which returns the tenant base URL}
        {--to= : A same-host relative path to open after the sign-in}';

    protected $description = 'Print the dev login URL of a user (it does not sign anyone in)';

    public function handle(LoginProfiles $profiles, UserResolver $users, RedirectTarget $redirects): int
    {
        if (! DevLogin::gateOpen()) {
            $this->components->error('The dev login is closed: set DEV_LOGIN_ENABLED=true (it is never open in production while block_in_production is on).');

            return self::FAILURE;
        }

        try {
            $profile = $this->profile($profiles);
            $query = $this->query($profile, $redirects);
            $base = $this->baseUrl($profile);

            // not $this->argument(): Larastan types it only in a built Testbench skeleton, CI sees mixed
            $argument = $this->input->getArgument('user');
            $argument = is_string($argument) ? $argument : '';

            $guardName = $users->guardName($profile);
            $guard = $users->guard($guardName);

            $user = str_contains($argument, '@')
                ? $users->findByEmail($guard, $argument)
                : $users->find($profile, $guard, $argument);

            if (! $user instanceof Authenticatable) {
                $this->components->error("No user [{$argument}] found on the guard [{$guardName}].");

                return self::FAILURE;
            }

            $parameters = ['user' => $users->urlValue($profile, $user), ...$query];

            $this->line($base === null
                ? route($profile->routeName, $parameters)
                // route() asks for the domain parameters even for a relative URL, and ignores the root URL for domain routes
                : $base.route($profile->routeName, [...array_fill_keys($profile->domainParameters(), 'x'), ...$parameters], false));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function profile(LoginProfiles $profiles): LoginProfile
    {
        $name = $this->option('login');
        $name = is_string($name) && $name !== '' ? $name : LoginProfiles::DEFAULT;

        $profile = $profiles->find($name)
            ?? throw new InvalidArgumentException("Unknown login [{$name}]. Available: ".implode(', ', array_keys($profiles->all())).'.');

        if (! $profile->open()) {
            throw new InvalidArgumentException("The login [{$name}] is {$profile->state()}.");
        }

        return $profile;
    }

    /**
     * @return array<string, string>
     */
    private function query(LoginProfile $profile, RedirectTarget $redirects): array
    {
        $to = $this->option('to');

        if (! is_string($to) || $to === '') {
            return [];
        }

        if ($profile->redirectParameter === null) {
            throw new InvalidArgumentException('The ?to= target is turned off (redirect_parameter is null for this login).');
        }

        if (! $redirects->isSafeRelativePath($to)) {
            throw new InvalidArgumentException('--to must be a relative path on this host, like /servers/12.');
        }

        return [$profile->redirectParameter => $to];
    }

    /**
     * The scheme and host the URL starts with; null = let route() build the absolute URL from APP_URL.
     */
    private function baseUrl(LoginProfile $profile): ?string
    {
        $host = $this->option('host');
        $tenant = $this->option('tenant');
        $host = is_string($host) && $host !== '' ? $host : null;
        $tenant = is_string($tenant) && $tenant !== '' ? $tenant : null;

        if ($host !== null && $tenant !== null) {
            throw new InvalidArgumentException('Use either --host or --tenant, not both.');
        }

        if ($tenant !== null) {
            return $this->tenantBase($profile, $tenant);
        }

        if ($host !== null) {
            return $this->scheme().'://'.$this->checkedHost($profile, $host, '--host');
        }

        if ($profile->domain === null) {
            return null;
        }

        if ($profile->domainParameters() !== []) {
            throw new InvalidArgumentException("The domain [{$profile->domain}] has parameters: pass --host=<host> or --tenant=<value>.");
        }

        return $this->scheme().'://'.$profile->domain;
    }

    /**
     * The tenant hook switches to the tenant for the rest of the command and returns its base URL.
     */
    private function tenantBase(LoginProfile $profile, string $tenant): string
    {
        if ($profile->tenant === null) {
            throw new InvalidArgumentException("The login [{$profile->name}] has no tenant hook: set its 'tenant' key to an invokable class to use --tenant.");
        }

        if (! class_exists($profile->tenant)) {
            throw new InvalidArgumentException("The dev login tenant class [{$profile->tenant}] does not exist.");
        }

        $hook = app($profile->tenant);

        if (! is_callable($hook)) {
            throw new InvalidArgumentException("The dev login tenant class [{$profile->tenant}] must be invokable.");
        }

        $url = $hook($tenant);
        $parts = is_string($url) ? parse_url($url) : false;

        if ($parts === false || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || ! isset($parts['host'])) {
            throw new InvalidArgumentException("The tenant hook [{$profile->tenant}] must return an absolute http(s) URL, like https://acme.example.test.");
        }

        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $parts['scheme'].'://'.$this->checkedHost($profile, $host, 'The tenant hook host');
    }

    /**
     * A plain host (optional port) that also fits the login's domain.
     */
    private function checkedHost(LoginProfile $profile, string $host, string $label): string
    {
        if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/Di', $host) !== 1) {
            throw new InvalidArgumentException("{$label} [{$host}] is not a host name (letters, digits, dots, hyphens, optional :port).");
        }

        if (! $profile->matchesHost($host)) {
            throw new InvalidArgumentException("{$label} [{$host}] does not fit the domain [{$profile->domain}] of the login [{$profile->name}].");
        }

        return $host;
    }

    private function scheme(): string
    {
        $scheme = parse_url(config()->string('app.url', ''), PHP_URL_SCHEME);

        return $scheme === 'https' ? 'https' : 'http';
    }
}
