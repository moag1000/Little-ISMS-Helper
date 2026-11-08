<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Which push endpoints a browser may register (Web Push, RFC 8030).
 *
 * The endpoint comes from the browser's PushManager, but the subscribe call is
 * plain JSON: anyone signed in could post any URL, and the server would POST an
 * encrypted payload there on every notification. Only the push services of the
 * browsers ship endpoints, so only https URLs on their hosts are accepted. The
 * delivery itself still goes through {@see UserUrlHttpClient} (no private
 * addresses, no redirects).
 */
final class PushEndpointPolicy
{
    /** Host suffixes of the browser push services (Chrome/Edge/Opera, Firefox, Windows, Safari). */
    public const array HOST_SUFFIXES = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'push.services.mozilla.com',
        'notify.windows.com',
        'push.apple.com',
    ];

    public const int MAX_LENGTH = 1024;

    public static function accepts(string $endpoint): bool
    {
        if ($endpoint === '' || strlen($endpoint) > self::MAX_LENGTH) {
            return false;
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }
}
