<?php

declare(strict_types=1);

return [
    // The only switch that opens the route: off (the default), the route does not exist.
    // APP_ENV is deliberately not used to open it (an unedited .env.example often says APP_ENV=local).
    'enabled' => (bool) env('DEV_LOGIN_ENABLED', false),

    // APP_ENV may only narrow: while true, the route is never registered in the production environment.
    'block_in_production' => true,

    // Empty: no filter. Otherwise IPs or CIDR ranges (checked against $request->ip(), so behind
    // a proxy TrustProxies must trust only your actual proxy: trusting '*' on a directly reachable
    // host lets X-Forwarded-For be forged). A request from elsewhere gets a 404.
    'allowed_ips' => [],

    // The path must contain the {user} parameter.
    'path' => 'dev/login/{user}',
    'route_name' => 'dev.login',
    // throttle: cheap protection on a shared dev box; agents switching users stay well below it.
    'middleware' => ['web', 'throttle:60,1'],
    'domain' => null,

    // Refuse a navigation started from another site (Sec-Fetch-Site: cross-site) — login CSRF over GET.
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
