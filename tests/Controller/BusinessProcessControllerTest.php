<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BusinessProcess;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;

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
    private ?User $testManagerUser = null;
    private ?User $testAdminUser = null;
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

        foreach ([$this->testUser, $this->testManagerUser, $this->testAdminUser] as $u) {
            if ($u) {
                try {
                    $found = $this->entityManager->find(User::class, $u->getId());
                    if ($found) {
                        $this->entityManager->remove($found);
                    }
                } catch (\Exception $e) {
                    // Ignore
                }
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

        $this->testManagerUser = new User();
        $this->testManagerUser->setEmail('testmanager_' . $uniqueId . '@example.com');
        $this->testManagerUser->setFirstName('Test');
        $this->testManagerUser->setLastName('Manager');
        $this->testManagerUser->setRoles(['ROLE_MANAGER']);
        $this->testManagerUser->setPassword('hashed_password');
        $this->testManagerUser->setTenant($this->testTenant);
        $this->testManagerUser->setIsActive(true);
        $this->entityManager->persist($this->testManagerUser);

        $this->testAdminUser = new User();
        $this->testAdminUser->setEmail('testadmin_' . $uniqueId . '@example.com');
        $this->testAdminUser->setFirstName('Test');
        $this->testAdminUser->setLastName('Admin');
        $this->testAdminUser->setRoles(['ROLE_ADMIN']);
        $this->testAdminUser->setPassword('hashed_password');
        $this->testAdminUser->setTenant($this->testTenant);
        $this->testAdminUser->setIsActive(true);
        $this->entityManager->persist($this->testAdminUser);

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

    #[Test]
    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testIndexDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testIndexAcceptsViewFilter(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/bcm/business-process?view=own');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/en/bcm/business-process?view=inherited');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/en/bcm/business-process?view=subsidiaries');
        $this->assertResponseIsSuccessful();
    }

    // ========== NEW TESTS ==========

    #[Test]
    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process/new');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testNewRequiresRoleManager(): void
    {
        // ROLE_USER is denied (C-1/H-2: new requires ROLE_MANAGER)
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/new');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testNewDisplaysFormForManager(): void
    {
        $this->loginAsUser($this->testManagerUser);
        $crawler = $this->client->request('GET', '/en/bcm/business-process/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== SHOW TESTS ==========

    #[Test]
    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testShowDisplaysProcessForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId());
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testShowReturns404ForNonexistent(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/999999');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== EDIT TESTS ==========

    #[Test]
    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId() . '/edit');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testEditRequiresRoleManager(): void
    {
        // ROLE_USER is denied (H-2: edit requires ROLE_MANAGER)
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId() . '/edit');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testEditDisplaysFormForManager(): void
    {
        $this->loginAsUser($this->testManagerUser);
        $crawler = $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== DELETE TESTS ==========

    #[Test]
    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/bcm/business-process/' . $this->testProcess->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testDeleteRequiresRoleAdmin(): void
    {
        // ROLE_MANAGER is denied (H-2: delete requires ROLE_ADMIN)
        $this->loginAsUser($this->testManagerUser);
        $this->client->request('POST', '/en/bcm/business-process/' . $this->testProcess->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testDeleteRequiresCsrfToken(): void
    {
        // ROLE_ADMIN without CSRF token: redirect without deleting
        $this->loginAsUser($this->testAdminUser);
        $this->client->request('POST', '/en/bcm/business-process/' . $this->testProcess->getId());
        $this->assertResponseRedirects();
    }

    // ========== STATS API TESTS ==========

    #[Test]
    public function testStatsApiRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process/api/stats');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testStatsApiReturnsJsonForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/api/stats');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    #[Test]
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

    #[Test]
    public function testBiaRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId() . '/bia');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testBiaDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/' . $this->testProcess->getId() . '/bia');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testBiaReturns404ForNonexistent(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process/999999/bia');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== EMPTY STATE TESTS ==========

    #[Test]
    public function testIndexHandlesNoProcesses(): void
    {
        // Remove test process
        $this->entityManager->remove($this->testProcess);
        $this->entityManager->flush();
        $this->testProcess = null;

        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/bcm/business-process');
        $this->assertResponseIsSuccessful();
    }
}
