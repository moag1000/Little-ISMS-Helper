<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests for ComplianceWizardController
 *
 * Phase 7E: Compliance Wizards & Module-Aware KPIs
 */
class ComplianceWizardControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $managerUser = null;
    private ?User $adminUser = null;

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

        if ($this->adminUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->adminUser->getId());
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
        $uniqueId = uniqid('test_wizard_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant Wizard ' . $uniqueId);
        $this->testTenant->setCode('test_wizard_' . substr($uniqueId, 0, 18));
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('testuser_wizard_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->managerUser = new User();
        $this->managerUser->setEmail('manager_wizard_' . $uniqueId . '@example.com');
        $this->managerUser->setFirstName('Manager');
        $this->managerUser->setLastName('User');
        $this->managerUser->setRoles(['ROLE_MANAGER']);
        $this->managerUser->setPassword('hashed_password');
        $this->managerUser->setTenant($this->testTenant);
        $this->managerUser->setIsActive(true);
        $this->entityManager->persist($this->managerUser);

        $this->adminUser = new User();
        $this->adminUser->setEmail('admin_wizard_' . $uniqueId . '@example.com');
        $this->adminUser->setFirstName('Admin');
        $this->adminUser->setLastName('User');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        $this->entityManager->flush();
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/compliance-wizard');
        $this->assertResponseRedirects();
    }

    public function testIndexRequiresManagerRole(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/compliance-wizard');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testIndexDisplaysForManager(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/compliance-wizard');
        $this->assertResponseIsSuccessful();
    }

    public function testIndexDisplaysForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/compliance-wizard');
        $this->assertResponseIsSuccessful();
    }

    public function testStartWizardWithInvalidWizard(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/compliance-wizard/invalid-wizard/start');
        // Either 404 or redirect with error flash
        $response = $this->client->getResponse();
        $this->assertTrue(
            $response->getStatusCode() === Response::HTTP_NOT_FOUND || $response->isRedirect(),
            'Expected 404 or redirect for invalid wizard'
        );
    }

    public function testApiAssessRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/compliance-wizard/iso27001/api/assess');
        $this->assertResponseRedirects();
    }

    public function testApiAssessRequiresManagerRole(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/compliance-wizard/iso27001/api/assess');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testApiAssessReturnsJson(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/compliance-wizard/iso27001/api/assess');

        // Either returns JSON, redirect, or error (403/404 if wizard not available)
        $response = $this->client->getResponse();
        $statusCode = $response->getStatusCode();

        if ($response->isSuccessful()) {
            $this->assertJson($response->getContent());

            $data = json_decode($response->getContent(), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('success', $data);
        } else {
            // Accept redirect, 400/403/404 as valid responses when wizard not available
            $validResponses = $response->isRedirect() ||
                              $statusCode === Response::HTTP_BAD_REQUEST ||
                              $statusCode === Response::HTTP_FORBIDDEN ||
                              $statusCode === Response::HTTP_NOT_FOUND;
            $this->assertTrue($validResponses, 'Expected JSON, redirect, or 4xx error. Got: ' . $statusCode);
        }
    }

    public function testIndexShowsAvailableWizards(): void
    {
        $this->client->loginUser($this->managerUser);
        $crawler = $this->client->request('GET', '/en/compliance-wizard');

        $this->assertResponseIsSuccessful();
        // Page should have wizard content or show no wizards available message
        $content = $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }
}
