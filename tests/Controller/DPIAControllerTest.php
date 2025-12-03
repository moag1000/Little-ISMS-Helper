<?php

namespace App\Tests\Controller;

use App\Entity\DataProtectionImpactAssessment;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for DPIAController
 *
 * Tests Data Protection Impact Assessment management including:
 * - Index with listing and search
 * - Dashboard view
 * - CRUD operations
 * - Status workflow (submit, approve, reject, revision)
 * - PDF export
 * - Consultation features
 */
class DPIAControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?DataProtectionImpactAssessment $testDPIA = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testDPIA) {
            try {
                $dpia = $this->entityManager->find(DataProtectionImpactAssessment::class, $this->testDPIA->getId());
                if ($dpia) {
                    $this->entityManager->remove($dpia);
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

        if ($this->adminUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->adminUser->getId());
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

        $this->adminUser = new User();
        $this->adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $this->adminUser->setFirstName('Admin');
        $this->adminUser->setLastName('User');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        $this->testDPIA = new DataProtectionImpactAssessment();
        $this->testDPIA->setTenant($this->testTenant);
        $this->testDPIA->setTitle('Test DPIA ' . $uniqueId);
        $this->testDPIA->setReferenceNumber('DPIA-' . $uniqueId);
        $this->testDPIA->setVersion('1.0');
        $this->testDPIA->setProcessingDescription('Test processing description');
        $this->testDPIA->setProcessingPurposes('Testing purposes');
        $this->testDPIA->setNecessityAssessment('Required for testing');
        $this->testDPIA->setProportionalityAssessment('Proportionate');
        $this->testDPIA->setLegalBasis('consent');
        $this->testDPIA->setTechnicalMeasures('Encryption');
        $this->testDPIA->setOrganizationalMeasures('Access control');
        $this->testDPIA->setRiskLevel('low');
        $this->testDPIA->setDataCategories(['personal_data']);
        $this->testDPIA->setDataSubjectCategories(['customers']);
        $this->testDPIA->setStatus('draft');
        $this->testDPIA->setConductor($this->adminUser);
        $this->entityManager->persist($this->testDPIA);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/dpia/');
        $this->assertResponseRedirects();
    }

    public function testIndexDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/dpia/');
        $this->assertResponseIsSuccessful();
    }

    // ========== DASHBOARD TESTS ==========

    public function testDashboardRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/dpia/dashboard');
        $this->assertResponseRedirects();
    }

    public function testDashboardDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/dpia/dashboard');
        $this->assertResponseIsSuccessful();
    }

    // ========== SHOW TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/dpia/' . $this->testDPIA->getId());
        $this->assertResponseRedirects();
    }

    public function testShowDisplaysDPIA(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/dpia/' . $this->testDPIA->getId());
        $this->assertResponseIsSuccessful();
    }

    // ========== NEW TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/dpia/new');
        $this->assertResponseRedirects();
    }

    public function testNewDisplaysForm(): void
    {
        $this->loginAsUser($this->testUser);
        $crawler = $this->client->request('GET', '/en/dpia/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== EDIT TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/dpia/' . $this->testDPIA->getId() . '/edit');
        $this->assertResponseRedirects();
    }

    public function testEditDisplaysForm(): void
    {
        $this->loginAsUser($this->testUser);
        $crawler = $this->client->request('GET', '/en/dpia/' . $this->testDPIA->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== DELETE TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/dpia/' . $this->testDPIA->getId() . '/delete');
        $this->assertResponseRedirects();
    }

    // ========== SEARCH TESTS ==========

    public function testSearchRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/dpia/search');
        $this->assertResponseRedirects();
    }

    public function testSearchDisplaysResults(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/dpia/search?q=test');
        $this->assertResponseIsSuccessful();
    }

    // ========== WORKFLOW TESTS ==========

    public function testSubmitForReviewRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/dpia/' . $this->testDPIA->getId() . '/submit-for-review');
        $this->assertResponseRedirects();
    }

    public function testApproveRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/dpia/' . $this->testDPIA->getId() . '/approve');
        $this->assertResponseRedirects();
    }

    public function testRejectRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/dpia/' . $this->testDPIA->getId() . '/reject');
        $this->assertResponseRedirects();
    }

    // ========== EXPORT TESTS ==========

    public function testExportPdfRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/dpia/' . $this->testDPIA->getId() . '/export/pdf');
        $this->assertResponseRedirects();
    }

    // ========== CLONE TESTS ==========

    public function testCloneRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/dpia/' . $this->testDPIA->getId() . '/clone');
        $this->assertResponseRedirects();
    }

    // ========== VALIDATE TESTS ==========

    public function testValidateRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/dpia/' . $this->testDPIA->getId() . '/validate');
        $this->assertResponseRedirects();
    }

    public function testValidateDisplaysResults(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/dpia/' . $this->testDPIA->getId() . '/validate');
        $this->assertResponseIsSuccessful();
    }
}
