<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands in for a tenant middleware: swaps the default connection and drops the cached guards.
 */
final class SwitchToTenantDatabase
{
    public function handle(Request $request, Closure $next): Response
    {
        DB::setDefaultConnection('tenant');
        app('auth')->forgetGuards();

        /** @var Response */
        return $next($request);
    }
}
