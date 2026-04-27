<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\FulfillmentInheritanceLogRepository;
use App\Service\CompliancePolicyService;
use App\Service\InheritanceMetricsService;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Contract-level tests for the InheritanceMetricsService (CM-1 / CM-5 quick-wins).
 *
 * Query-level mocks stub out countFulfillments() and countLogStatuses() to
 * exercise the service arithmetic without hitting a real DB.
 */
final class InheritanceMetricsServiceTest extends TestCase
{
    private FulfillmentInheritanceLogRepository $logRepository;
    private ComplianceRequirementFulfillmentRepository $fulfillmentRepository;
    private ComplianceFrameworkRepository $frameworkRepository;
    private CompliancePolicyService $policy;

    protected function setUp(): void
    {
        $this->logRepository = $this->createStub(FulfillmentInheritanceLogRepository::class);
        $this->fulfillmentRepository = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $this->frameworkRepository = $this->createStub(ComplianceFrameworkRepository::class);
        $this->policy = $this->createStub(CompliancePolicyService::class);
    }

    #[Test]
    public function testEmptyTenantYieldsZeroInheritanceRate(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $framework = $this->mockFramework('NIS2');

        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);
        $this->stubFulfillmentCount(0);
        $this->stubLogStatusCounts([]);
        $this->stubPolicyFactor(0.3);

        $service = $this->service();
        $result = $service->metricsForTenant($tenant);

