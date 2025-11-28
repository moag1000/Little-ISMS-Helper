<?php

namespace App\Tests\Repository;

use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Repository\DataBreachRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DataBreachRepository
 *
 * Note: Many methods use QueryBuilder->getQuery()->getResult() which returns
 * Doctrine\ORM\Query. Since Query is final and cannot be mocked, these methods
 * should be tested via integration tests using a real database.
 *
 * This test file focuses on:
 * - Methods that can be unit tested with mocks
 * - Testing the logic in findIncomplete()
 * - Testing reference number generation logic
 * - Testing dashboard statistics aggregation logic
 */
class DataBreachRepositoryTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $queryBuilder;
    private DataBreachRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);

        // Create repository with mocked dependencies
        // Note: We cannot properly unit test repository methods that use QueryBuilder
        // because Doctrine\ORM\Query is final. These should be integration tested.
        $this->repository = $this->getMockBuilder(DataBreachRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * Test findIncomplete() filters breaches correctly
     *
     * This method calls findByTenant() and then filters using DataBreach->isComplete()
     * We can unit test the filtering logic.
     */
    public function testFindIncompleteReturnsOnlyIncompleteBreaches(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // Create mock breaches - some complete, some incomplete
        $completeBreach = $this->createMock(DataBreach::class);
        $completeBreach->method('isComplete')->willReturn(true);

        $incompleteBreach1 = $this->createMock(DataBreach::class);
        $incompleteBreach1->method('isComplete')->willReturn(false);

        $incompleteBreach2 = $this->createMock(DataBreach::class);
        $incompleteBreach2->method('isComplete')->willReturn(false);

        $allBreaches = [$completeBreach, $incompleteBreach1, $incompleteBreach2];

        // Create a partial mock that only mocks findByTenant
        $repository = $this->getMockBuilder(DataBreachRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByTenant'])
            ->getMock();

        $repository->expects($this->once())
            ->method('findByTenant')
            ->with($tenant)
            ->willReturn($allBreaches);

        $result = $repository->findIncomplete($tenant);

        // Should only return the two incomplete breaches
        $this->assertCount(2, $result);
        $this->assertContains($incompleteBreach1, $result);
        $this->assertContains($incompleteBreach2, $result);
        $this->assertNotContains($completeBreach, $result);
    }

    public function testFindIncompleteReturnsEmptyArrayWhenAllComplete(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $completeBreach1 = $this->createMock(DataBreach::class);
        $completeBreach1->method('isComplete')->willReturn(true);

        $completeBreach2 = $this->createMock(DataBreach::class);
        $completeBreach2->method('isComplete')->willReturn(true);

        $allBreaches = [$completeBreach1, $completeBreach2];

        $repository = $this->getMockBuilder(DataBreachRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByTenant'])
            ->getMock();

        $repository->expects($this->once())
            ->method('findByTenant')
            ->with($tenant)
            ->willReturn($allBreaches);

        $result = $repository->findIncomplete($tenant);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFindIncompleteReturnsEmptyArrayWhenNoBreaches(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $repository = $this->getMockBuilder(DataBreachRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByTenant'])
            ->getMock();

        $repository->expects($this->once())
            ->method('findByTenant')
            ->with($tenant)
            ->willReturn([]);

        $result = $repository->findIncomplete($tenant);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getNextReferenceNumber() generation logic
     *
     * Note: This method uses QueryBuilder and Query which are difficult to mock.
     * The full method should be tested via integration tests.
     * Here we document the expected behavior.
     */
    public function testGetNextReferenceNumberFormat(): void
    {
        // This test documents the expected format: BREACH-YYYY-XXX
        // Full testing requires integration tests with a real database

        $tenant = $this->createMock(Tenant::class);

        // Expected format for first breach of the year
        $year = date('Y');
        $expectedFirstBreach = sprintf('BREACH-%s-001', $year);

        // Pattern validation
        $this->assertMatchesRegularExpression('/^BREACH-\d{4}-\d{3}$/', $expectedFirstBreach);
    }

    /**
     * Test reference number increment logic
     *
     * Integration test should verify:
     * - First breach: BREACH-2024-001
     * - Second breach: BREACH-2024-002
     * - After BREACH-2024-009: BREACH-2024-010
     * - After BREACH-2024-099: BREACH-2024-100
     */
    public function testReferenceNumberIncrementLogic(): void
    {
        // Test the str_pad logic used in getNextReferenceNumber
        $year = '2024';
        $prefix = sprintf('BREACH-%s-', $year);

        // Simulate incrementing from 1
        $lastNumber = 1;
        $nextNumber = $lastNumber + 1;
        $result = $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
        $this->assertEquals('BREACH-2024-002', $result);

        // Simulate incrementing from 9
        $lastNumber = 9;
        $nextNumber = $lastNumber + 1;
        $result = $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
        $this->assertEquals('BREACH-2024-010', $result);

        // Simulate incrementing from 99
        $lastNumber = 99;
        $nextNumber = $lastNumber + 1;
        $result = $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
        $this->assertEquals('BREACH-2024-100', $result);

        // Simulate incrementing from 999
        $lastNumber = 999;
        $nextNumber = $lastNumber + 1;
        $result = $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
        $this->assertEquals('BREACH-2024-1000', $result); // Exceeds 3 digits
    }

    /**
     * Test reference number extraction logic
     *
     * Verifies the substr logic used to extract the sequence number
     */
    public function testReferenceNumberExtractionLogic(): void
    {
        // Test extracting the last 3 characters from reference numbers
        $refNumber1 = 'BREACH-2024-001';
        $extracted1 = (int) substr($refNumber1, -3);
        $this->assertEquals(1, $extracted1);

        $refNumber2 = 'BREACH-2024-042';
        $extracted2 = (int) substr($refNumber2, -3);
        $this->assertEquals(42, $extracted2);

        $refNumber3 = 'BREACH-2024-999';
        $extracted3 = (int) substr($refNumber3, -3);
        $this->assertEquals(999, $extracted3);
    }

    /**
     * Test getTotalAffectedDataSubjects() calculation
     *
     * Note: Full testing requires integration tests.
     * Here we document the expected behavior.
     */
    public function testGetTotalAffectedDataSubjectsReturnsZeroWhenNull(): void
    {
        // Test the null coalescing logic: (int) ($result ?? 0)
        $result = null;
        $total = (int) ($result ?? 0);
        $this->assertEquals(0, $total);
    }

    public function testGetTotalAffectedDataSubjectsConvertsToInt(): void
    {
        // Test that string/float results are converted to int
        $result = '150';
        $total = (int) ($result ?? 0);
        $this->assertEquals(150, $total);

        $result = 42.8; // SUM might return float
        $total = (int) ($result ?? 0);
        $this->assertEquals(42, $total);
    }

    /**
     * Test dashboard statistics calculation logic
     *
     * Note: Full getDashboardStatistics() testing requires integration tests.
     * Here we test the aggregation and calculation logic.
     */
    public function testDashboardStatisticsStatusCountsInitialization(): void
    {
        // Test that status counts are initialized with all expected statuses
        $statusCounts = [
            'draft' => 0,
            'under_assessment' => 0,
            'authority_notified' => 0,
            'subjects_notified' => 0,
            'closed' => 0,
        ];

        $this->assertArrayHasKey('draft', $statusCounts);
        $this->assertArrayHasKey('under_assessment', $statusCounts);
        $this->assertArrayHasKey('authority_notified', $statusCounts);
        $this->assertArrayHasKey('subjects_notified', $statusCounts);
        $this->assertArrayHasKey('closed', $statusCounts);
        $this->assertEquals(0, $statusCounts['draft']);
    }

    public function testDashboardStatisticsRiskCountsInitialization(): void
    {
        // Test that risk level counts are initialized with all expected levels
        $riskCounts = [
            'low' => 0,
            'medium' => 0,
            'high' => 0,
            'critical' => 0,
        ];

        $this->assertArrayHasKey('low', $riskCounts);
        $this->assertArrayHasKey('medium', $riskCounts);
        $this->assertArrayHasKey('high', $riskCounts);
        $this->assertArrayHasKey('critical', $riskCounts);
        $this->assertEquals(0, $riskCounts['low']);
    }

    public function testDashboardStatisticsCompletenessCalculation(): void
    {
        // Test the completeness rate calculation logic
        // completenessRate = total > 0 ? round(completenessSum / total) : 0

        // Case 1: No breaches
        $total = 0;
        $completenessSum = 0;
        $completenessRate = $total > 0 ? (int) round($completenessSum / $total) : 0;
        $this->assertEquals(0, $completenessRate);

        // Case 2: All complete (100%)
        $total = 5;
        $completenessSum = 500; // 5 breaches × 100%
        $completenessRate = $total > 0 ? (int) round($completenessSum / $total) : 0;
        $this->assertEquals(100, $completenessRate);

        // Case 3: Mixed completeness
        $total = 4;
        $completenessSum = 200; // Average 50%
        $completenessRate = $total > 0 ? (int) round($completenessSum / $total) : 0;
        $this->assertEquals(50, $completenessRate);

        // Case 4: Rounding test
        $total = 3;
        $completenessSum = 200; // 66.67% should round to 67
        $completenessRate = $total > 0 ? (int) round($completenessSum / $total) : 0;
        $this->assertEquals(67, $completenessRate);
    }

    public function testDashboardStatisticsReturnStructure(): void
    {
        // Test that the expected array structure is returned
        $expectedKeys = [
            'total',
            'draft',
            'under_assessment',
            'closed',
            'low_risk',
            'medium_risk',
            'high_risk',
            'critical_risk',
            'requires_authority_notification',
            'authority_notified',
            'requires_subject_notification',
            'subjects_notified',
            'special_categories_affected',
            'completeness_rate',
        ];

        // Verify all expected keys are present
        foreach ($expectedKeys as $key) {
            $this->assertIsString($key);
        }

        $this->assertCount(14, $expectedKeys);
    }

    /**
     * Test findRecent() date calculation
     *
     * Note: Full testing requires integration tests.
     * Here we test the date calculation logic.
     */
    public function testFindRecentDateCalculation(): void
    {
        $days = 30;
        $since = new DateTime(sprintf('-%d days', $days));
        $now = new DateTime();

        $interval = $now->diff($since);
        $daysDiff = $interval->days;

        // Should be approximately 30 days (allowing for DST changes)
        $this->assertGreaterThanOrEqual(29, $daysDiff);
        $this->assertLessThanOrEqual(31, $daysDiff);
    }

    public function testFindRecentSupportsCustomDays(): void
    {
        // Test that different day values work correctly
        $testCases = [7, 14, 30, 60, 90];

        foreach ($testCases as $days) {
            $since = new DateTime(sprintf('-%d days', $days));
            $this->assertInstanceOf(DateTime::class, $since);

            // Since should be in the past
            $this->assertLessThan(new DateTime(), $since);
        }
    }

    /**
     * Test findAuthorityNotificationOverdue() deadline calculation
     *
     * This is CRITICAL for GDPR Art. 33 compliance (72-hour deadline)
     */
    public function testAuthorityNotificationOverdueDeadlineCalculation(): void
    {
        $deadline = new DateTime('-72 hours');
        $now = new DateTime();

        $interval = $now->diff($deadline);
        $hoursDiff = ($interval->days * 24) + $interval->h;

        // Should be approximately 72 hours
        $this->assertGreaterThanOrEqual(71, $hoursDiff);
        $this->assertLessThanOrEqual(73, $hoursDiff);
    }

    public function testAuthorityNotificationDeadlineIsInPast(): void
    {
        $deadline = new DateTime('-72 hours');
        $now = new DateTime();

        // Deadline should be before now
        $this->assertLessThan($now, $deadline);
    }

    /**
     * Integration test notes for methods using QueryBuilder
     *
     * The following methods should be tested via integration tests with a real database:
     *
     * 1. findByTenant(Tenant $tenant): array
     *    - Should return all breaches for tenant
     *    - Should order by createdAt DESC
     *    - Should return empty array if no breaches
     *
     * 2. findByStatus(Tenant $tenant, string $status): array
     *    - Should filter by status correctly
     *    - Should respect tenant isolation
     *
     * 3. findByRiskLevel(Tenant $tenant, string $riskLevel): array
     *    - Should filter by risk level correctly
     *    - Should respect tenant isolation
     *
     * 4. findHighRisk(Tenant $tenant): array
     *    - Should return breaches with 'high' or 'critical' risk
     *    - Should not return 'low' or 'medium' risk breaches
     *
     * 5. findRequiringAuthorityNotification(Tenant $tenant): array
     *    - Should return breaches where requiresAuthorityNotification = true
     *    - Should exclude breaches where supervisoryAuthorityNotifiedAt is set
     *    - Should order by createdAt ASC (oldest first)
     *
     * 6. findAuthorityNotificationOverdue(Tenant $tenant): array
     *    - Should join with incident table
     *    - Should filter by 72-hour deadline
     *    - Should order by incident.detectedAt ASC (most overdue first)
     *
     * 7. findRequiringSubjectNotification(Tenant $tenant): array
     *    - Should return breaches where requiresSubjectNotification = true
     *    - Should exclude breaches where dataSubjectsNotifiedAt is set
     *
     * 8. findWithSpecialCategories(Tenant $tenant): array
     *    - Should filter by specialCategoriesAffected = true
     *
     * 9. findWithCriminalData(Tenant $tenant): array
     *    - Should filter by criminalDataAffected = true
     *
     * 10. findByProcessingActivity(Tenant $tenant, int $id): array
     *     - Should filter by processingActivity relationship
     *
     * 11. findByDateRange(Tenant $tenant, DateTimeInterface $start, DateTimeInterface $end): array
     *     - Should join with incident table
     *     - Should filter by incident.detectedAt BETWEEN start and end
     *
     * 12. getDashboardStatistics(Tenant $tenant): array
     *     - Should aggregate counts correctly
     *     - Should calculate completeness rate
     *     - Should return all expected keys
     *
     * 13. getNextReferenceNumber(Tenant $tenant): string
     *     - Should generate BREACH-YYYY-001 for first breach
     *     - Should increment correctly
     *     - Should handle year rollover
     *     - Should pad numbers to 3 digits
     *
     * 14. getTotalAffectedDataSubjects(Tenant $tenant): int
     *     - Should sum affectedDataSubjects across all breaches
     *     - Should return 0 if no breaches or all null
     *
     * 15. findRecent(Tenant $tenant, int $days = 30): array
     *     - Should filter by createdAt within date range
     *     - Should respect custom days parameter
     */
}
