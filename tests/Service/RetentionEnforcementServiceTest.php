<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Asset;
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

    /** @param list<object> $rows */
    private function stubQuery(array $rows): void
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn($rows);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'setParameter'] as $fluent) {
            $qb->method($fluent)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        $this->entityManager->method('createQueryBuilder')->willReturn($qb);
    }

    private function asset(string $status): Asset
    {
        // The query selects by age; the service re-checks that the record ended before the cutoff.
        return (new Asset())->setStatus($status)->setCreatedAt(new \DateTime('-3 years'));
    }

    #[Test]
    public function testDryRunReportsOnlyEndStateRecordsAsExpired(): void
    {
        $this->tenantRepository->method('findAll')->willReturn([
            $this->tenantWithPolicies(['asset' => ['retention_days' => 365, 'auto_delete' => true]]),
        ]);
        $this->stubQuery([$this->asset('disposed'), $this->asset('disposed'), $this->asset('active')]);

        // Dry run: nothing is removed.
        $this->entityManager->expects($this->never())->method('remove');

        $report = $this->service->enforce(false);

        $this->assertCount(1, $report);
        $this->assertSame('asset', $report[0]['entity_type']);
        $this->assertSame(2, $report[0]['expired']);
        $this->assertSame(0, $report[0]['deleted']);
        $this->assertStringContainsString('1 overdue for retention, still active', (string) $report[0]['note']);
    }

    #[Test]
    public function testActiveRecordsAreNeverDeletedEndStateRecordsAre(): void
    {
        $tenant = $this->tenantWithPolicies(['asset' => ['retention_days' => 365, 'auto_delete' => true]]);
        $this->tenantRepository->method('findAll')->willReturn([$tenant]);

        $disposed = $this->asset('disposed');
        $active = $this->asset('active');
        $retired = $this->asset('retired'); // disposal still needs the four-eyes step
        $this->stubQuery([$disposed, $active, $retired]);

        $removed = [];
        $this->entityManager->method('remove')->willReturnCallback(function (object $o) use (&$removed): void {
            $removed[] = $o;
        });

        // Audit trail goes through logBulk, pinned to the tenant.
        $this->auditLogger->expects($this->once())->method('withTenant')
            ->with($tenant, $this->isCallable())
            ->willReturnCallback(static fn ($t, callable $cb): mixed => $cb());
        $this->auditLogger->expects($this->once())->method('logBulk')
            ->with(
                'retention.enforced',
                Asset::class,
                $this->callback(static fn (array $b): bool => $b['deleted'] === 1 && $b['tenant'] === 1),
                $this->callback(static fn (array $rows): bool => count($rows) === 1 && $rows[0]['action'] === 'delete'),
            )
            ->willReturn('batch');
        $this->auditLogger->expects($this->never())->method('logCustom');

        $report = $this->service->enforce(true);

        $this->assertSame([$disposed], $removed);
        $this->assertSame(1, $report[0]['deleted']);
        $this->assertStringContainsString('2 overdue for retention, still active', (string) $report[0]['note']);
    }

    #[Test]
    public function testEveryEntityTypeHasEndStates(): void
    {
        $this->assertSame(
            array_keys(RetentionEnforcementService::REGISTRY),
            array_keys(RetentionEnforcementService::END_STATES),
        );
        foreach (RetentionEnforcementService::END_STATES as $states) {
            $this->assertNotSame([], $states);
        }
    }

    #[Test]
    public function retentionRunsFromCompletionNotFromCreation(): void
    {
        // W3: an asset created years ago but disposed last week is not due yet.
        $this->tenantRepository->method('findAll')->willReturn([
            $this->tenantWithPolicies(['asset' => ['retention_days' => 365, 'auto_delete' => true]]),
        ]);
        $old = $this->asset('disposed')->setCreatedAt(new \DateTime('-5 years'))->setUpdatedAt(new \DateTime('-3 years'));
        $justDisposed = $this->asset('disposed')->setCreatedAt(new \DateTime('-5 years'))->setUpdatedAt(new \DateTime('-7 days'));
        $this->stubQuery([$old, $justDisposed]);

        $report = $this->service->enforce(false);

        $this->assertSame(1, $report[0]['expired']);
    }

    #[Test]
    public function aRecentAuditRowKeepsTheRecord(): void
    {
        $tenant = $this->tenantWithPolicies(['incident' => ['retention_days' => 365, 'auto_delete' => true]]);
        $this->tenantRepository->method('findAll')->willReturn([$tenant]);
        $incident = (new \App\Entity\Incident())->setStatus(\App\Enum\IncidentStatus::Closed)
            ->setCreatedAt(new \DateTime('-5 years'))->setClosedAt(new \DateTime('-4 years'));
        (new \ReflectionProperty(\App\Entity\Incident::class, 'id'))->setValue($incident, 41);
        $this->stubQuery([$incident]);

        $audit = $this->createMock(\App\Repository\AuditLogRepository::class);
        $audit->method('lastChangeAt')->with('Incident', [41])->willReturn([41 => new \DateTimeImmutable('-2 days')]);
        $service = new RetentionEnforcementService($this->entityManager, $this->tenantRepository, $this->auditLogger,
            $this->createMock(LoggerInterface::class), $audit);

        $this->entityManager->expects($this->never())->method('remove');
        $this->assertSame(0, $service->enforce(true)[0]['expired']);
    }
}
