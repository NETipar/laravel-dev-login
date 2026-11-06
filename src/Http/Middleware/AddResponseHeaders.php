<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First in the route's middleware list, so every response carries the headers, including aborts
 * and a throttle 429 (rendered exceptions pass back through the route middleware).
 */
final class AddResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
