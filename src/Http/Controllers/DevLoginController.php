<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Http\Controllers;

use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;
use NETipar\DevLogin\DevLogin;
use NETipar\DevLogin\Events\DevLoginRefused;
use NETipar\DevLogin\Events\DevLoginSucceeded;
use NETipar\DevLogin\Support\RedirectTarget;
use NETipar\DevLogin\Support\UserResolver;
use Symfony\Component\HttpFoundation\IpUtils;
use UnexpectedValueException;

final class DevLoginController
{
    /**
     * Checks that give nothing away (404) run before the ones that state a reason (204, 403).
     */
    public function __invoke(
        Request $request,
        UserResolver $users,
        RedirectTarget $redirects,
        Dispatcher $events,
    ): Response|RedirectResponse {
        // defense in depth: a stale route cache can keep the route alive while the gate is closed
        abort_unless(DevLogin::gateOpen(), 404);

        $this->abortUnlessIpAllowed($request);

        if ($this->isPrefetch($request)) {
            return response()->noContent();
        }

        if (config()->boolean('dev-login.reject_cross_site', true) && $request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            abort(403, 'A dev login cannot be started from another site.');
        }

        $guardName = $users->guardName();
        $guard = $users->guard($guardName);

        $authenticatable = $users->find($guard, $this->rawUser($request)) ?? abort(404);

        $verdict = $this->authorize($authenticatable, $request);

        if ($verdict->denied()) {
            $events->dispatch(new DevLoginRefused($authenticatable, $guardName, $verdict->message()));

            $status = $verdict->status() ?? 403;

            abort($status, $verdict->message() ?? ($status === 403 ? 'This account may not be signed in with a dev login.' : ''));
        }

        // the previous person's session (and its sign-in confirmation) must not carry over
        if ($guard->check()) {
            $guard->logout();
        }

        $request->session()->invalidate();

        // set right before login(): check() above may already fire a Login via the remember-me cookie
        Context::add(DevLogin::CONTEXT_KEY, true);

        $guard->login($authenticatable);
        $request->session()->regenerate();

        $events->dispatch(new DevLoginSucceeded($authenticatable, $guardName));

        return redirect()->to($redirects->resolve($request));
    }

    /**
     * The {user} value as it is in the URL, whatever the app's Route::model() / Route::bind() made of the parameter.
     */
    private function rawUser(Request $request): string
    {
        $raw = $request->route()?->originalParameter('user');

        return is_string($raw) ? $raw : abort(404);
    }

    private function abortUnlessIpAllowed(Request $request): void
    {
        $allowed = array_values(array_filter(
            config()->array('dev-login.allowed_ips', []),
            is_string(...),
        ));

        if ($allowed === []) {
            return;
        }

        abort_unless(IpUtils::checkIp((string) $request->ip(), $allowed), 404);
    }

    private function isPrefetch(Request $request): bool
    {
        return str_contains(strtolower((string) $request->headers->get('Sec-Purpose')), 'prefetch')
            || strtolower((string) $request->headers->get('Purpose')) === 'prefetch';
    }

    /**
     * Fail closed: only true or an allowing Response lets the user in.
     */
    private function authorize(Authenticatable $user, Request $request): AccessResponse
    {
        $class = config('dev-login.authorize');

        if (! is_string($class) || $class === '') {
            return AccessResponse::allow();
        }

        $hook = app($class);

        if (! is_callable($hook)) {
            throw new UnexpectedValueException("The dev-login.authorize class [{$class}] must be invokable.");
        }

        $result = $hook($user, $request);

        if ($result instanceof AccessResponse) {
            return $result;
        }

        return $result === true ? AccessResponse::allow() : AccessResponse::deny();
    }
}
