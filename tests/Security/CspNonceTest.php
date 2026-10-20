<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\CspNonce;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CspNonceTest extends TestCase
{
    #[Test]
    public function nonceIsStableWithinARequestAndFreshPerRequest(): void
    {
        $stack = new RequestStack();
        $nonce = new CspNonce($stack);

        $stack->push(new Request());
        $first = $nonce->get();
        self::assertSame($first, $nonce->get());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $first);

        $stack->pop();
        $stack->push(new Request());
        self::assertNotSame($first, $nonce->get());
    }

    #[Test]
    public function turboFetchReusesAWellFormedDocumentNonce(): void
    {
        $request = new Request();
        $request->headers->set('X-Turbo-Request-Id', 'abc');
        $request->headers->set(CspNonce::TURBO_HEADER, 'Abcdefghijklmnopqrstuvwx');
        $stack = new RequestStack();
        $stack->push($request);

        self::assertSame('Abcdefghijklmnopqrstuvwx', (new CspNonce($stack))->get());
    }

    #[Test]
    public function headerIsIgnoredWithoutTurboOrWhenMalformed(): void
    {
        $plain = new Request();
        $plain->headers->set(CspNonce::TURBO_HEADER, 'Abcdefghijklmnopqrstuvwx');
        $stack = new RequestStack();
        $stack->push($plain);
        self::assertNotSame('Abcdefghijklmnopqrstuvwx', (new CspNonce($stack))->get());

        $evil = new Request();
        $evil->headers->set('X-Turbo-Request-Id', 'abc');
        $evil->headers->set(CspNonce::TURBO_HEADER, "abcdefghijklmnopqrstuvwxyz'; script-src *");
        $stack = new RequestStack();
        $stack->push($evil);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', (new CspNonce($stack))->get());
    }

    #[Test]
    public function withoutRequestAStableFallbackIsUsedUntilReset(): void
    {
        $nonce = new CspNonce(new RequestStack());
        $fallback = $nonce->get();
        self::assertSame($fallback, $nonce->get());

        // ResetInterface hook (kernel.reset between requests in long-running workers).
        \call_user_func([$nonce, 'reset']);
        self::assertNotSame($fallback, $nonce->get());
    }
}
