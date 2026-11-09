<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\ShareController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S16: a URL shared into the app (PWA share target) becomes a link on the share page
 * and text in a new record. Only http(s) survives; `javascript:` would run on click.
 */
final class ShareTargetUrlTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function urls(): iterable
    {
        yield 'https' => ['https://example.org/a?b=c', 'https://example.org/a?b=c'];
        yield 'http' => ['http://example.org', 'http://example.org'];
        yield 'javascript' => ['javascript:alert(document.cookie)', ''];
        yield 'mixed case javascript' => ['JaVaScRiPt:alert(1)', ''];
        yield 'data' => ['data:text/html;base64,PHNjcmlwdD4=', ''];
        yield 'protocol relative' => ['//attacker.example', ''];
        yield 'with whitespace inside' => ["https://example.org/\njavascript:x", ''];
    }

    #[Test]
    #[DataProvider('urls')]
    public function onlyWebUrlsAreKept(string $shared, string $expected): void
    {
        self::assertSame($expected, ShareController::webUrlOrEmpty($shared));
    }
}
