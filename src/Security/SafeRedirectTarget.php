<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * The one rule for post-login / post-re-auth redirect targets (#1059 m-4/m-7):
 * a same-origin relative path — exactly one leading slash, no `//` or `/\`
 * prefix (browsers treat both as protocol-relative), no control characters.
 * With $request, an absolute http(s) URL on the request's own scheme + host
 * (+ port) is accepted too: Symfony's TargetPathTrait stores the full URI of
 * the page that required the login.
 */
final class SafeRedirectTarget
{
    private function __construct()
    {
    }

    public static function isSafe(string $target, ?Request $request = null): bool
    {
        if ($target === '' || preg_match('/[\x00-\x1f\x7f]/', $target) === 1) {
            return false;
        }
        if ($target[0] === '/') {
            return !str_starts_with($target, '//') && !str_starts_with($target, '/\\');
        }
        if ($request === null || str_contains($target, '\\')) {
            return false;
        }
        $parts = parse_url($target);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && strtolower((string) ($parts['scheme'] ?? '')) === $request->getScheme()
            && strtolower((string) ($parts['host'] ?? '')) === strtolower($request->getHost())
            && (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80)) === $request->getPort();
    }
}
