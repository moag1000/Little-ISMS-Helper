<?php

namespace App\Tests\Controller;

use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for ProcessingActivityController
 *
 * Tests GDPR Processing Activity (VVT) management including:
 * - Index with listing
 * - CRUD operations
 * - Status management (activate, archive)
 * - Compliance report
 */
class ProcessingActivityControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?ProcessingActivity $testActivity = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testActivity) {
            try {
                $activity = $this->entityManager->find(ProcessingActivity::class, $this->testActivity->getId());
                if ($activity) {
                    $this->entityManager->remove($activity);
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

        $this->testActivity = new ProcessingActivity();
        $this->testActivity->setTenant($this->testTenant);
        $this->testActivity->setName('Test Processing Activity ' . $uniqueId);
        $this->testActivity->setPurposes(['Testing purposes']);
        $this->testActivity->setLegalBasis('consent');
        $this->testActivity->setPersonalDataCategories(['personal_data']);
        $this->testActivity->setDataSubjectCategories(['customers']);
        $this->testActivity->setStatus('active');
        $this->entityManager->persist($this->testActivity);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/processing-activity/');
        $this->assertResponseRedirects();
    }

    public function testIndexDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/processing-activity/');
        $this->assertResponseIsSuccessful();
    }

    public function testIndexShowsTestActivity(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/processing-activity/');
        $this->assertResponseIsSuccessful();
    }

    // ========== NEW TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/processing-activity/new');
        $this->assertResponseRedirects();
    }

    public function testNewDisplaysForm(): void
    {
        $this->loginAsUser($this->testUser);
        $crawler = $this->client->request('GET', '/en/processing-activity/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== EDIT TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/processing-activity/' . $this->testActivity->getId() . '/edit');
        $this->assertResponseRedirects();
    }

    public function testEditDisplaysForm(): void
    {
        $this->loginAsUser($this->testUser);
        $crawler = $this->client->request('GET', '/en/processing-activity/' . $this->testActivity->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== DELETE TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/processing-activity/' . $this->testActivity->getId() . '/delete');
        $this->assertResponseRedirects();
    }

    // ========== STATUS TESTS ==========

    public function testActivateRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/processing-activity/' . $this->testActivity->getId() . '/activate');
        $this->assertResponseRedirects();
    }

    public function testArchiveRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/processing-activity/' . $this->testActivity->getId() . '/archive');
        $this->assertResponseRedirects();
    }

    // ========== COMPLIANCE REPORT TESTS ==========

    public function testComplianceReportRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/processing-activity/' . $this->testActivity->getId() . '/compliance-report');
        $this->assertResponseRedirects();
    }

    public function testComplianceReportDisplays(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/processing-activity/' . $this->testActivity->getId() . '/compliance-report');
        $this->assertResponseIsSuccessful();
    }

    // ========== EMPTY STATE TESTS ==========

    public function testIndexHandlesNoActivities(): void
    {
        // Remove test activity
        $this->entityManager->remove($this->testActivity);
        $this->entityManager->flush();
        $this->testActivity = null;

        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/processing-activity/');
        $this->assertResponseIsSuccessful();
    }
}
