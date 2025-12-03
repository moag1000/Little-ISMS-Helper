<?php

namespace App\Tests\Controller;

use App\Entity\BusinessProcess;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for BusinessProcessController
 *
 * Tests business process management including:
 * - Index with listing and filtering
 * - CRUD operations
 * - Stats API
 * - Business Impact Analysis (BIA) view
 */
class BusinessProcessControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?BusinessProcess $testProcess = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testProcess) {
            try {
                $process = $this->entityManager->find(BusinessProcess::class, $this->testProcess->getId());
                if ($process) {
                    $this->entityManager->remove($process);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->testUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->testUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Ignore
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('test_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('testuser_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->testProcess = new BusinessProcess();
        $this->testProcess->setTenant($this->testTenant);
        $this->testProcess->setName('Test Process ' . $uniqueId);
        $this->testProcess->setDescription('Test description');
        $this->testProcess->setCriticality('high');
        $this->testProcess->setRto(4);
        $this->testProcess->setRpo(1);
        $this->testProcess->setMtpd(24);
        $this->testProcess->setProcessOwner('Test Owner');
        $this->testProcess->setReputationalImpact(3);
        $this->testProcess->setRegulatoryImpact(2);
        $this->testProcess->setOperationalImpact(3);
        $this->entityManager->persist($this->testProcess);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process/');
        $this->assertResponseRedirects();
    }

    public function testIndexDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/');
        $this->assertResponseIsSuccessful();
    }

    public function testIndexAcceptsViewFilter(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/bcm/business-process/?view=own');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/en/bcm/business-process/?view=inherited');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/en/bcm/business-process/?view=subsidiaries');
        $this->assertResponseIsSuccessful();
    }

    // ========== NEW TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process/new');
        $this->assertResponseRedirects();
    }

    public function testNewDisplaysFormForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $crawler = $this->client->request('GET', '/en/bcm/business-process/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== SHOW TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId());
        $this->assertResponseRedirects();
    }

    public function testShowDisplaysProcessForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId());
        $this->assertResponseIsSuccessful();
    }

    public function testShowReturns404ForNonexistent(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/999999');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== EDIT TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId() . '/edit');
        $this->assertResponseRedirects();
    }

    public function testEditDisplaysFormForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $crawler = $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== DELETE TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/bcm/business-process/' . $this->testProcess->getId());
        $this->assertResponseRedirects();
    }

    public function testDeleteRequiresCsrfToken(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/bcm/business-process/' . $this->testProcess->getId());
        // Without valid CSRF token, should redirect without deleting
        $this->assertResponseRedirects();
    }

    // ========== STATS API TESTS ==========

    public function testStatsApiRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process/api/stats');
        $this->assertResponseRedirects();
    }

    public function testStatsApiReturnsJsonForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/api/stats');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testStatsApiContainsExpectedFields(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/api/stats');

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('total', $response);
        $this->assertArrayHasKey('by_criticality', $response);
        $this->assertArrayHasKey('avg_rto', $response);
        $this->assertArrayHasKey('avg_rpo', $response);
        $this->assertArrayHasKey('processes_with_high_risks', $response);
    }

    // ========== BIA TESTS ==========

    public function testBiaRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId() . '/bia');
        $this->assertResponseRedirects();
    }

    public function testBiaDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId() . '/bia');
        $this->assertResponseIsSuccessful();
    }

    public function testBiaReturns404ForNonexistent(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/999999/bia');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== EMPTY STATE TESTS ==========

    public function testIndexHandlesNoProcesses(): void
    {
        // Remove test process
        $this->entityManager->remove($this->testProcess);
        $this->entityManager->flush();
        $this->testProcess = null;

        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/');
        $this->assertResponseIsSuccessful();
    }
}
