<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('loads the published config without the package: no autoloader, no package class', function (): void {
    $path = dirname(__DIR__, 2).'/config/dev-login.php';

    $process = new Process([
        PHP_BINARY,
        '-r',
        'function env($key, $default = null) { return $default; } $config = require $argv[1]; echo is_array($config) ? "LOADED" : "NOT AN ARRAY";',
        '--',
        $path,
    ]);
    $process->mustRun();

    expect($process->getOutput())->toContain('LOADED')
        ->and((string) file_get_contents($path))->not->toContain('NETipar\\');
});
