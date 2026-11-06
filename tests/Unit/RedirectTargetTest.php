<?php

declare(strict_types=1);

use NETipar\DevLogin\Support\RedirectTarget;

it('accepts only same-host relative paths', function (string $path, bool $safe): void {
    expect((new RedirectTarget)->isSafeRelativePath($path))->toBe($safe);
})->with([
    'plain path' => ['/servers/12', true],
    'path with query' => ['/servers?page=2#top', true],
    'root' => ['/', true],
    'absolute url' => ['https://evil.test', false],
    'protocol-relative' => ['//evil.test', false],
    'backslash after slash' => ['/\\evil.test', false],
    'backslash later' => ['/a\\b', false],
    'tab smuggled between slashes' => ["/\t/evil.test", false],
    'newline' => ["/a\nb", false],
    'no leading slash' => ['evil.test', false],
    'javascript scheme' => ['javascript:alert(1)', false],
    'empty' => ['', false],
]);
