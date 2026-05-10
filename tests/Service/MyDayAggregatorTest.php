<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\DataSubjectRequest;
use App\Entity\Document;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Repository\AuditFindingRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\RiskRepository;
use App\Repository\TrainingParticipationRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\MyDayAggregator;
use App\Service\TenantContext;
use DateTimeImmutable;
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
    private MockObject $riskRepo;
    private MockObject $trainingParticipationRepo;
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
        $this->riskRepo = $this->createMock(RiskRepository::class);
        $this->trainingParticipationRepo = $this->createMock(TrainingParticipationRepository::class);
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
            $this->riskRepo,
            $this->trainingParticipationRepo,
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
        $this->riskRepo->method('findAcceptanceExpiring')->willReturn([]);
        $this->documentRepo->method('findReviewOverdue')->willReturn([]);
        $this->trainingParticipationRepo->method('findPendingForUser')->willReturn([]);
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
        // Audit V4 V4-LB-1 — new ISB-Pflicht-Buckets must also respect
        // the missing-tenant short-circuit.
        $this->riskRepo->expects($this->never())->method('findAcceptanceExpiring');
        $this->documentRepo->expects($this->never())->method('findReviewOverdue');
        $this->trainingParticipationRepo->expects($this->never())->method('findPendingForUser');

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
        $this->riskRepo->method('findAcceptanceExpiring')->willReturn([]);
        $this->documentRepo->method('findReviewOverdue')->willReturn([]);
        $this->trainingParticipationRepo->method('findPendingForUser')->willReturn([]);
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
        $this->riskRepo->method('findAcceptanceExpiring')->willReturn([]);
        $this->documentRepo->method('findReviewOverdue')->willReturn([]);
        $this->trainingParticipationRepo->method('findPendingForUser')->willReturn([]);
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

    #[Test]
    public function testRiskAcceptanceExpiringPassesTenantToRepository(): void
    {
        // Audit V4 V4-LB-1 — Risk-Acceptance-Expiry bucket must scope by tenant.
        $tenant = $this->createTenant(1);
        $user = $this->createUser(11, $tenant, ['ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->stubAllRepoEmpty();
        $this->riskRepo->expects($this->once())
            ->method('findAcceptanceExpiring')
            ->with($tenant, 30)
            ->willReturn([]);

        $result = $this->aggregator->aggregate($user);

        self::assertSame(0, $result['summary']['risk_acceptance_expiring']);
    }

    #[Test]
    public function testRiskAcceptanceVisibleToOwner(): void
    {
        $tenant = $this->createTenant(1);
        $owner = $this->createUser(11, $tenant, ['ROLE_USER']);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubOtherReposEmptyExceptRisk();

        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(42);
        $risk->method('getTitle')->willReturn('Acceptance test');
        $risk->method('getRiskOwner')->willReturn($owner);
        $risk->method('getAcceptanceExpiryDate')
            ->willReturn(new \DateTimeImmutable('+5 days'));
        $this->riskRepo->method('findAcceptanceExpiring')->willReturn([$risk]);
        $this->urls->method('generate')->willReturn('/risk/42');

        $result = $this->aggregator->aggregate($owner);

        self::assertSame(1, $result['summary']['risk_acceptance_expiring']);
        self::assertSame('expiring', $result['risk_acceptance_expiring'][0]['badge']);
    }

    #[Test]
    public function testRiskAcceptanceHiddenFromUnrelatedRoleUser(): void
    {
        // Plain ROLE_USER without ownership must NOT see other users' risks.
        $tenant = $this->createTenant(1);
        $owner = $this->createUser(11, $tenant, ['ROLE_USER']);
        $stranger = $this->createUser(99, $tenant, ['ROLE_USER']);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubOtherReposEmptyExceptRisk();

        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(42);
        $risk->method('getTitle')->willReturn('Other-owner risk');
        $risk->method('getRiskOwner')->willReturn($owner);
        $risk->method('getAcceptanceExpiryDate')
            ->willReturn(new \DateTimeImmutable('+5 days'));
        $this->riskRepo->method('findAcceptanceExpiring')->willReturn([$risk]);

        $result = $this->aggregator->aggregate($stranger);

        self::assertSame(0, $result['summary']['risk_acceptance_expiring']);
    }

    #[Test]
    public function testDocumentReviewOverduePassesTenantToRepository(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(11, $tenant, ['ROLE_MANAGER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->stubAllRepoEmpty();
        $this->documentRepo->expects($this->once())
            ->method('findReviewOverdue')
            ->with($tenant)
            ->willReturn([]);

        $result = $this->aggregator->aggregate($user);

        self::assertSame(0, $result['summary']['documents_review_overdue']);
    }

    #[Test]
    public function testDocumentReviewOverdueSurfacesToManager(): void
    {
        $tenant = $this->createTenant(1);
        $manager = $this->createUser(11, $tenant, ['ROLE_MANAGER', 'ROLE_USER']);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubOtherReposEmptyExceptDocReview();

        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn(7);
        $doc->method('getOriginalFilename')->willReturn('Policy-IT.pdf');
        $doc->method('getNextReviewDate')
            ->willReturn(new \DateTimeImmutable('-3 days'));
        $this->documentRepo->method('findReviewOverdue')->willReturn([$doc]);
        $this->urls->method('generate')->willReturn('/document/7');

        $result = $this->aggregator->aggregate($manager);

        self::assertSame(1, $result['summary']['documents_review_overdue']);
        self::assertSame('Policy-IT.pdf', $result['documents_review_overdue'][0]['title']);
    }

    #[Test]
    public function testTrainingsPendingPassesTenantAndUserToRepository(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(11, $tenant, ['ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->stubAllRepoEmpty();
        $this->trainingParticipationRepo->expects($this->once())
            ->method('findPendingForUser')
            ->with($user, $tenant)
            ->willReturn([]);

        $result = $this->aggregator->aggregate($user);

        self::assertSame(0, $result['summary']['trainings_pending']);
    }

    #[Test]
    public function testTrainingsPendingSurfacesToAssignee(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(11, $tenant, ['ROLE_USER']);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubOtherReposEmptyExceptTraining();

        $training = $this->createMock(Training::class);
        $training->method('getId')->willReturn(3);
        $training->method('getTitle')->willReturn('ISMS-Awareness');

        $participation = $this->createMock(TrainingParticipation::class);
        $participation->method('getTraining')->willReturn($training);
        $participation->method('getAssignedAt')
            ->willReturn(new DateTimeImmutable('-2 days'));

        $this->trainingParticipationRepo->method('findPendingForUser')
            ->willReturn([$participation]);
        $this->urls->method('generate')->willReturn('/training/3');

        $result = $this->aggregator->aggregate($user);

        self::assertSame(1, $result['summary']['trainings_pending']);
        self::assertSame('ISMS-Awareness', $result['trainings_pending'][0]['title']);
    }

    /**
     * Stub the universally-empty buckets — leaves the new ISB buckets free
     * for per-test specialization. (PHPUnit's MockBuilder can't be re-bound
     * to the same method twice; once the stub is set it sticks.)
     */
    private function stubAllRepoEmpty(): void
    {
        $this->workflowInstances->method('findPendingForUser')->willReturn([]);
        $this->workflowInstances->method('findOverdueForTenant')->willReturn([]);
        $this->fourEyesRepo->method('findPendingFor')->willReturn([]);
        $this->auditFindingRepo->method('findOpenByTenant')->willReturn([]);
        $this->dsrRepo->method('findByTenant')->willReturn([]);
        $this->caRepo->method('findOverdue')->willReturn([]);
        $this->riskRepo->method('findAcceptanceExpiring')->willReturn([]);
        $this->documentRepo->method('findReviewOverdue')->willReturn([]);
        $this->trainingParticipationRepo->method('findPendingForUser')->willReturn([]);
        $this->stubEmptyDocumentQuery();
    }

    private function stubOtherReposEmptyExceptRisk(): void
    {
        $this->workflowInstances->method('findPendingForUser')->willReturn([]);
        $this->workflowInstances->method('findOverdueForTenant')->willReturn([]);
        $this->fourEyesRepo->method('findPendingFor')->willReturn([]);
        $this->auditFindingRepo->method('findOpenByTenant')->willReturn([]);
        $this->dsrRepo->method('findByTenant')->willReturn([]);
        $this->caRepo->method('findOverdue')->willReturn([]);
        $this->documentRepo->method('findReviewOverdue')->willReturn([]);
        $this->trainingParticipationRepo->method('findPendingForUser')->willReturn([]);
        $this->stubEmptyDocumentQuery();
    }

    private function stubOtherReposEmptyExceptDocReview(): void
    {
        $this->workflowInstances->method('findPendingForUser')->willReturn([]);
        $this->workflowInstances->method('findOverdueForTenant')->willReturn([]);
        $this->fourEyesRepo->method('findPendingFor')->willReturn([]);
        $this->auditFindingRepo->method('findOpenByTenant')->willReturn([]);
        $this->dsrRepo->method('findByTenant')->willReturn([]);
        $this->caRepo->method('findOverdue')->willReturn([]);
        $this->riskRepo->method('findAcceptanceExpiring')->willReturn([]);
        $this->trainingParticipationRepo->method('findPendingForUser')->willReturn([]);
        $this->stubEmptyDocumentQuery();
    }

    private function stubOtherReposEmptyExceptTraining(): void
    {
        $this->workflowInstances->method('findPendingForUser')->willReturn([]);
        $this->workflowInstances->method('findOverdueForTenant')->willReturn([]);
        $this->fourEyesRepo->method('findPendingFor')->willReturn([]);
        $this->auditFindingRepo->method('findOpenByTenant')->willReturn([]);
        $this->dsrRepo->method('findByTenant')->willReturn([]);
        $this->caRepo->method('findOverdue')->willReturn([]);
        $this->riskRepo->method('findAcceptanceExpiring')->willReturn([]);
        $this->documentRepo->method('findReviewOverdue')->willReturn([]);
        $this->stubEmptyDocumentQuery();
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
