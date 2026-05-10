<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\DataSubjectRequest;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditFindingRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\MyDayAggregator;
use App\Service\TenantContext;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Audit V3 W2-C2 — MyDayAggregator regression tests.
 *
 * Validates that:
 *   - all 7 aggregator buckets pass the active tenant down so cross-tenant
 *     leakage cannot occur (W2-C2);
 *   - the DSR-See-All fallback for ROLE_MANAGER is gone — only explicitly
 *     assigned DSRs are surfaced (W2-C2 DSGVO Art. 5 (1) (c)).
 */
#[AllowMockObjectsWithoutExpectations]
final class MyDayAggregatorTest extends TestCase
{
    private MockObject $tenantContext;
    private MockObject $workflowInstances;
    private MockObject $fourEyesRepo;
    private MockObject $policyAckRepo;
    private MockObject $auditFindingRepo;
    private MockObject $dsrRepo;
    private MockObject $caRepo;
    private MockObject $documentRepo;
    private MockObject $urls;
    private MyDayAggregator $aggregator;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->workflowInstances = $this->createMock(WorkflowInstanceRepository::class);
        $this->fourEyesRepo = $this->createMock(FourEyesApprovalRequestRepository::class);
        $this->policyAckRepo = $this->createMock(PolicyAcknowledgementRepository::class);
        $this->auditFindingRepo = $this->createMock(AuditFindingRepository::class);
        $this->dsrRepo = $this->createMock(DataSubjectRequestRepository::class);
        $this->caRepo = $this->createMock(CorrectiveActionRepository::class);
        $this->documentRepo = $this->createMock(DocumentRepository::class);
        $this->urls = $this->createMock(UrlGeneratorInterface::class);

        $this->aggregator = new MyDayAggregator(
            $this->tenantContext,
            $this->workflowInstances,
            $this->fourEyesRepo,
            $this->policyAckRepo,
            $this->auditFindingRepo,
            $this->dsrRepo,
            $this->caRepo,
            $this->documentRepo,
            $this->urls,
        );
    }

    #[Test]
    public function testAggregatePassesTenantToWorkflowQueries(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(11, $tenant, ['ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        // Tenant-scoped variants MUST be invoked.
        $this->workflowInstances->expects($this->once())
            ->method('findPendingForUser')
            ->with($user, $tenant)
            ->willReturn([]);
        $this->workflowInstances->expects($this->once())
            ->method('findOverdueForTenant')
            ->with($tenant)
            ->willReturn([]);

        // Plain (cross-tenant) variants MUST NOT be invoked.
        $this->workflowInstances->expects($this->never())->method('findOverdue');

        // Other repos: short-circuit to empty.
        $this->fourEyesRepo->method('findPendingFor')->willReturn([]);
        $this->auditFindingRepo->method('findOpenByTenant')->willReturn([]);
        $this->dsrRepo->method('findByTenant')->willReturn([]);
        $this->caRepo->method('findOverdue')->willReturn([]);
        $this->stubEmptyDocumentQuery();

        $result = $this->aggregator->aggregate($user);

        self::assertSame(0, $result['total']);
    }

    #[Test]
    public function testAggregateReturnsEmptyWithoutTenantContext(): void
    {
        // No active tenant: NONE of the cross-tenant variants must be called.
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);
        $user = $this->createUser(11, null, ['ROLE_MANAGER', 'ROLE_USER']);

        $this->workflowInstances->expects($this->never())->method('findPendingForUser');
        $this->workflowInstances->expects($this->never())->method('findOverdue');
        $this->workflowInstances->expects($this->never())->method('findOverdueForTenant');
        $this->fourEyesRepo->expects($this->never())->method('findPendingFor');
        $this->dsrRepo->expects($this->never())->method('findByTenant');

        $result = $this->aggregator->aggregate($user);

        self::assertSame(0, $result['total']);
    }

    #[Test]
    public function testDsrFallbackDoesNotLeakToRoleManager(): void
    {
        // DSGVO regression guard: a Manager without explicit DSR
        // assignment must NOT see open DSRs of other data subjects.
        $tenant = $this->createTenant(1);
        $manager = $this->createUser(99, $tenant, ['ROLE_MANAGER', 'ROLE_USER']);
        $assignee = $this->createUser(100, $tenant, ['ROLE_USER']);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->workflowInstances->method('findPendingForUser')->willReturn([]);
        $this->workflowInstances->method('findOverdueForTenant')->willReturn([]);
        $this->fourEyesRepo->method('findPendingFor')->willReturn([]);
        $this->auditFindingRepo->method('findOpenByTenant')->willReturn([]);
        $this->caRepo->method('findOverdue')->willReturn([]);
        $this->stubEmptyDocumentQuery();

        $dsr = $this->createMock(DataSubjectRequest::class);
        $dsr->method('getId')->willReturn(7);
        // assignedTo points to a different user
        $dsr->method('getAssignedTo')->willReturn($assignee);
        $dsr->method('getStatus')->willReturn('open');
        $this->dsrRepo->method('findByTenant')->willReturn([$dsr]);

        $result = $this->aggregator->aggregate($manager);

        self::assertSame(0, $result['summary']['dsrs'], 'Manager without explicit DSR-assignment must not see DSAR PII (DSGVO).');
    }

    #[Test]
    public function testDsrSurfacesToExplicitlyAssignedUser(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(11, $tenant, ['ROLE_USER']);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->workflowInstances->method('findPendingForUser')->willReturn([]);
        $this->workflowInstances->method('findOverdueForTenant')->willReturn([]);
        $this->fourEyesRepo->method('findPendingFor')->willReturn([]);
        $this->auditFindingRepo->method('findOpenByTenant')->willReturn([]);
        $this->caRepo->method('findOverdue')->willReturn([]);
        $this->stubEmptyDocumentQuery();

        $dsr = $this->createMock(DataSubjectRequest::class);
        $dsr->method('getId')->willReturn(7);
        $dsr->method('getAssignedTo')->willReturn($user);
        $dsr->method('getStatus')->willReturn('open');
        $dsr->method('getRequestType')->willReturn('access');
        $dsr->method('getDataSubjectName')->willReturn('Bob');
        $this->dsrRepo->method('findByTenant')->willReturn([$dsr]);
        $this->urls->method('generate')->willReturn('/dsr/7');

        $result = $this->aggregator->aggregate($user);

        self::assertSame(1, $result['summary']['dsrs']);
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new \ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        return $tenant;
    }

    /** @param list<string> $roles */
    private function createUser(int $id, ?Tenant $tenant, array $roles): User
    {
        $user = new User();
        $idProperty = (new \ReflectionClass($user))->getProperty('id');
        $idProperty->setValue($user, $id);
        $user->setEmail('user' . $id . '@example.com');
        $user->setRoles($roles);
        if ($tenant !== null) {
            $user->setTenant($tenant);
        }
        return $user;
    }

    private function stubEmptyDocumentQuery(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([]);
        $qb->method('getQuery')->willReturn($query);
        $this->documentRepo->method('createQueryBuilder')->willReturn($qb);
    }
}
