<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Repository\ApiTokenRepository;
use App\Security\ApiTokenAuthenticator;
use App\Security\TooManyTokenFailuresException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * W3: failed bearer attempts are counted per client. A failed attempt never
 * reaches the general API limiter (the firewall answers 401 before it), so the
 * authenticator counts them itself; after the limit the token lookup is not
 * tried any more (429).
 */
#[AllowMockObjectsWithoutExpectations]
final class ApiTokenFailureThrottleTest extends TestCase
{
    #[Test]
    public function guessingTokensEndsInTooManyRequests(): void
    {
        $limiter = new RateLimiterFactory(['id' => 'api_auth_failure', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '10 minutes'], new InMemoryStorage());
        $repo = $this->createMock(ApiTokenRepository::class);
        $repo->expects(self::exactly(3))->method('findOneByHash')->willReturn(null);
        $auth = new ApiTokenAuthenticator($repo, $this->createMock(EntityManagerInterface::class), $limiter);

        for ($i = 0; $i < 3; ++$i) {
            $request = $this->bearer('guess' . $i);
            try {
                $auth->authenticate($request);
                self::fail('A wrong token was accepted.');
            } catch (AuthenticationException $e) {
                self::assertSame(401, $auth->onAuthenticationFailure($request, $e)->getStatusCode());
            }
        }

        $request = $this->bearer('guess-again');
        try {
            $auth->authenticate($request);
            self::fail('The fourth guess reached the lookup.');
        } catch (TooManyTokenFailuresException $e) {
            self::assertSame(429, $auth->onAuthenticationFailure($request, $e)->getStatusCode());
        }
    }

    private function bearer(string $token): Request
    {
        $request = Request::create('/api/risks', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }
}
