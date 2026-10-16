<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The liveness probe lives at the fixed path /health. Dockerfile's
 * HEALTHCHECK, docker-compose and SetupRequiredSubscriber all use that path,
 * while the route used to be registered as /{_locale}/health.
 */
final class HealthControllerTest extends WebTestCase
{
    #[Test]
    public function healthIsPublicAndLocaleFree(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame('OK', $client->getResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
    }

    #[Test]
    public function localePrefixedHealthNoLongerExists(): void
    {
        $client = self::createClient();
        $client->request('GET', '/de/health');

        self::assertNotSame(200, $client->getResponse()->getStatusCode());
    }
}
