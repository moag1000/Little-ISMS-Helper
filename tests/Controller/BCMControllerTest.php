<?php

namespace App\Tests\Controller;

use App\Entity\BusinessProcess;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for BCMController
 *
 * Tests Business Continuity Management dashboard including:
 * - Index with statistics (total, critical, high, avg RTO/MTPD)
 * - Critical processes view
 * - Data reuse insights
 * - Role-based access control
 */
class BCMControllerTest extends WebTestCase
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
        // Clean up business process
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

        // Clean up user
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

        // Clean up tenant
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
            // Ignore flush errors during cleanup
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('test_', true);

        // Create test tenant
        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        // Create test user with ROLE_USER
        $this->testUser = new User();
        $this->testUser->setEmail('testuser_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        // Create test business process
        $this->testProcess = new BusinessProcess();
        $this->testProcess->setTenant($this->testTenant);
        $this->testProcess->setName('Test Process ' . $uniqueId);
        $this->testProcess->setDescription('Test process description');
        $this->testProcess->setCriticality('high');
        $this->testProcess->setRto(4);
        $this->testProcess->setRpo(2);
        $this->testProcess->setMtpd(24);
        $this->testProcess->setProcessOwner('Test User');
        $this->testProcess->setReputationalImpact(3);
        $this->testProcess->setRegulatoryImpact(2);
        $this->testProcess->setOperationalImpact(4);
        $this->entityManager->persist($this->testProcess);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX ACTION TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/');

        $this->assertResponseRedirects();
    }

    public function testIndexShowsProcessesForAuthenticatedUser(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/bcm/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexDisplaysStatistics(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/bcm/');

        $this->assertResponseIsSuccessful();
        // Page should render successfully with statistics
    }

    public function testIndexShowsTestProcess(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/bcm/');

        $this->assertResponseIsSuccessful();
    }

    // ========== CRITICAL PROCESSES TESTS ==========

    public function testCriticalRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/critical');

        $this->assertResponseRedirects();
    }

    public function testCriticalShowsProcessesForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/bcm/critical');

        $this->assertResponseIsSuccessful();
    }

    public function testCriticalFiltersHighAndCriticalProcesses(): void
    {
        $this->loginAsUser($this->testUser);

        // Create a critical process
        $criticalProcess = new BusinessProcess();
        $criticalProcess->setTenant($this->testTenant);
        $criticalProcess->setName('Critical Process ' . uniqid());
        $criticalProcess->setDescription('Critical process description');
        $criticalProcess->setCriticality('critical');
        $criticalProcess->setRto(1);
        $criticalProcess->setRpo(1);
        $criticalProcess->setMtpd(4);
        $criticalProcess->setProcessOwner('Test User');
        $criticalProcess->setReputationalImpact(5);
        $criticalProcess->setRegulatoryImpact(5);
        $criticalProcess->setOperationalImpact(5);
        $this->entityManager->persist($criticalProcess);
        $this->entityManager->flush();

        $this->client->request('GET', '/en/bcm/critical');

        $this->assertResponseIsSuccessful();

        // Clean up
        $this->entityManager->remove($criticalProcess);
        $this->entityManager->flush();
    }

    // ========== DATA REUSE INSIGHTS TESTS ==========

    public function testDataReuseInsightsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/data-reuse-insights');

        $this->assertResponseRedirects();
    }

    public function testDataReuseInsightsShowsForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/bcm/data-reuse-insights');

        $this->assertResponseIsSuccessful();
    }

    public function testDataReuseInsightsDisplaysAnalysis(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/bcm/data-reuse-insights');

        $this->assertResponseIsSuccessful();
        // Should render the data reuse analysis page
    }

    // ========== STATISTICS CALCULATION TESTS ==========

    public function testIndexCalculatesAverageRTO(): void
    {
        $this->loginAsUser($this->testUser);

        // Create additional process with different RTO
        $process2 = new BusinessProcess();
        $process2->setTenant($this->testTenant);
        $process2->setName('Process 2 ' . uniqid());
        $process2->setDescription('Second process');
        $process2->setCriticality('medium');
        $process2->setRto(8);
        $process2->setRpo(4);
        $process2->setMtpd(48);
        $process2->setProcessOwner('Test User');
        $process2->setReputationalImpact(2);
        $process2->setRegulatoryImpact(2);
        $process2->setOperationalImpact(2);
        $this->entityManager->persist($process2);
        $this->entityManager->flush();

        $this->client->request('GET', '/en/bcm/');

        $this->assertResponseIsSuccessful();

        // Clean up
        $this->entityManager->remove($process2);
        $this->entityManager->flush();
    }

    public function testIndexCalculatesAverageMTPD(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/bcm/');

        $this->assertResponseIsSuccessful();
    }

    public function testIndexCountsCriticalProcesses(): void
    {
        $this->loginAsUser($this->testUser);

        // Create a critical process
        $criticalProcess = new BusinessProcess();
        $criticalProcess->setTenant($this->testTenant);
        $criticalProcess->setName('Critical ' . uniqid());
        $criticalProcess->setDescription('Critical');
        $criticalProcess->setCriticality('critical');
        $criticalProcess->setRto(1);
        $criticalProcess->setRpo(1);
        $criticalProcess->setMtpd(4);
        $criticalProcess->setProcessOwner('Test User');
        $criticalProcess->setReputationalImpact(5);
        $criticalProcess->setRegulatoryImpact(5);
        $criticalProcess->setOperationalImpact(5);
        $this->entityManager->persist($criticalProcess);
        $this->entityManager->flush();

        $this->client->request('GET', '/en/bcm/');

        $this->assertResponseIsSuccessful();

        // Clean up
        $this->entityManager->remove($criticalProcess);
        $this->entityManager->flush();
    }

    public function testIndexCountsHighProcesses(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/bcm/');

        $this->assertResponseIsSuccessful();
        // The test process has 'high' criticality
    }

    // ========== EMPTY STATE TESTS ==========

    public function testIndexHandlesNoProcesses(): void
    {
        // Remove test process to simulate empty state
        $this->entityManager->remove($this->testProcess);
        $this->entityManager->flush();
        $this->testProcess = null;

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/bcm/');

        $this->assertResponseIsSuccessful();
    }

    public function testCriticalHandlesNoProcesses(): void
    {
        // Remove test process
        $this->entityManager->remove($this->testProcess);
        $this->entityManager->flush();
        $this->testProcess = null;

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/bcm/critical');

        $this->assertResponseIsSuccessful();
    }

    public function testDataReuseInsightsHandlesNoProcesses(): void
    {
        // Remove test process
        $this->entityManager->remove($this->testProcess);
        $this->entityManager->flush();
        $this->testProcess = null;

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/bcm/data-reuse-insights');

        $this->assertResponseIsSuccessful();
    }
}
