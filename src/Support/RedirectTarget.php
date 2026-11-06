<?php

declare(strict_types=1);

namespace NETipar\DevLogin\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * @internal
 */
final class RedirectTarget
{
    /**
     * ?to= (only a same-host relative path), else the redirect config (route name or path), else fortify.home or '/'.
     */
    public function resolve(Request $request, LoginProfile $profile): string
    {
        if ($profile->redirectParameter !== null) {
            $to = $request->query($profile->redirectParameter);

            if (is_string($to) && $this->isSafeRelativePath($to)) {
                return $to;
            }
        }

        return $this->default($profile);
    }

    public function default(LoginProfile $profile): string
    {
        $configured = $profile->redirect;

        if ($configured !== null) {
            return Route::has($configured) ? route($configured) : $configured;
        }

        $home = config('fortify.home');

        return is_string($home) && $home !== '' ? $home : '/';
    }

    /**
     * Only "/path…": no scheme or host, no "//" (protocol-relative), no backslash (browsers read "/\" as "//"),
     * no control characters (browsers strip tabs and newlines, so "/<TAB>/host" would become "//host").
     */
    public function isSafeRelativePath(string $path): bool
    {
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1) {
            return false;
        }

        $parts = parse_url($path);

        return $parts !== false && ! isset($parts['scheme']) && ! isset($parts['host']);
    }
}
