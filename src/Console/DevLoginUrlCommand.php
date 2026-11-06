<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use NETipar\DevLogin\DevLogin;
use NETipar\DevLogin\Support\RedirectTarget;
use NETipar\DevLogin\Support\UserResolver;

final class DevLoginUrlCommand extends Command
{
    protected $signature = 'dev-login:url
        {user : The user key (see dev-login.user_key), or an e-mail address}
        {--to= : A same-host relative path to open after the sign-in}';

    protected $description = 'Print the dev login URL of a user (it does not sign anyone in)';

    public function handle(UserResolver $users, RedirectTarget $redirects): int
    {
        if (! DevLogin::gateOpen()) {
            $this->components->error('The dev login is closed: set DEV_LOGIN_ENABLED=true (it is never open in production while block_in_production is on).');

            return self::FAILURE;
        }

        $to = $this->option('to');
        $parameter = config('dev-login.redirect_parameter');
        $query = [];

        if (is_string($to) && $to !== '') {
            if (! is_string($parameter) || $parameter === '') {
                $this->components->error('The ?to= target is turned off (dev-login.redirect_parameter is null).');

                return self::FAILURE;
            }

            if (! $redirects->isSafeRelativePath($to)) {
                $this->components->error('--to must be a relative path on this host, like /servers/12.');

                return self::FAILURE;
            }

            $query = [$parameter => $to];
        }

        try {
            $guardName = $users->guardName();
            $guard = $users->guard($guardName);

            // not $this->argument(): Larastan types it only in a built Testbench skeleton, CI sees mixed
            $argument = $this->input->getArgument('user');
            $argument = is_string($argument) ? $argument : '';

            $user = str_contains($argument, '@')
                ? $users->findByEmail($guard, $argument)
                : $users->find($guard, $argument);

            if (! $user instanceof Authenticatable) {
                $this->components->error("No user [{$argument}] found on the guard [{$guardName}].");

                return self::FAILURE;
            }

            $this->line(route(config()->string('dev-login.route_name'), ['user' => $users->urlValue($user), ...$query]));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
