<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ThreatIntelligenceControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    #[Test]
    public function testIndexPageReachable(): void
    {
        $this->client->request('GET', '/de/threat-intelligence/');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [200, 302]);
    }

    #[Test]
    public function testNewPageReachable(): void
    {
        $this->client->request('GET', '/de/threat-intelligence/new');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [200, 302, 403]);
    }

    #[Test]
    public function testShowPageRedirectsOrNotFound(): void
    {
        $this->client->request('GET', '/de/threat-intelligence/9999999');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [200, 302, 404]);
    }

    #[Test]
    public function testEditPageRedirectsOrForbidden(): void
    {
        $this->client->request('GET', '/de/threat-intelligence/9999999/edit');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [200, 302, 403, 404]);
    }
}
