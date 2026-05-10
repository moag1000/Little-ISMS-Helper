<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditChecklist;
use App\Entity\ChangeRequest;
use App\Entity\ComplianceRequirement;
use App\Entity\DataBreach;
use App\Entity\DataSubjectRequest;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\ManagementReview;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\Entity\Vulnerability;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Repository\AuditChecklistRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\ChangeRequestRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\DataBreachRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Repository\IncidentRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\RiskRepository;
use App\Repository\TrainingParticipationRepository;
use App\Repository\VulnerabilityRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\MyDayAggregator;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
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
    private MockObject $incidentRepo;
    private MockObject $dataBreachRepo;
    private MockObject $vulnerabilityRepo;
    private MockObject $auditChecklistRepo;
    private MockObject $changeRequestRepo;
    private MockObject $managementReviewRepo;
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
        $this->incidentRepo = $this->createMock(IncidentRepository::class);
        $this->dataBreachRepo = $this->createMock(DataBreachRepository::class);
        $this->vulnerabilityRepo = $this->createMock(VulnerabilityRepository::class);
        $this->auditChecklistRepo = $this->createMock(AuditChecklistRepository::class);
        $this->changeRequestRepo = $this->createMock(ChangeRequestRepository::class);
        $this->managementReviewRepo = $this->createMock(ManagementReviewRepository::class);
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
            $this->incidentRepo,
            $this->dataBreachRepo,
            $this->vulnerabilityRepo,
            $this->auditChecklistRepo,
            $this->changeRequestRepo,
            $this->managementReviewRepo,
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
        $this->incidentRepo->method('findOpenIncidents')->willReturn([]);
        $this->incidentRepo->method('findOpenAssignedToUser')->willReturn([]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->vulnerabilityRepo->method('findCriticalUnpatchedByTenant')->willReturn([]);
        $this->auditChecklistRepo->method('findDueForUser')->willReturn([]);
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([]);
        $this->managementReviewRepo->method('findUpcomingByTenant')->willReturn([]);
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
        // Audit V4 V4-LB-1 Round-2 — Tagesgeschäft buckets must short-circuit too.
        $this->incidentRepo->expects($this->never())->method('findOpenIncidents');
        $this->incidentRepo->expects($this->never())->method('findOpenAssignedToUser');
        $this->dataBreachRepo->expects($this->never())->method('findAuthorityNotification72hTicking');
        $this->vulnerabilityRepo->expects($this->never())->method('findCriticalUnpatchedByTenant');
        $this->auditChecklistRepo->expects($this->never())->method('findDueForUser');
        $this->changeRequestRepo->expects($this->never())->method('findPendingApprovalByTenant');
        $this->managementReviewRepo->expects($this->never())->method('findUpcomingByTenant');

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
        $this->incidentRepo->method('findOpenIncidents')->willReturn([]);
        $this->incidentRepo->method('findOpenAssignedToUser')->willReturn([]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->vulnerabilityRepo->method('findCriticalUnpatchedByTenant')->willReturn([]);
        $this->auditChecklistRepo->method('findDueForUser')->willReturn([]);
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([]);
        $this->managementReviewRepo->method('findUpcomingByTenant')->willReturn([]);
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
        $this->incidentRepo->method('findOpenIncidents')->willReturn([]);
        $this->incidentRepo->method('findOpenAssignedToUser')->willReturn([]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->vulnerabilityRepo->method('findCriticalUnpatchedByTenant')->willReturn([]);
        $this->auditChecklistRepo->method('findDueForUser')->willReturn([]);
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([]);
        $this->managementReviewRepo->method('findUpcomingByTenant')->willReturn([]);
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

    // -----------------------------------------------------------------
    //  Audit V4 V4-LB-1 Round-2 — 6 new ISB-Tagesgeschäft buckets.
    // -----------------------------------------------------------------

    #[Test]
    public function testIncidentsOpenAssignedSurfacesToAssignee(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(11, $tenant, ['ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubAllExceptIncident();

        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn(50);
        $incident->method('getIncidentNumber')->willReturn('INC-2026-0001');
        $incident->method('getTitle')->willReturn('Phishing wave');
        $incident->method('getCategory')->willReturn('phishing');
        $incident->method('getSeverity')->willReturn(IncidentSeverity::High);
        $incident->method('getStatus')->willReturn(IncidentStatus::InInvestigation);
        $incident->method('getDetectedAt')->willReturn(new DateTimeImmutable('-2 days'));
        // ROLE_USER: must call the user-scoped variant.
        $this->incidentRepo->expects($this->once())
            ->method('findOpenAssignedToUser')
            ->with($user, $tenant)
            ->willReturn([$incident]);
        $this->urls->method('generate')->willReturn('/incident/50');

        $result = $this->aggregator->aggregate($user);

        self::assertSame(1, $result['summary']['incidents_open_assigned']);
        self::assertSame('high', $result['incidents_open_assigned'][0]['priority']);
        self::assertSame('danger', $result['incidents_open_assigned'][0]['tone']);
    }

    #[Test]
    public function testDataBreaches72hSurfacesToDpoOnly(): void
    {
        $tenant = $this->createTenant(1);
        $regularUser = $this->createUser(11, $tenant, ['ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubAllExceptBreach();

        $breach = $this->createMock(DataBreach::class);
        $breach->method('getId')->willReturn(7);
        $breach->method('getReferenceNumber')->willReturn('BREACH-2026-001');
        $breach->method('getTitle')->willReturn('SaaS Backup-Leak');
        $breach->method('getSeverity')->willReturn('high');
        $breach->method('getDetectedAt')->willReturn(new DateTimeImmutable('-12 hours'));
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')
            ->willReturn([$breach]);
        $this->urls->method('generate')->willReturn('/data-breach/7');

        $resultRegular = $this->aggregator->aggregate($regularUser);
        self::assertSame(0, $resultRegular['summary']['data_breaches_72h_ticking'],
            'Regular user must NOT see DataBreach details (DSGVO Art. 5 (1) (c)).');
    }

    #[Test]
    public function testDataBreaches72hSurfacesToDpoRole(): void
    {
        $tenant = $this->createTenant(1);
        $dpo = $this->createUser(12, $tenant, ['ROLE_DPO', 'ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubAllExceptBreach();

        $breach = $this->createMock(DataBreach::class);
        $breach->method('getId')->willReturn(7);
        $breach->method('getReferenceNumber')->willReturn('BREACH-2026-001');
        $breach->method('getTitle')->willReturn('SaaS Backup-Leak');
        $breach->method('getSeverity')->willReturn('high');
        $breach->method('getDetectedAt')->willReturn(new DateTimeImmutable('-12 hours'));
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')
            ->willReturn([$breach]);
        $this->urls->method('generate')->willReturn('/data-breach/7');

        $result = $this->aggregator->aggregate($dpo);
        self::assertSame(1, $result['summary']['data_breaches_72h_ticking']);
        self::assertSame('72h', $result['data_breaches_72h_ticking'][0]['badge']);
    }

    #[Test]
    public function testVulnerabilitiesCriticalSurfacesToCiso(): void
    {
        $tenant = $this->createTenant(1);
        $ciso = $this->createUser(11, $tenant, ['ROLE_GROUP_CISO', 'ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubAllExceptVuln();

        $vuln = $this->createMock(Vulnerability::class);
        $vuln->method('getId')->willReturn(99);
        $vuln->method('getCveId')->willReturn('CVE-2026-1234');
        $vuln->method('getTitle')->willReturn('Critical RCE in dependency');
        $vuln->method('getSeverity')->willReturn('critical');
        $vuln->method('getCvssScore')->willReturn('9.8');
        $vuln->method('getRemediationDeadline')->willReturn(new DateTimeImmutable('+3 days'));
        $vuln->method('getResponsiblePerson')->willReturn(null);
        $this->vulnerabilityRepo->expects($this->once())
            ->method('findCriticalUnpatchedByTenant')
            ->with($tenant)
            ->willReturn([$vuln]);
        $this->urls->method('generate')->willReturn('/vulnerability/99');

        $result = $this->aggregator->aggregate($ciso);
        self::assertSame(1, $result['summary']['vulnerabilities_critical_unpatched']);
        self::assertSame('danger', $result['vulnerabilities_critical_unpatched'][0]['tone']);
    }

    #[Test]
    public function testAuditChecklistDuePassesUserAndTenant(): void
    {
        $tenant = $this->createTenant(1);
        $auditor = $this->createUser(11, $tenant, ['ROLE_AUDITOR', 'ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubAllExceptChecklist();

        $audit = $this->createMock(InternalAudit::class);
        $audit->method('getId')->willReturn(7);
        $audit->method('getTitle')->willReturn('Internal-Audit Q2');
        $audit->method('getPlannedDate')->willReturn(new DateTimeImmutable('+3 days'));
        $req = $this->createMock(ComplianceRequirement::class);
        $req->method('getRequirementId')->willReturn('A.5.1');
        $req->method('getTitle')->willReturn('Information security policies');
        $checklistItem = $this->createMock(AuditChecklist::class);
        $checklistItem->method('getAudit')->willReturn($audit);
        $checklistItem->method('getRequirement')->willReturn($req);
        $checklistItem->method('getVerificationStatus')->willReturn('not_checked');

        $this->auditChecklistRepo->expects($this->once())
            ->method('findDueForUser')
            ->with($auditor, $tenant, 7)
            ->willReturn([$checklistItem]);
        $this->urls->method('generate')->willReturn('/audit/7/checklist');

        $result = $this->aggregator->aggregate($auditor);
        self::assertSame(1, $result['summary']['audit_checklist_due']);
    }

    #[Test]
    public function testChangeRequestsPendingHiddenFromRegularUser(): void
    {
        $tenant = $this->createTenant(1);
        $regular = $this->createUser(11, $tenant, ['ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubAllExceptChange();

        // Repo returns 1 pending change — but ROLE_USER must NOT see it.
        $cr = $this->createMock(ChangeRequest::class);
        $cr->method('getId')->willReturn(13);
        $cr->method('getChangeNumber')->willReturn('CHG-2026-013');
        $cr->method('getTitle')->willReturn('Firewall ruleset update');
        $cr->method('getStatus')->willReturn('submitted');
        $cr->method('getPriority')->willReturn('high');
        $cr->method('getChangeType')->willReturn('infrastructure');
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([$cr]);

        $result = $this->aggregator->aggregate($regular);
        self::assertSame(0, $result['summary']['change_requests_pending_approval'],
            'ROLE_USER without governance role must NOT see CR-Approval-Inbox.');
    }

    #[Test]
    public function testManagementReviewUpcomingSurfacesToManager(): void
    {
        $tenant = $this->createTenant(1);
        $mgr = $this->createUser(11, $tenant, ['ROLE_MANAGER', 'ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubAllExceptReview();

        $review = $this->createMock(ManagementReview::class);
        $review->method('getId')->willReturn(2);
        $review->method('getTitle')->willReturn('Q1 Management Review 2026');
        $review->method('getStatus')->willReturn('planned');
        $review->method('getReviewDate')->willReturn(new DateTimeImmutable('+30 days'));
        $review->method('getReviewedBy')->willReturn(null);
        $review->method('getParticipants')->willReturn(new ArrayCollection([]));
        $this->managementReviewRepo->expects($this->once())
            ->method('findUpcomingByTenant')
            ->with($tenant, 90)
            ->willReturn([$review]);
        $this->urls->method('generate')->willReturn('/management-review/2');

        $result = $this->aggregator->aggregate($mgr);
        self::assertSame(1, $result['summary']['management_review_upcoming']);
        self::assertSame('Q1 Management Review 2026',
            $result['management_review_upcoming'][0]['title']);
    }

    // ------------- Round-2 stub helpers -----------------

    private function stubAllExceptIncident(): void
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
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->vulnerabilityRepo->method('findCriticalUnpatchedByTenant')->willReturn([]);
        $this->auditChecklistRepo->method('findDueForUser')->willReturn([]);
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([]);
        $this->managementReviewRepo->method('findUpcomingByTenant')->willReturn([]);
        $this->stubEmptyDocumentQuery();
    }

    private function stubAllExceptBreach(): void
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
        $this->incidentRepo->method('findOpenIncidents')->willReturn([]);
        $this->incidentRepo->method('findOpenAssignedToUser')->willReturn([]);
        $this->vulnerabilityRepo->method('findCriticalUnpatchedByTenant')->willReturn([]);
        $this->auditChecklistRepo->method('findDueForUser')->willReturn([]);
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([]);
        $this->managementReviewRepo->method('findUpcomingByTenant')->willReturn([]);
        $this->stubEmptyDocumentQuery();
    }

    private function stubAllExceptVuln(): void
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
        $this->incidentRepo->method('findOpenIncidents')->willReturn([]);
        $this->incidentRepo->method('findOpenAssignedToUser')->willReturn([]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->auditChecklistRepo->method('findDueForUser')->willReturn([]);
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([]);
        $this->managementReviewRepo->method('findUpcomingByTenant')->willReturn([]);
        $this->stubEmptyDocumentQuery();
    }

    private function stubAllExceptChecklist(): void
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
        $this->incidentRepo->method('findOpenIncidents')->willReturn([]);
        $this->incidentRepo->method('findOpenAssignedToUser')->willReturn([]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->vulnerabilityRepo->method('findCriticalUnpatchedByTenant')->willReturn([]);
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([]);
        $this->managementReviewRepo->method('findUpcomingByTenant')->willReturn([]);
        $this->stubEmptyDocumentQuery();
    }

    private function stubAllExceptChange(): void
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
        $this->incidentRepo->method('findOpenIncidents')->willReturn([]);
        $this->incidentRepo->method('findOpenAssignedToUser')->willReturn([]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->vulnerabilityRepo->method('findCriticalUnpatchedByTenant')->willReturn([]);
        $this->auditChecklistRepo->method('findDueForUser')->willReturn([]);
        $this->managementReviewRepo->method('findUpcomingByTenant')->willReturn([]);
        $this->stubEmptyDocumentQuery();
    }

    private function stubAllExceptReview(): void
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
        $this->incidentRepo->method('findOpenIncidents')->willReturn([]);
        $this->incidentRepo->method('findOpenAssignedToUser')->willReturn([]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->vulnerabilityRepo->method('findCriticalUnpatchedByTenant')->willReturn([]);
        $this->auditChecklistRepo->method('findDueForUser')->willReturn([]);
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([]);
        $this->stubEmptyDocumentQuery();
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
        $this->incidentRepo->method('findOpenIncidents')->willReturn([]);
        $this->incidentRepo->method('findOpenAssignedToUser')->willReturn([]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->vulnerabilityRepo->method('findCriticalUnpatchedByTenant')->willReturn([]);
        $this->auditChecklistRepo->method('findDueForUser')->willReturn([]);
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([]);
        $this->managementReviewRepo->method('findUpcomingByTenant')->willReturn([]);
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
        $this->incidentRepo->method('findOpenIncidents')->willReturn([]);
        $this->incidentRepo->method('findOpenAssignedToUser')->willReturn([]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->vulnerabilityRepo->method('findCriticalUnpatchedByTenant')->willReturn([]);
        $this->auditChecklistRepo->method('findDueForUser')->willReturn([]);
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([]);
        $this->managementReviewRepo->method('findUpcomingByTenant')->willReturn([]);
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
        $this->incidentRepo->method('findOpenIncidents')->willReturn([]);
        $this->incidentRepo->method('findOpenAssignedToUser')->willReturn([]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->vulnerabilityRepo->method('findCriticalUnpatchedByTenant')->willReturn([]);
        $this->auditChecklistRepo->method('findDueForUser')->willReturn([]);
        $this->changeRequestRepo->method('findPendingApprovalByTenant')->willReturn([]);
        $this->managementReviewRepo->method('findUpcomingByTenant')->willReturn([]);
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
