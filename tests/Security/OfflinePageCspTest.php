<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The service worker serves public/offline.html from its cache. A cached
 * copy can only carry a stale per-request CSP nonce, so the offline page must
 * work under the app's real policy WITHOUT any nonce: no inline script, no
 * inline event handler, no inline style — only same-origin external files,
 * which the service worker caches together with the page (#1059).
 */
final class OfflinePageCspTest extends WebTestCase
{
    #[Test]
    public function offlinePageNeedsNoNonceUnderTheAppsContentSecurityPolicy(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/login');
        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        $directives = $this->directives($csp);
        self::assertArrayHasKey('script-src', $directives, 'the app sends a CSP with script-src');
        self::assertNotContains("'unsafe-inline'", $directives['script-src'], 'inline scripts need the per-request nonce');
        self::assertContains("'self'", $directives['script-src']);
        self::assertContains("'self'", $directives['style-src'] ?? $directives['default-src']);

        $public = \dirname(__DIR__, 2) . '/public';
        $html = (string) file_get_contents($public . '/offline.html');
        $sw = (string) file_get_contents($public . '/sw.js');

        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html, 'no inline <script>');
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, 'no inline event handler');
        self::assertDoesNotMatchRegularExpression('/<style[\s>]/i', $html, 'no inline <style>');
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, 'no style attribute');
        self::assertStringNotContainsString('nonce=', $html);

        preg_match_all('/<(?:script[^>]*\ssrc|link[^>]*rel="stylesheet"[^>]*\shref)="([^"]+)"/i', $html, $m);
        self::assertNotEmpty($m[1], 'the page loads its script and stylesheet as files');
        foreach ($m[1] as $asset) {
            self::assertMatchesRegularExpression('#^/[^/]#', $asset, sprintf('%s is same-origin (\'self\')', $asset));
            self::assertFileExists($public . $asset);
            self::assertStringContainsString(sprintf("'%s'", $asset), $sw, sprintf('sw.js caches %s with the offline page', $asset));
        }
        self::assertStringContainsString("'/offline.html'", $sw);
    }

    /**
     * @return array<string, list<string>>
     */
    private function directives(string $csp): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $csp))) as $directive) {
            $parts = preg_split('/\s+/', $directive) ?: [];
            $name = strtolower((string) array_shift($parts));
            $out[$name] = array_values($parts);
        }

        return $out;
    }
}
