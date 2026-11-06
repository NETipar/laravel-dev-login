<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Tests\Support;

use Illuminate\Support\Facades\DB;

final class ActivateAcmeTenant
{
    public function __invoke(string $tenant): string
    {
        DB::setDefaultConnection('tenant');
        app('auth')->forgetGuards();

        return "https://{$tenant}.example.test";
    }
}
