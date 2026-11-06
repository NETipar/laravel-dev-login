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
use NETipar\DevLogin\Support\LoginProfile;
use NETipar\DevLogin\Support\LoginProfiles;
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
        LoginProfiles $profiles,
        UserResolver $users,
        RedirectTarget $redirects,
        Dispatcher $events,
    ): Response|RedirectResponse {
        $profile = $this->profile($request, $profiles);

        // defense in depth: a stale route cache can keep the route alive while the profile is closed
        abort_unless($profile->open(), 404);

        $this->abortUnlessIpAllowed($request, $profiles, $profile);

        if ($this->isPrefetch($request)) {
            return response()->noContent();
        }

        if ($profile->rejectCrossSite && $request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            abort(403, 'A dev login cannot be started from another site.');
        }

        // guard and provider are looked up only now: a tenant middleware may have swapped them
        $guardName = $users->guardName($profile);
        $guard = $users->guard($guardName);

        $authenticatable = $users->find($profile, $guard, $this->rawUser($request)) ?? abort(404);

        $verdict = $this->authorize($profile, $authenticatable, $request);

        if ($verdict->denied()) {
            $events->dispatch(new DevLoginRefused($authenticatable, $guardName, $verdict->message(), $profile->name));

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

        $events->dispatch(new DevLoginSucceeded($authenticatable, $guardName, $profile->name));

        return redirect()->to($redirects->resolve($request, $profile));
    }

    /**
     * The profile the route was registered for; a profile that is gone from config answers 404.
     */
    private function profile(Request $request, LoginProfiles $profiles): LoginProfile
    {
        $name = $request->route()?->getAction('dev_login');

        return is_string($name) ? ($profiles->find($name) ?? abort(404)) : abort(404);
    }

    /**
     * The {user} value as it is in the URL, whatever the app's Route::model() / Route::bind() made of the parameter.
     */
    private function rawUser(Request $request): string
    {
        $raw = $request->route()?->originalParameter('user');

        return is_string($raw) ? $raw : abort(404);
    }

    /**
     * The global list and the profile's own list must both let the IP through.
     */
    private function abortUnlessIpAllowed(Request $request, LoginProfiles $profiles, LoginProfile $profile): void
    {
        foreach ([$profiles->globalAllowedIps(), $profile->allowedIps] as $allowed) {
            if ($allowed !== []) {
                abort_unless(IpUtils::checkIp((string) $request->ip(), $allowed), 404);
            }
        }
    }

    private function isPrefetch(Request $request): bool
    {
        return str_contains(strtolower((string) $request->headers->get('Sec-Purpose')), 'prefetch')
            || strtolower((string) $request->headers->get('Purpose')) === 'prefetch';
    }

    /**
     * Fail closed: only true or an allowing Response lets the user in.
     */
    private function authorize(LoginProfile $profile, Authenticatable $user, Request $request): AccessResponse
    {
        $class = $profile->authorize;

        if ($class === null) {
            return AccessResponse::allow();
        }

        $hook = app($class);

        if (! is_callable($hook)) {
            throw new UnexpectedValueException("The dev login authorize class [{$class}] must be invokable.");
        }

        $result = $hook($user, $request);

        if ($result instanceof AccessResponse) {
            return $result;
        }

        return $result === true ? AccessResponse::allow() : AccessResponse::deny();
    }
}
