<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\PublicPathRateLimitSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * S25: every anonymous path is throttled per client IP, each kind with its own budget,
 * logged-in pages are not touched.
 */
final class PublicPathRateLimitSubscriberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function paths(): iterable
    {
        yield 'trust center' => ['/trust/abc', 'token'];
        yield 'questionnaire' => ['/supplier-questionnaire/abc', 'token'];
        yield 'readiness' => ['/readyz', 'probe'];
        yield 'health' => ['/health', 'probe'];
        yield 'metrics' => ['/metrics', 'probe'];
        yield 'oidc start' => ['/sso/okta/start', 'sso'];
        yield 'saml acs' => ['/de/saml/acs', 'sso'];
        yield 'azure oauth' => ['/en/oauth/azure/check', 'sso'];
        yield 'quick-fix' => ['/quick-fix', 'quick_fix'];
        yield 'quick-fix job' => ['/quick-fix/jobs/x/status', 'quick_fix'];
        yield 'dashboard' => ['/de/dashboard', null];
        yield 'health lookalike' => ['/healthcheck', null];
        yield 'api' => ['/api/risks', null];
    }

    #[Test]
    #[DataProvider('paths')]
    public function pathMapsToItsLimiter(string $path, ?string $limiter): void
    {
        self::assertSame($limiter, PublicPathRateLimitSubscriber::limiterFor($path));
    }

    #[Test]
    public function exhaustedBudgetAnswers429PerKind(): void
    {
        $subscriber = new PublicPathRateLimitSubscriber(
            $this->factory('t', 5),
            $this->factory('p', 2),
            $this->factory('s', 5),
            $this->factory('q', 5),
        );

        self::assertNull($this->hit($subscriber, '/readyz'));
        self::assertNull($this->hit($subscriber, '/health'));
        self::assertSame(429, $this->hit($subscriber, '/metrics'));
        // another kind has its own budget, another address too
        self::assertNull($this->hit($subscriber, '/trust/abc'));
        self::assertNull($this->hit($subscriber, '/readyz', '10.0.0.2'));
        // pages behind the login are not counted here
        self::assertNull($this->hit($subscriber, '/de/dashboard'));
    }

    private function factory(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
    }

    private function hit(PublicPathRateLimitSubscriber $subscriber, string $path, string $ip = '10.0.0.1'): ?int
    {
        $request = Request::create($path, 'GET', server: ['REMOTE_ADDR' => $ip]);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);

        return $event->getResponse()?->getStatusCode();
    }
}
