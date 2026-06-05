<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ApiToken;
use App\EventSubscriber\ReadOnlyApiSubscriber;
use App\Repository\ApiTokenRepository;
use App\Security\ApiTokenAuthenticator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * F6 — token validity + read-only enforcement (DB-free).
 */
#[AllowMockObjectsWithoutExpectations]
final class ApiTokenReadOnlyTest extends TestCase
{
    private function plainHash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    // ── Entity validity ───────────────────────────────────────────────────────

    #[Test]
    public function tokenValidityReflectsRevokeAndExpiry(): void
    {
        $now = new DateTimeImmutable('2026-01-01');

        $valid = (new ApiToken());
        self::assertTrue($valid->isValid($now));

        $revoked = (new ApiToken())->setRevoked(true);
        self::assertFalse($revoked->isValid($now));

        $expired = (new ApiToken())->setExpiresAt(new DateTimeImmutable('2025-01-01'));
        self::assertFalse($expired->isValid($now));

        $future = (new ApiToken())->setExpiresAt(new DateTimeImmutable('2027-01-01'));
        self::assertTrue($future->isValid($now));
    }

    // ── Authenticator support gate ────────────────────────────────────────────

    #[Test]
    public function authenticatorSupportsOnlyBearerRequests(): void
    {
        $auth = new ApiTokenAuthenticator(
            $this->createMock(ApiTokenRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $bearer = new Request();
        $bearer->headers->set('Authorization', 'Bearer abc');
        self::assertTrue($auth->supports($bearer));

        self::assertFalse((bool) $auth->supports(new Request()));

        $basic = new Request();
        $basic->headers->set('Authorization', 'Basic xyz');
        self::assertFalse((bool) $auth->supports($basic));
    }

    // ── Read-only subscriber ──────────────────────────────────────────────────

    private function event(string $method, ?string $auth, string $path = '/api/risks'): RequestEvent
    {
        $request = Request::create($path, $method);
        if ($auth !== null) {
            $request->headers->set('Authorization', $auth);
        }

        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function subscriberWithValidToken(): ReadOnlyApiSubscriber
    {
        $repo = $this->createMock(ApiTokenRepository::class);
        $repo->method('findOneByHash')->willReturn(new ApiToken()); // valid (not revoked, no expiry)

        return new ReadOnlyApiSubscriber($repo);
    }

    #[Test]
    public function blocksWriteVerbWithValidToken(): void
    {
        $event = $this->event('POST', 'Bearer goodtoken');
        $this->subscriberWithValidToken()->onRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(405, $event->getResponse()?->getStatusCode());
    }

    #[Test]
    public function allowsGetWithValidToken(): void
    {
        $event = $this->event('GET', 'Bearer goodtoken');
        $this->subscriberWithValidToken()->onRequest($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function ignoresNonApiPaths(): void
    {
        $event = $this->event('POST', 'Bearer goodtoken', '/dashboard');
        $this->subscriberWithValidToken()->onRequest($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function ignoresNonBearerRequests(): void
    {
        $repo = $this->createMock(ApiTokenRepository::class);
        $subscriber = new ReadOnlyApiSubscriber($repo);

        $event = $this->event('POST', null);
        $subscriber->onRequest($event);

        self::assertNull($event->getResponse()); // session-auth POST untouched
    }

    #[Test]
    public function doesNotBlockWhenTokenUnknown(): void
    {
        $repo = $this->createMock(ApiTokenRepository::class);
        $repo->method('findOneByHash')->willReturn(null);

        $event = $this->event('POST', 'Bearer bogus');
        (new ReadOnlyApiSubscriber($repo))->onRequest($event);

        // Unknown token → leave it to the authenticator's 401, don't 405.
        self::assertNull($event->getResponse());
    }
}
