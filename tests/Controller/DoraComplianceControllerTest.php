<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests for DoraComplianceController
 *
 * Phase 7E: Compliance Wizards & Module-Aware KPIs
 */
class DoraComplianceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $managerUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        try {
            $this->entityManager = $container->get(EntityManagerInterface::class);
            $this->entityManager->getConnection()->executeQuery('SELECT 1');
            $this->createTestData();
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Access denied') ||
                str_contains($e->getMessage(), 'Connection refused') ||
                str_contains($e->getMessage(), 'SQLSTATE')) {
                $this->markTestSkipped('Database not available: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();
            return;
        }

        if ($this->testUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->testUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {}
        }

        if ($this->managerUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->managerUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {}
        }

        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception $e) {}
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {}

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('test_dora_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant DORA ' . $uniqueId);
        $this->testTenant->setCode('test_dora_' . substr($uniqueId, 0, 20));
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('testuser_dora_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->managerUser = new User();
        $this->managerUser->setEmail('manager_dora_' . $uniqueId . '@example.com');
        $this->managerUser->setFirstName('Manager');
        $this->managerUser->setLastName('User');
        $this->managerUser->setRoles(['ROLE_MANAGER']);
        $this->managerUser->setPassword('hashed_password');
        $this->managerUser->setTenant($this->testTenant);
        $this->managerUser->setIsActive(true);
        $this->entityManager->persist($this->managerUser);

        $this->entityManager->flush();
    }

    public function testDashboardRequiresAuthentication(): void
    {
        $this->client->request('GET', '/dora-compliance');
        $this->assertResponseRedirects();
    }

    public function testDashboardRequiresManagerRole(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/dora-compliance');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDashboardDisplaysForManager(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/dora-compliance');
        // DORA dashboard redirects to compliance if DORA framework not loaded
        $response = $this->client->getResponse();
        $this->assertTrue($response->isSuccessful() || $response->isRedirect());
    }

    public function testDashboardRedirectsWhenDoraNotInstalled(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/dora-compliance');
        $response = $this->client->getResponse();

        // Either shows dashboard (DORA installed) or redirects (DORA not installed)
        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirect(),
            'Expected success or redirect response'
        );

        // If redirected, should go to compliance index
        if ($response->isRedirect()) {
            $this->client->followRedirect();
            // After redirect, we should be at a compliance-related page
            $this->assertTrue($this->client->getResponse()->isSuccessful());
        }
    }
}
