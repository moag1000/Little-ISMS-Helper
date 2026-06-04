<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\AuditLogger;
use App\Service\RetentionEnforcementService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GDPR Art. 5(1)(e) — data-retention enforcement (audit finding M-4).
 */
#[AllowMockObjectsWithoutExpectations]
class RetentionEnforcementServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $tenantRepository;
    private MockObject $auditLogger;
    private RetentionEnforcementService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->tenantRepository = $this->createMock(TenantRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $this->service = new RetentionEnforcementService(
            $this->entityManager,
            $this->tenantRepository,
            $this->auditLogger,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function tenantWithPolicies(array $policies): MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $tenant->method('getDataRetentionPolicies')->willReturn($policies);

        return $tenant;
    }

    #[Test]
    public function testSkipsEntityTypesWithoutAutoDelete(): void
    {
        $this->tenantRepository->method('findAll')->willReturn([
            $this->tenantWithPolicies(['asset' => ['retention_days' => 365, 'auto_delete' => false]]),
        ]);
        // No query is ever built for a non-opted-in type.
        $this->entityManager->expects($this->never())->method('createQueryBuilder');

        $this->assertSame([], $this->service->enforce(false));
    }

    #[Test]
    public function testSkipsZeroRetention(): void
    {
        $this->tenantRepository->method('findAll')->willReturn([
            $this->tenantWithPolicies(['asset' => ['retention_days' => 0, 'auto_delete' => true]]),
        ]);
        $this->entityManager->expects($this->never())->method('createQueryBuilder');

        $this->assertSame([], $this->service->enforce(false));
    }

    #[Test]
    public function testReportsNoEnforcerForUnknownType(): void
    {
        $this->tenantRepository->method('findAll')->willReturn([
            $this->tenantWithPolicies(['mystery' => ['retention_days' => 30, 'auto_delete' => true]]),
        ]);
        $this->entityManager->expects($this->never())->method('createQueryBuilder');

        $report = $this->service->enforce(false);

        $this->assertCount(1, $report);
        $this->assertSame('mystery', $report[0]['entity_type']);
        $this->assertSame('no enforcer (manual cleanup required)', $report[0]['note']);
    }

    #[Test]
    public function testDryRunReportsExpiredCountWithoutDeleting(): void
    {
        $this->tenantRepository->method('findAll')->willReturn([
            $this->tenantWithPolicies(['asset' => ['retention_days' => 365, 'auto_delete' => true]]),
        ]);

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn([new \stdClass(), new \stdClass()]);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'setParameter'] as $fluent) {
            $qb->method($fluent)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        $this->entityManager->method('createQueryBuilder')->willReturn($qb);

        // Dry run: nothing is removed.
        $this->entityManager->expects($this->never())->method('remove');

        $report = $this->service->enforce(false);

        $this->assertCount(1, $report);
        $this->assertSame('asset', $report[0]['entity_type']);
        $this->assertSame(2, $report[0]['expired']);
        $this->assertSame(0, $report[0]['deleted']);
    }
}
