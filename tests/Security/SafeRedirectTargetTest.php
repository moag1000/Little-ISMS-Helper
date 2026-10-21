<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SafeRedirectTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * #1059 m-4/m-7: one rule for post-login / re-auth redirect targets.
 */
final class SafeRedirectTargetTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function relativeTargets(): iterable
    {
        yield 'path' => ['/en/dashboard?tab=1', true];
        yield 'root' => ['/', true];
        yield 'empty' => ['', false];
        yield 'backslash host' => ['/\\evil.com', false];
        yield 'protocol-relative' => ['//evil.com', false];
        yield 'absolute' => ['https://evil.com/', false];
        yield 'scheme' => ['javascript:alert(1)', false];
        yield 'no slash' => ['en/dashboard', false];
        yield 'control char' => ["/\t/evil.com", false];
    }

    #[Test]
    #[DataProvider('relativeTargets')]
    public function relativeRule(string $target, bool $safe): void
    {
        self::assertSame($safe, SafeRedirectTarget::isSafe($target));
    }

    #[Test]
    public function absoluteSameOriginOnlyWithRequest(): void
    {
        $request = Request::create('https://isms.example/en/login');

        self::assertTrue(SafeRedirectTarget::isSafe('https://isms.example/en/risk', $request));
        self::assertFalse(SafeRedirectTarget::isSafe('https://isms.example/en/risk'), 'absolute needs the request');
        self::assertFalse(SafeRedirectTarget::isSafe('https://evil.example/en/risk', $request));
        self::assertFalse(SafeRedirectTarget::isSafe('http://isms.example/en/risk', $request), 'scheme differs');
        self::assertFalse(SafeRedirectTarget::isSafe('https://isms.example:8443/x', $request), 'port differs');
        self::assertFalse(SafeRedirectTarget::isSafe('https://user@isms.example/x', $request));
        self::assertFalse(SafeRedirectTarget::isSafe('/\\evil.com', $request));
    }
}
