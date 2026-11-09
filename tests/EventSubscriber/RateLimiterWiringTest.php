<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Controller\DocumentController;
use App\Controller\Security\ReauthController;
use App\EventSubscriber\ApiRateLimitSubscriber;
use App\EventSubscriber\PublicPathRateLimitSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Each consumer gets the limiter named in rate_limiter.yaml. A generic
 * `$rateLimiterFactory` alias to limiter.login once gave the whole REST API and
 * document uploads the login budget (5 requests per 15 minutes per IP).
 */
final class RateLimiterWiringTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{0: class-string, 1: string, 2: int, 3?: string}>
     */
    public static function consumers(): iterable
    {
        yield 'api' => [ApiRateLimitSubscriber::class, 'apiLimiter', 100];
        yield 'document upload' => [DocumentController::class, 'documentUploadLimiter', 20];
        yield 'reauth' => [ReauthController::class, 'reauthLimiter', 5];
        yield 'public token urls' => [PublicPathRateLimitSubscriber::class, 'limiters', 60, 'token'];
        // test env raises the other public limits (functional tests from 127.0.0.1)
        yield 'public probes' => [PublicPathRateLimitSubscriber::class, 'limiters', 100000, 'probe'];
        yield 'public sso' => [PublicPathRateLimitSubscriber::class, 'limiters', 100000, 'sso'];
        yield 'public quick-fix' => [PublicPathRateLimitSubscriber::class, 'limiters', 100000, 'quick_fix'];
        // test env raises the limit (the suite fails many tokens from one IP); the throttle has its own test
        yield 'failed bearer attempts' => [\App\Security\ApiTokenAuthenticator::class, 'apiAuthFailureLimiter', 100000];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('consumers')]
    public function consumerGetsItsOwnLimiter(string $class, string $property, int $limit, ?string $key = null): void
    {
        self::bootKernel();
        $service = self::getContainer()->get($class);

        $factory = (new ReflectionProperty($class, $property))->getValue($service);
        if ($key !== null) {
            $factory = $factory[$key];
        }
        self::assertInstanceOf(RateLimiterFactory::class, $factory);

        $rateLimit = $factory->create('wiring-test-' . bin2hex(random_bytes(4)))->consume(0);
        self::assertSame($limit, $rateLimit->getLimit(), sprintf('%s::$%s is wired to the wrong limiter.', $class, $property));
    }
}