        $this->assertSame(0, $result['total']['fulfillments_total']);
        $this->assertSame(0, $result['total']['inheritance_rate_percent']);
        $this->assertSame(0.0, $service->fteSavedForTenant($tenant));
    }

    #[Test]
    public function testMixedInheritedAndManualFulfillments(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $framework = $this->mockFramework('NIS2');

        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);
        $this->stubFulfillmentCount(125);
        $this->stubLogStatusCounts([
            FulfillmentInheritanceLog::STATUS_CONFIRMED => 48,
            FulfillmentInheritanceLog::STATUS_OVERRIDDEN => 7,
            FulfillmentInheritanceLog::STATUS_PENDING_REVIEW => 10,
            FulfillmentInheritanceLog::STATUS_SOURCE_UPDATED => 2,
        ]);
        $this->stubPolicyFactor(0.3);

        $service = $this->service();
        $result = $service->metricsForFramework($tenant, $framework);

        $this->assertSame('NIS2', $result['framework_code']);
        $this->assertSame(125, $result['fulfillments_total']);
        $this->assertSame(48, $result['fulfillments_from_inheritance_confirmed']);
        $this->assertSame(7, $result['fulfillments_from_inheritance_overridden']);
        $this->assertSame(70, $result['fulfillments_manual']);
        $this->assertSame(44, $result['inheritance_rate_percent']); // 55/125 = 44%
        $this->assertSame(12, $result['pending_review_count']);
    }

    #[Test]
    public function testOnlyPendingReviewsYieldsZeroInheritanceRate(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $framework = $this->mockFramework('ISO27001');

        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);
        $this->stubFulfillmentCount(93);
        $this->stubLogStatusCounts([
            FulfillmentInheritanceLog::STATUS_PENDING_REVIEW => 20,
        ]);
        $this->stubPolicyFactor(0.3);

        $service = $this->service();
        $result = $service->metricsForFramework($tenant, $framework);

        $this->assertSame(93, $result['fulfillments_total']);
        $this->assertSame(0, $result['fulfillments_from_inheritance_confirmed']);
        $this->assertSame(0, $result['fulfillments_from_inheritance_overridden']);
        $this->assertSame(93, $result['fulfillments_manual']);
        $this->assertSame(0, $result['inheritance_rate_percent']);
        $this->assertSame(20, $result['pending_review_count']);
    }

    #[Test]
    public function testFteSavedWithTenInheritedYieldsThreeDays(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $framework = $this->mockFramework('NIS2');

        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);
        $this->stubFulfillmentCount(50);
        $this->stubLogStatusCounts([
            FulfillmentInheritanceLog::STATUS_CONFIRMED => 8,
            FulfillmentInheritanceLog::STATUS_OVERRIDDEN => 2,
        ]);
        $this->stubPolicyFactor(0.3);

        $service = $this->service();

        $this->assertSame(3.0, $service->fteSavedForFramework($tenant, $framework));
        $this->assertSame(3.0, $service->fteSavedForTenant($tenant));
    }

    #[Test]
    public function testFteSavedWithNoInheritedYieldsZero(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $framework = $this->mockFramework('ISO27001');

        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);
        $this->stubFulfillmentCount(93);
        $this->stubLogStatusCounts([]);
        $this->stubPolicyFactor(0.3);

        $service = $this->service();

        $this->assertSame(0.0, $service->fteSavedForFramework($tenant, $framework));
        $this->assertSame(0.0, $service->fteSavedForTenant($tenant));
    }

    #[Test]
    public function testTenantAggregateCombinesFrameworks(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $nis2 = $this->mockFramework('NIS2');
        $iso = $this->mockFramework('ISO27001');

        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$nis2, $iso]);

        // NIS2: 100 total, 40 inherited (30 confirmed + 10 overridden)
        // ISO27001: 100 total, 0 inherited
        // Combined: 200 total, 40 inherited → 20%
        $fulfillmentQb = $this->createStub(QueryBuilder::class);
        $fulfillmentQuery = $this->createStub(Query::class);
        $fulfillmentQb->method('select')->willReturnSelf();
        $fulfillmentQb->method('innerJoin')->willReturnSelf();
        $fulfillmentQb->method('where')->willReturnSelf();
        $fulfillmentQb->method('andWhere')->willReturnSelf();
        $fulfillmentQb->method('setParameter')->willReturnSelf();
        $fulfillmentQb->method('getQuery')->willReturn($fulfillmentQuery);
        $fulfillmentQuery->method('getSingleScalarResult')->willReturn(100);
        $this->fulfillmentRepository->method('createQueryBuilder')->willReturn($fulfillmentQb);

        $logQbNis2 = $this->createStub(QueryBuilder::class);
        $logQueryNis2 = $this->createStub(Query::class);
        $logQbNis2->method('select')->willReturnSelf();
        $logQbNis2->method('innerJoin')->willReturnSelf();
        $logQbNis2->method('where')->willReturnSelf();
        $logQbNis2->method('andWhere')->willReturnSelf();
        $logQbNis2->method('groupBy')->willReturnSelf();
        $logQbNis2->method('setParameter')->willReturnSelf();
        $logQbNis2->method('getQuery')->willReturn($logQueryNis2);
        $logQueryNis2->method('getArrayResult')->willReturnOnConsecutiveCalls(
            [
                ['status' => FulfillmentInheritanceLog::STATUS_CONFIRMED, 'cnt' => 30],
                ['status' => FulfillmentInheritanceLog::STATUS_OVERRIDDEN, 'cnt' => 10],
            ],
            [],
        );
        $this->logRepository->method('createQueryBuilder')->willReturn($logQbNis2);

        $this->stubPolicyFactor(0.3);

        $service = $this->service();
        $result = $service->metricsForTenant($tenant);

        $this->assertCount(2, $result['per_framework']);
        $this->assertSame(200, $result['total']['fulfillments_total']);
        $this->assertSame(30, $result['total']['fulfillments_from_inheritance_confirmed']);
        $this->assertSame(10, $result['total']['fulfillments_from_inheritance_overridden']);
        $this->assertSame(160, $result['total']['fulfillments_manual']);
        $this->assertSame(20, $result['total']['inheritance_rate_percent']);
    }

    private function service(): InheritanceMetricsService
    {
        return new InheritanceMetricsService(
            $this->logRepository,
            $this->fulfillmentRepository,
            $this->frameworkRepository,
            $this->policy,
        );
    }

    private function mockFramework(string $code): ComplianceFramework
    {
        $framework = $this->createStub(ComplianceFramework::class);
        $framework->method('getCode')->willReturn($code);
        $framework->method('getName')->willReturn($code);
        return $framework;
    }

    private function stubFulfillmentCount(int $count): void
    {
        $qb = $this->createStub(QueryBuilder::class);
        $query = $this->createStub(Query::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getSingleScalarResult')->willReturn($count);

        $this->fulfillmentRepository->method('createQueryBuilder')->willReturn($qb);
    }

    /**
     * @param array<string, int> $statusCounts map of reviewStatus → count
     */
    private function stubLogStatusCounts(array $statusCounts): void
    {
        $rows = [];
        foreach ($statusCounts as $status => $cnt) {
            $rows[] = ['status' => $status, 'cnt' => $cnt];
        }

        $qb = $this->createStub(QueryBuilder::class);
        $query = $this->createStub(Query::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getArrayResult')->willReturn($rows);

        $this->logRepository->method('createQueryBuilder')->willReturn($qb);
    }

    private function stubPolicyFactor(float $factor): void
    {
        $this->policy->method('getFloat')->willReturnCallback(
            static fn (string $key, float $fallback = 0.0): float => $factor,
        );
    }
}
