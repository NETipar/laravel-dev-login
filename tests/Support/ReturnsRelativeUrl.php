<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Tests\Support;

final class ReturnsRelativeUrl
{
    public function __invoke(string $tenant): string
    {
        return '/just/a/path';
    }
}
