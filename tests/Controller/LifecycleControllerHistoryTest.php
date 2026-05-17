<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for the lifecycle history endpoint (X.3).
 */
final class LifecycleControllerHistoryTest extends WebTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function historyRouteIsRegistered(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get('router');

        $this->assertNotNull(
            $router->getRouteCollection()->get('app_lifecycle_history'),
            'Route app_lifecycle_history must be registered.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function historyRouteReturns403ForUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/lifecycle/document/1/history');
        // Firewall redirects to login (302) or returns 403 — both indicate auth required.
        $this->assertContains(
            $client->getResponse()->getStatusCode(),
            [302, 403],
            'Unauthenticated access to history endpoint must be refused (302 or 403).'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function historyRouteFor404UnknownType(): void
    {
        $client = static::createClient();
        // Use unknown entity type — expects 302 (login redirect), 403, or 404.
        $client->request('GET', '/lifecycle/nonexistent-type/1/history');
        $this->assertContains(
            $client->getResponse()->getStatusCode(),
            [302, 403, 404],
        );
    }
}
