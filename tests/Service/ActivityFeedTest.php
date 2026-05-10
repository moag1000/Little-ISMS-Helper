<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\WorkflowInstance;
use App\Repository\AuditLogRepository;
use App\Repository\DocumentRepository;
use App\Repository\RiskRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\ActivityFeed;
use App\Service\TenantContext;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Audit V3 W2-C1 — ActivityFeed tenant-scoping regression test.
 *
 * Validates that {@see ActivityFeed::recent()} consults the
 * tenant-scoped repository methods and does NOT call
 * {@see AuditLogRepository::findAllOrdered()} or
 * {@see WorkflowInstanceRepository::findActive()} (cross-tenant queries).
 */
#[AllowMockObjectsWithoutExpectations]
final class ActivityFeedTest extends TestCase
{
    private MockObject $auditLogRepo;
    private MockObject $workflowRepo;
    private MockObject $documentRepo;
    private MockObject $riskRepo;
    private MockObject $tenantContext;
    private MockObject $urlGenerator;
    private ActivityFeed $feed;

    protected function setUp(): void
    {
        $this->auditLogRepo = $this->createMock(AuditLogRepository::class);
        $this->workflowRepo = $this->createMock(WorkflowInstanceRepository::class);
        $this->documentRepo = $this->createMock(DocumentRepository::class);
        $this->riskRepo = $this->createMock(RiskRepository::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->urlGenerator = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);

        $this->feed = new ActivityFeed(
            $this->auditLogRepo,
            $this->workflowRepo,
            $this->documentRepo,
            $this->riskRepo,
            $this->tenantContext,
            $this->urlGenerator,
        );
    }

    #[Test]
    public function testRecentScopesAuditLogToActiveTenant(): void
    {
        $tenant = $this->createTenant(42);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $log = $this->createConfiguredMock(AuditLog::class, [
            'getAction' => 'create',
            'getEntityType' => 'Risk',
            'getDescription' => 'created risk #1',
            'getCreatedAt' => new \DateTimeImmutable('-1 hour'),
            'getUserName' => 'alice@a.example',
        ]);

        // Tenant-scoped method MUST be invoked with the tenant.
        $this->auditLogRepo->expects($this->once())
            ->method('findAllOrderedForTenant')
            ->with($tenant, 50, 0)
            ->willReturn([$log]);

        // Untenanted method MUST NOT be invoked (regression guard for W2-C1).
        $this->auditLogRepo->expects($this->never())->method('findAllOrdered');

        // Workflows: tenant-scoped only.
        $this->workflowRepo->expects($this->once())
            ->method('findActiveForTenant')
            ->with($tenant)
            ->willReturn([]);
        $this->workflowRepo->expects($this->never())->method('findActive');

        // Document/Risk QB stubs: empty results to keep test focused on queries above.
        $this->stubEmptyQueryBuilder($this->documentRepo, 'd');
        $this->stubEmptyQueryBuilder($this->riskRepo, 'r');

        $result = $this->feed->recent();

        self::assertNotEmpty($result);
        self::assertSame('audit_log', $result[0]['source']);
    }

    #[Test]
    public function testRecentReturnsEmptyWithoutTenantContext(): void
    {
        // No active tenant — must NOT call any cross-tenant repo method.
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $this->auditLogRepo->expects($this->never())->method('findAllOrdered');
        $this->auditLogRepo->expects($this->never())->method('findAllOrderedForTenant');
        $this->workflowRepo->expects($this->never())->method('findActive');
        $this->workflowRepo->expects($this->never())->method('findActiveForTenant');

        $result = $this->feed->recent();

        self::assertSame([], $result);
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new \ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        return $tenant;
    }

    private function stubEmptyQueryBuilder(MockObject $repo, string $alias): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn([]);
        $qb->method('getQuery')->willReturn($query);
        $repo->method('createQueryBuilder')->willReturn($qb);
    }
}
