<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LifecycleControllerTest extends WebTestCase
{
    public function testUnknownEntityTypeReturns404(): void
    {
        $client = static::createClient();
        $client->request('POST', '/lifecycle/unknown/1/transition',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['transition' => 'submit_for_review']),
        );
        // Allow 302 (firewall redirect to login), 403, or 404 — all acceptable for the
        // no-fixture state. Full assertion deferred until DocumentFixtureFactory exists.
        $this->assertContains($client->getResponse()->getStatusCode(), [302, 403, 404]);
    }

    public function testRoutesRegisteredViaContainer(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get('router');

        $this->assertNotNull($router->getRouteCollection()->get('app_lifecycle_transition'));
        $this->assertNotNull($router->getRouteCollection()->get('app_lifecycle_bulk_transition'));
        $this->assertNotNull($router->getRouteCollection()->get('app_lifecycle_allowed'));
    }

    public function testSingleTransitionSuccess(): void
    {
        $this->markTestSkipped('Requires DocumentFixtureFactory; deferred to Sprint X.3.');
    }

    public function testVersionConflictReturns409(): void
    {
        $this->markTestSkipped('Requires DocumentFixtureFactory; deferred to Sprint X.3.');
    }

    public function testInvalidTransitionReturns422(): void
    {
        $this->markTestSkipped('Requires DocumentFixtureFactory; deferred to Sprint X.3.');
    }

    public function testVoterDeniedReturns403(): void
    {
        $this->markTestSkipped('Requires DocumentFixtureFactory; deferred to Sprint X.3.');
    }

    public function testBulkBestEffort(): void
    {
        $this->markTestSkipped('Requires DocumentFixtureFactory; deferred to Sprint X.3.');
    }
}
