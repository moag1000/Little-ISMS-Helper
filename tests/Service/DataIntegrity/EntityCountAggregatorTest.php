<?php

declare(strict_types=1);

namespace App\Tests\Service\DataIntegrity;

use App\Repository\AssetRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ControlRepository;
use App\Repository\DataBreachRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\LocationRepository;
use App\Repository\PersonRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;
use App\Repository\TenantRepository;
use App\Repository\TrainingRepository;
use App\Service\DataIntegrity\EntityCountAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class EntityCountAggregatorTest extends TestCase
{
    private MockObject $entityManager;
    private EntityCountAggregator $aggregator;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->aggregator = new EntityCountAggregator(
            $this->entityManager,
            $this->createMock(TenantRepository::class),
            $this->createMock(AssetRepository::class),
            $this->createMock(RiskRepository::class),
            $this->createMock(IncidentRepository::class),
            $this->createMock(InternalAuditRepository::class),
            $this->createMock(DocumentRepository::class),
            $this->createMock(TrainingRepository::class),
            $this->createMock(BusinessProcessRepository::class),
            $this->createMock(BusinessContinuityPlanRepository::class),
            $this->createMock(DataBreachRepository::class),
            $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(SupplierRepository::class),
            $this->createMock(LocationRepository::class),
            $this->createMock(PersonRepository::class),
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // calculateHealthScore — pure math, no DB needed
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function health_score_is_100_when_no_entities(): void
    {
        // Configure repositories called inside calculateHealthScore
        $this->setupFindAllReturnsEmpty();

        $filters = $this->createMock(\Doctrine\ORM\Query\FilterCollection::class);
        $filters->method('isEnabled')->willReturn(false);
        $this->entityManager->method('getFilters')->willReturn($filters);

        $score = $this->aggregator->calculateHealthScore(0, 0, 0, 0, 0);

        self::assertSame(100, $score);
    }

    #[Test]
    public function health_score_is_between_0_and_100(): void
    {
        $this->setupFindAllReturnsSomeEntities(10);

        $filters = $this->createMock(\Doctrine\ORM\Query\FilterCollection::class);
        $filters->method('isEnabled')->willReturn(false);
        $this->entityManager->method('getFilters')->willReturn($filters);

        $score = $this->aggregator->calculateHealthScore(5, 3, 2, 1, 4);

        self::assertGreaterThanOrEqual(0, $score);
        self::assertLessThanOrEqual(100, $score);
    }

    #[Test]
    public function health_score_is_lower_when_more_broken_issues(): void
    {
        $this->setupFindAllReturnsSomeEntities(20);

        $filters = $this->createMock(\Doctrine\ORM\Query\FilterCollection::class);
        $filters->method('isEnabled')->willReturn(false);
        $this->entityManager->method('getFilters')->willReturn($filters);

        // Run twice: once with few issues, once with many
        $this->setupFindAllReturnsSomeEntities(20);
        $filters2 = $this->createMock(\Doctrine\ORM\Query\FilterCollection::class);
        $filters2->method('isEnabled')->willReturn(false);

        // We compute directly using the formula: fewer issues → higher score
        // orphaned*3 + broken*5 + missing + duplicates*2 + inconsistent
        // With 0 issues and 50 total entities → 100
        // With many issues and same entities → lower
        $scoreGood = $this->aggregator->calculateHealthScore(0, 0, 0, 0, 0);
        $scoreBad  = $this->aggregator->calculateHealthScore(10, 10, 10, 10, 10);

        self::assertGreaterThan($scoreBad, $scoreGood);
    }

    #[Test]
    public function count_by_tenant_returns_empty_when_no_tenants(): void
    {
        // TenantRepository in aggregator returns [] → no iterations
        $tenantRepo = $this->getMockBuilder(TenantRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $tenantRepo->method('findAll')->willReturn([]);

        $aggregator = new EntityCountAggregator(
            $this->entityManager,
            $tenantRepo,
            $this->createMock(AssetRepository::class),
            $this->createMock(RiskRepository::class),
            $this->createMock(IncidentRepository::class),
            $this->createMock(InternalAuditRepository::class),
            $this->createMock(DocumentRepository::class),
            $this->createMock(TrainingRepository::class),
            $this->createMock(BusinessProcessRepository::class),
            $this->createMock(BusinessContinuityPlanRepository::class),
            $this->createMock(DataBreachRepository::class),
            $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(SupplierRepository::class),
            $this->createMock(LocationRepository::class),
            $this->createMock(PersonRepository::class),
        );

        $counts = $aggregator->countByTenant();

        self::assertSame([], $counts);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    private function setupFindAllReturnsEmpty(): void
    {
        // These repos are called via property access inside calculateHealthScore
        // We rebuild the aggregator with mocks that return empty arrays
        $makeEmptyRepo = function (string $class): MockObject {
            $repo = $this->getMockBuilder($class)->disableOriginalConstructor()->getMock();
            $repo->method('findAll')->willReturn([]);
            return $repo;
        };

        $this->aggregator = new EntityCountAggregator(
            $this->entityManager,
            $makeEmptyRepo(TenantRepository::class),
            $makeEmptyRepo(AssetRepository::class),
            $makeEmptyRepo(RiskRepository::class),
            $makeEmptyRepo(IncidentRepository::class),
            $makeEmptyRepo(InternalAuditRepository::class),
            $makeEmptyRepo(DocumentRepository::class),
            $makeEmptyRepo(TrainingRepository::class),
            $makeEmptyRepo(BusinessProcessRepository::class),
            $makeEmptyRepo(BusinessContinuityPlanRepository::class),
            $makeEmptyRepo(DataBreachRepository::class),
            $makeEmptyRepo(ProcessingActivityRepository::class),
            $makeEmptyRepo(SupplierRepository::class),
            $makeEmptyRepo(LocationRepository::class),
            $makeEmptyRepo(PersonRepository::class),
        );
    }

    private function setupFindAllReturnsSomeEntities(int $count): void
    {
        $entities = array_fill(0, $count, new \stdClass());

        $makeRepo = function (string $class) use ($entities): MockObject {
            $repo = $this->getMockBuilder($class)->disableOriginalConstructor()->getMock();
            $repo->method('findAll')->willReturn($entities);
            return $repo;
        };

        $this->aggregator = new EntityCountAggregator(
            $this->entityManager,
            $makeRepo(TenantRepository::class),
            $makeRepo(AssetRepository::class),
            $makeRepo(RiskRepository::class),
            $makeRepo(IncidentRepository::class),
            $makeRepo(InternalAuditRepository::class),
            $makeRepo(DocumentRepository::class),
            $makeRepo(TrainingRepository::class),
            $makeRepo(BusinessProcessRepository::class),
            $makeRepo(BusinessContinuityPlanRepository::class),
            $makeRepo(DataBreachRepository::class),
            $makeRepo(ProcessingActivityRepository::class),
            $makeRepo(SupplierRepository::class),
            $makeRepo(LocationRepository::class),
            $makeRepo(PersonRepository::class),
        );
    }
}
