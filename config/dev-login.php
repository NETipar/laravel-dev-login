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
];
