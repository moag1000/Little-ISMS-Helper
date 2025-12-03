<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for AnalyticsController
 *
 * Tests analytics dashboard and API including:
 * - Dashboard view
 * - Heat map data
 * - Compliance radar data
 * - Trend data
 * - Export functionality (risks, assets, compliance)
 */
class AnalyticsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;

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

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== DASHBOARD TESTS ==========

    public function testDashboardRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/analytics');
        $this->assertResponseRedirects();
    }

    public function testDashboardDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics');
        $this->assertResponseIsSuccessful();
    }

    // ========== HEAT MAP TESTS ==========

    public function testHeatMapRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/analytics/api/heat-map');
        $this->assertResponseRedirects();
    }

    public function testHeatMapReturnsJsonForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics/api/heat-map');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testHeatMapContainsExpectedFields(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics/api/heat-map');

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('matrix', $response);
        $this->assertArrayHasKey('total_risks', $response);
    }

    // ========== COMPLIANCE RADAR TESTS ==========

    public function testComplianceRadarRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/analytics/api/compliance-radar');
        $this->assertResponseRedirects();
    }

    public function testComplianceRadarReturnsJsonForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics/api/compliance-radar');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testComplianceRadarContainsExpectedFields(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics/api/compliance-radar');

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('overall_compliance', $response);
    }

    // ========== TRENDS TESTS ==========

    public function testTrendsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/analytics/api/trends');
        $this->assertResponseRedirects();
    }

    public function testTrendsReturnsJsonForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics/api/trends');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testTrendsContainsExpectedFields(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics/api/trends');

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('risks', $response);
        $this->assertArrayHasKey('assets', $response);
        $this->assertArrayHasKey('incidents', $response);
    }

    public function testTrendsAcceptsPeriodParameter(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics/api/trends?period=6');
        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        // With 6 months period, we should get 6 data points
        $this->assertCount(6, $response['risks']);
    }

    // ========== EXPORT TESTS ==========

    public function testExportRisksRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/analytics/api/export/risks');
        $this->assertResponseRedirects();
    }

    public function testExportRisksReturnsCsvForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics/api/export/risks');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
    }

    public function testExportAssetsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/analytics/api/export/assets');
        $this->assertResponseRedirects();
    }

    public function testExportAssetsReturnsCsvForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics/api/export/assets');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
    }

    public function testExportComplianceRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/analytics/api/export/compliance');
        $this->assertResponseRedirects();
    }

    public function testExportComplianceReturnsCsvForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics/api/export/compliance');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
    }

    public function testExportHasCorrectFilenameHeader(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/analytics/api/export/risks');

        $contentDisposition = $this->client->getResponse()->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $contentDisposition);
        $this->assertStringContainsString('analytics_risks_', $contentDisposition);
        $this->assertStringContainsString('.csv', $contentDisposition);
    }
}
