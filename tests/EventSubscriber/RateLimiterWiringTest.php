<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Controller\DocumentController;
use App\Controller\Security\ReauthController;
use App\EventSubscriber\ApiRateLimitSubscriber;
use App\EventSubscriber\PublicTokenRateLimitSubscriber;
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
     * @return iterable<string, array{class-string, string, int}>
     */
    public static function consumers(): iterable
    {
        yield 'api' => [ApiRateLimitSubscriber::class, 'apiLimiter', 100];
        yield 'document upload' => [DocumentController::class, 'documentUploadLimiter', 20];
        yield 'reauth' => [ReauthController::class, 'reauthLimiter', 5];
        yield 'public token urls' => [PublicTokenRateLimitSubscriber::class, 'publicTokenLimiter', 60];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('consumers')]
    public function consumerGetsItsOwnLimiter(string $class, string $property, int $limit): void
    {
        self::bootKernel();
        $service = self::getContainer()->get($class);

        $factory = (new ReflectionProperty($class, $property))->getValue($service);
        self::assertInstanceOf(RateLimiterFactory::class, $factory);

        $rateLimit = $factory->create('wiring-test-' . bin2hex(random_bytes(4)))->consume(0);
        self::assertSame($limit, $rateLimit->getLimit(), sprintf('%s::$%s is wired to the wrong limiter.', $class, $property));
    }
}
