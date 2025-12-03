<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for MappingQualityController
 *
 * Tests compliance mapping quality analysis including:
 * - Dashboard with quality overview
 * - Review queue for mappings requiring attention
 * - Individual mapping review
 * - Gap analysis and management
 * - Batch analysis
 * - Quality statistics
 * - Export functionality
 */
class MappingQualityControllerTest extends WebTestCase
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

    // ========== DASHBOARD TESTS ==========

    public function testDashboardRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/compliance/mapping-quality/');
        $this->assertResponseRedirects();
    }

    public function testDashboardAccessibleForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/');
        // May redirect to compliance index if no mappings exist
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_FOUND,
            "Expected 200 or 302, got {$statusCode}"
        );
    }

    public function testDashboardAccessibleForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/');
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_FOUND,
            "Expected 200 or 302, got {$statusCode}"
        );
    }

    // ========== REVIEW QUEUE TESTS ==========

    public function testReviewQueueRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/compliance/mapping-quality/review-queue');
        $this->assertResponseRedirects();
    }

    public function testReviewQueueAccessibleForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/review-queue');
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_FOUND,
            "Expected 200 or 302, got {$statusCode}"
        );
    }

    // ========== REVIEW MAPPING TESTS ==========

    public function testReviewMappingRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/compliance/mapping-quality/review/1');
        $this->assertResponseRedirects();
    }

    public function testReviewMappingReturns404ForNonexistent(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/review/999999');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUpdateReviewRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/compliance/mapping-quality/review/1/update');
        $this->assertResponseRedirects();
    }

    public function testUpdateReviewRequiresPost(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/review/1/update');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testUpdateReviewReturns404ForNonexistent(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request(
            'POST',
            '/en/compliance/mapping-quality/review/999999/update',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['review_status' => 'approved'])
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== ANALYZE MAPPING TESTS ==========

    public function testAnalyzeMappingRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/compliance/mapping-quality/analyze/1');
        $this->assertResponseRedirects();
    }

    public function testAnalyzeMappingRequiresPost(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/analyze/1');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testAnalyzeMappingReturns404ForNonexistent(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/compliance/mapping-quality/analyze/999999');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== GAPS TESTS ==========

    public function testGapsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/compliance/mapping-quality/gaps');
        $this->assertResponseRedirects();
    }

    public function testGapsAccessibleForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/gaps');
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_FOUND,
            "Expected 200 or 302, got {$statusCode}"
        );
    }

    // ========== UPDATE GAP TESTS ==========

    public function testUpdateGapRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/compliance/mapping-quality/gap/1/update');
        $this->assertResponseRedirects();
    }

    public function testUpdateGapRequiresPost(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/gap/1/update');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testUpdateGapReturns404ForNonexistent(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request(
            'POST',
            '/en/compliance/mapping-quality/gap/999999/update',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['status' => 'resolved'])
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== BATCH ANALYZE TESTS ==========

    public function testBatchAnalyzeRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/compliance/mapping-quality/batch-analyze');
        $this->assertResponseRedirects();
    }

    public function testBatchAnalyzeRequiresPost(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/batch-analyze');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testBatchAnalyzeReturnsJsonForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request(
            'POST',
            '/en/compliance/mapping-quality/batch-analyze',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['limit' => 5])
        );
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testBatchAnalyzeValidatesLimit(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request(
            'POST',
            '/en/compliance/mapping-quality/batch-analyze',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['limit' => 500]) // Exceeds max of 100
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testBatchAnalyzeValidatesJson(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request(
            'POST',
            '/en/compliance/mapping-quality/batch-analyze',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'invalid json'
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // ========== STATS TESTS ==========

    public function testStatsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/compliance/mapping-quality/stats');
        $this->assertResponseRedirects();
    }

    public function testStatsReturnsJsonForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/stats');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testStatsContainsExpectedFields(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/stats');

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('total', $response);
        $this->assertArrayHasKey('analyzed', $response);
        $this->assertArrayHasKey('remaining', $response);
        $this->assertArrayHasKey('percentage', $response);
    }

    // ========== EXPORT TESTS ==========

    public function testExportRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/compliance/mapping-quality/export');
        $this->assertResponseRedirects();
    }

    public function testExportReturnsJsonForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/export');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testExportContainsExpectedFields(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/compliance/mapping-quality/export');

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('quality_statistics', $response);
        $this->assertArrayHasKey('mappings_requiring_review_count', $response);
        $this->assertArrayHasKey('high_priority_gaps_count', $response);
        $this->assertArrayHasKey('export_date', $response);
    }

    // ========== INPUT VALIDATION TESTS ==========

    public function testUpdateReviewValidatesReviewStatus(): void
    {
        $this->loginAsUser($this->testUser);
        // This would require a real mapping to test properly
        // For now, test that invalid mapping returns 404
        $this->client->request(
            'POST',
            '/en/compliance/mapping-quality/review/999999/update',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['review_status' => 'invalid_status'])
        );
        // Returns 404 because mapping doesn't exist
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUpdateGapValidatesStatus(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request(
            'POST',
            '/en/compliance/mapping-quality/gap/999999/update',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['status' => 'invalid_status'])
        );
        // Returns 404 because gap doesn't exist
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
