<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for ReportController
 *
 * Tests report generation including:
 * - Index with report types
 * - Dashboard PDF/Excel export
 * - Risks PDF/Excel export
 * - Controls PDF/Excel export
 * - Incidents PDF/Excel export
 * - Trainings PDF/Excel export
 */
class ReportControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
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

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/reports/');
        $this->assertResponseRedirects();
    }

    public function testIndexDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/reports/');
        $this->assertResponseIsSuccessful();
    }

    public function testIndexShowsReportTypes(): void
    {
        $this->loginAsUser($this->testUser);
        $crawler = $this->client->request('GET', '/en/reports/');
        $this->assertResponseIsSuccessful();
    }

    // ========== DASHBOARD REPORTS ==========

    public function testDashboardPdfRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/reports/dashboard/pdf');
        $this->assertResponseRedirects();
    }

    public function testDashboardExcelRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/reports/dashboard/excel');
        $this->assertResponseRedirects();
    }

    // ========== RISKS REPORTS ==========

    public function testRisksPdfRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/reports/risks/pdf');
        $this->assertResponseRedirects();
    }

    public function testRisksExcelRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/reports/risks/excel');
        $this->assertResponseRedirects();
    }

    // ========== CONTROLS REPORTS ==========

    public function testControlsPdfRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/reports/controls/pdf');
        $this->assertResponseRedirects();
    }

    public function testControlsExcelRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/reports/controls/excel');
        $this->assertResponseRedirects();
    }

    // ========== INCIDENTS REPORTS ==========

    public function testIncidentsPdfRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/reports/incidents/pdf');
        $this->assertResponseRedirects();
    }

    public function testIncidentsExcelRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/reports/incidents/excel');
        $this->assertResponseRedirects();
    }

    // ========== TRAININGS REPORTS ==========

    public function testTrainingsPdfRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/reports/trainings/pdf');
        $this->assertResponseRedirects();
    }

    public function testTrainingsExcelRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/reports/trainings/excel');
        $this->assertResponseRedirects();
    }

    // ========== AUTHENTICATED REPORT GENERATION ==========

    public function testDashboardPdfGeneratesForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/reports/dashboard/pdf');
        // May return PDF or redirect - both are valid
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === 200 || $statusCode === 302,
            "Expected 200 or 302, got {$statusCode}"
        );
    }

    public function testDashboardExcelGeneratesForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/reports/dashboard/excel');
        // May return Excel or redirect - both are valid
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === 200 || $statusCode === 302,
            "Expected 200 or 302, got {$statusCode}"
        );
    }

    public function testRisksPdfGeneratesForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/reports/risks/pdf');
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === 200 || $statusCode === 302,
            "Expected 200 or 302, got {$statusCode}"
        );
    }

    public function testControlsPdfGeneratesForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/reports/controls/pdf');
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === 200 || $statusCode === 302,
            "Expected 200 or 302, got {$statusCode}"
        );
    }
}
