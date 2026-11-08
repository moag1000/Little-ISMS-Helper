<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ApiToken;
use App\EventSubscriber\ReadOnlyApiSubscriber;
use App\Repository\ApiTokenRepository;
use App\Entity\User;
use App\Security\ApiTokenAuthenticator;
use App\Security\Token\ApiTokenRequest;
use App\Security\Token\RequestScopedSession;
use App\Security\UserChecker;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\DisabledException;

/**
 * F6 — token validity + read-only enforcement (DB-free); S1 (wave 5) — token
 * only on /api and /mcp, request-scoped session, account status check.
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

    #[Test]
    public function authenticatorRefusesATokenOutsideApiAndMcp(): void
    {
        $repo = $this->createMock(ApiTokenRepository::class);
        $repo->expects(self::never())->method('findOneByHash');
        $auth = new ApiTokenAuthenticator($repo, $this->createMock(EntityManagerInterface::class));

        foreach (['/de/risk/1/edit', '/dashboard', '/apix', '/mcp/other', '/de/api/heat-map'] as $path) {
            $request = Request::create($path, 'POST');
            $request->headers->set('Authorization', 'Bearer goodtoken');
            try {
                $auth->authenticate($request);
                self::fail('Token accepted on ' . $path);
            } catch (CustomUserMessageAuthenticationException $e) {
                self::assertStringContainsString('/api and /mcp', $e->getMessageKey(), $path);
            }
        }
    }

    #[Test]
    public function tokenPathsAreTheApiAndTheAssistantEndpoint(): void
    {
        foreach (['/api', '/api/risks', '/api/risks/1', '/api.jsonld', '/mcp'] as $path) {
            self::assertTrue(ApiTokenRequest::acceptsTokenOn($path), $path);
        }
        foreach (['/', '/apix', '/mcpx', '/mcp/', '/de/api/heat-map', '/de/risk', '/profile/api-tokens'] as $path) {
            self::assertFalse(ApiTokenRequest::acceptsTokenOn($path), $path);
        }
    }

    #[Test]
    public function aTokenRequestGetsASessionThatIsNeverStored(): void
    {
        $session = new RequestScopedSession();
        $session->set('_locale', 'de');
        $session->getFlashBag()->add('success', 'x');

        self::assertSame('de', $session->get('_locale'));
        self::assertFalse($session->isStarted(), 'Never started: the session listener saves nothing and sets no cookie.');
        self::assertSame('', $session->getId());
    }

    #[Test]
    public function userCheckerRefusesADeactivatedAccount(): void
    {
        $checker = new UserChecker();
        $checker->checkPreAuth((new User())->setIsActive(true));

        $this->expectException(DisabledException::class);
        $checker->checkPreAuth((new User())->setIsActive(false));
    }

    #[Test]
    public function userCheckerRefusesADeactivatedAccountOnImpersonationToo(): void
    {
        $this->expectException(DisabledException::class);
        (new UserChecker())->checkPostAuth((new User())->setIsActive(false));
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

    /**
     * Before wave 5 (S1) this test pinned the gap: a read-only token posting
     * a web form outside /api was let through. Now a token writes nowhere.
     */
    #[Test]
    public function blocksWriteVerbOutsideTheApiToo(): void
    {
        foreach (['/de/risk/1/edit', '/dashboard', '/profile/api-tokens/create', '/api'] as $path) {
            $event = $this->event('POST', 'Bearer goodtoken', $path);
            $this->subscriberWithValidToken()->onRequest($event);

            self::assertSame(405, $event->getResponse()?->getStatusCode(), $path);
        }
    }

    #[Test]
    public function leavesTheAssistantEndpointToMcpWriteAccess(): void
    {
        $event = $this->event('POST', 'Bearer goodtoken', '/mcp');
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
