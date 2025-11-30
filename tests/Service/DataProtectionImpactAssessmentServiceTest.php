<?php

namespace App\Tests\Service;

use App\Entity\Control;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Service\AuditLogger;
use App\Service\DataProtectionImpactAssessmentService;
use App\Service\TenantContext;
use DateTime;
use App\Service\WorkflowAutoProgressionService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;

class DataProtectionImpactAssessmentServiceTest extends TestCase
{
    private MockObject $repository;
    private MockObject $entityManager;
    private MockObject $tenantContext;
    private MockObject $security;
    private MockObject $auditLogger;
    private MockObject $workflowAutoProgressionService;
    private DataProtectionImpactAssessmentService $service;
    private MockObject $tenant;
    private MockObject $user;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(DataProtectionImpactAssessmentRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->security = $this->createMock(Security::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->workflowAutoProgressionService = $this->createMock(WorkflowAutoProgressionService::class);

        $this->tenant = $this->createMock(Tenant::class);
        $this->tenant->method('getId')->willReturn(1);

        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn(1);
        $this->user->method('getEmail')->willReturn('user@example.com');

        $this->service = new DataProtectionImpactAssessmentService(
            $this->repository,
            $this->entityManager,
            $this->tenantContext,
            $this->security,
            $this->auditLogger,
            $this->workflowAutoProgressionService
        );
    }

    // =========================================================================
    // CRUD Tests
    // =========================================================================

    public function testCreateDPIASetsDefaultValues(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);
        $this->security->method('getUser')->willReturn($this->user);
        $this->repository->method('getNextReferenceNumber')
            ->with($this->tenant)
            ->willReturn('DPIA-2025-001');

        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getReferenceNumber')->willReturn(null);
        $dpia->method('getTitle')->willReturn('Test DPIA');
        $dpia->method('getProcessingActivity')->willReturn(null);

        $dpia->expects($this->once())->method('setTenant')->with($this->tenant);
        $dpia->expects($this->once())->method('setCreatedBy')->with($this->user);
        $dpia->expects($this->once())->method('setUpdatedBy')->with($this->user);
        $dpia->expects($this->once())->method('setConductor')->with($this->user);
        $dpia->expects($this->once())->method('setReferenceNumber')->with('DPIA-2025-001');

        $this->entityManager->expects($this->once())->method('persist')->with($dpia);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->create($dpia);

        $this->assertSame($dpia, $result);
    }

    public function testCreateDPIAPreservesExistingReferenceNumber(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);
        $this->security->method('getUser')->willReturn($this->user);

        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getReferenceNumber')->willReturn('CUSTOM-REF-001');
        $dpia->method('getTitle')->willReturn('Test DPIA');
        $dpia->method('getProcessingActivity')->willReturn(null);

        $dpia->expects($this->once())->method('setTenant')->with($this->tenant);
        $dpia->expects($this->once())->method('setCreatedBy')->with($this->user);
        $dpia->expects($this->once())->method('setUpdatedBy')->with($this->user);
        $dpia->expects($this->once())->method('setConductor')->with($this->user);
        $dpia->expects($this->never())->method('setReferenceNumber');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->create($dpia);

        $this->assertSame($dpia, $result);
    }

    public function testUpdateDPIASetsUpdatedBy(): void
    {
        $this->security->method('getUser')->willReturn($this->user);

        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');
        $dpia->method('getStatus')->willReturn('draft');
        $dpia->method('getCompletenessPercentage')->willReturn(50);

        $dpia->expects($this->once())->method('setUpdatedBy')->with($this->user);

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->update($dpia);

        $this->assertSame($dpia, $result);
    }

    public function testDeleteDPIA(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');

        $this->entityManager->expects($this->once())->method('remove')->with($dpia);
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->delete($dpia);
    }

    // =========================================================================
    // Finder Method Tests
    // =========================================================================

    public function testFindAllReturnsTenantDPIAs(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $dpias = [
            $this->createMock(DataProtectionImpactAssessment::class),
            $this->createMock(DataProtectionImpactAssessment::class),
        ];

        $this->repository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn($dpias);

        $result = $this->service->findAll();

        $this->assertCount(2, $result);
        $this->assertSame($dpias, $result);
    }

    public function testFindByStatusReturnsDPIAsWithMatchingStatus(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $dpias = [$this->createMock(DataProtectionImpactAssessment::class)];

        $this->repository->method('findByStatus')
            ->with($this->tenant, 'draft')
            ->willReturn($dpias);

        $result = $this->service->findByStatus('draft');

        $this->assertCount(1, $result);
    }

    public function testFindHighRiskReturnsDPIAsWithHighRisk(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $dpias = [$this->createMock(DataProtectionImpactAssessment::class)];

        $this->repository->method('findHighRisk')
            ->with($this->tenant)
            ->willReturn($dpias);

        $result = $this->service->findHighRisk();

        $this->assertCount(1, $result);
    }

    public function testFindRequiringSupervisoryConsultation(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $dpias = [$this->createMock(DataProtectionImpactAssessment::class)];

        $this->repository->method('findRequiringSupervisoryConsultation')
            ->with($this->tenant)
            ->willReturn($dpias);

        $result = $this->service->findRequiringSupervisoryConsultation();

        $this->assertCount(1, $result);
    }

    public function testFindDueForReview(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $dpias = [$this->createMock(DataProtectionImpactAssessment::class)];

        $this->repository->method('findDueForReview')
            ->with($this->tenant)
            ->willReturn($dpias);

        $result = $this->service->findDueForReview();

        $this->assertCount(1, $result);
    }

    public function testFindByProcessingActivity(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);

        $this->repository->method('findByProcessingActivity')
            ->with($processingActivity)
            ->willReturn($dpia);

        $result = $this->service->findByProcessingActivity($processingActivity);

        $this->assertSame($dpia, $result);
    }

    // =========================================================================
    // Workflow Tests
    // =========================================================================

    public function testSubmitForReviewThrowsExceptionForNonDraftStatus(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getStatus')->willReturn('in_review');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only draft DPIAs can be submitted for review');

        $this->service->submitForReview($dpia);
    }

    public function testSubmitForReviewThrowsExceptionForIncompleteDPIA(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getStatus')->willReturn('draft');
        $dpia->method('isComplete')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DPIA must be complete before submission');

        $this->service->submitForReview($dpia);
    }

    public function testSubmitForReviewChangesStatusToInReview(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getStatus')->willReturn('draft');
        $dpia->method('isComplete')->willReturn(true);
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');
        $dpia->method('getCompletenessPercentage')->willReturn(100);

        $dpia->expects($this->once())->method('setStatus')->with('in_review');

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->submitForReview($dpia);

        $this->assertSame($dpia, $result);
    }

    public function testApproveThrowsExceptionForNonInReviewStatus(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getStatus')->willReturn('draft');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only DPIAs in review can be approved');

        $this->service->approve($dpia, $this->user);
    }

    public function testApproveSetsDPIAAsApproved(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getStatus')->willReturn('in_review');
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');
        $dpia->method('getReviewFrequencyMonths')->willReturn(12);
        $dpia->method('getResidualRiskLevel')->willReturn('medium');
        $dpia->method('getProcessingActivity')->willReturn(null);

        $dpia->expects($this->once())->method('setStatus')->with('approved');
        $dpia->expects($this->once())->method('setApprover')->with($this->user);
        $dpia->expects($this->once())->method('setApprovalDate');
        $dpia->expects($this->once())->method('setNextReviewDate');

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->approve($dpia, $this->user, 'Approved');

        $this->assertSame($dpia, $result);
    }

    public function testApproveUpdatesLinkedProcessingActivity(): void
    {
        $processingActivity = $this->createMock(ProcessingActivity::class);
        $processingActivity->expects($this->once())->method('setDpiaCompleted')->with(true);
        $processingActivity->expects($this->once())->method('setDpiaDate');

        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getStatus')->willReturn('in_review');
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');
        $dpia->method('getReviewFrequencyMonths')->willReturn(0);
        $dpia->method('getResidualRiskLevel')->willReturn('low');
        $dpia->method('getProcessingActivity')->willReturn($processingActivity);

        $this->entityManager->expects($this->exactly(2))->method('flush');

        $this->service->approve($dpia, $this->user);
    }

    public function testRejectThrowsExceptionForNonInReviewStatus(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getStatus')->willReturn('approved');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only DPIAs in review can be rejected');

        $this->service->reject($dpia, $this->user, 'Insufficient analysis');
    }

    public function testRejectSetsDPIAAsRejected(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getStatus')->willReturn('in_review');
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');

        $dpia->expects($this->once())->method('setStatus')->with('rejected');
        $dpia->expects($this->once())->method('setApprover')->with($this->user);
        $dpia->expects($this->once())->method('setRejectionReason')->with('Insufficient analysis');

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->reject($dpia, $this->user, 'Insufficient analysis');

        $this->assertSame($dpia, $result);
    }

    public function testRequestRevisionThrowsExceptionForInvalidStatus(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getStatus')->willReturn('draft');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DPIA must be in review or approved to request revision');

        $this->service->requestRevision($dpia, 'Needs more detail');
    }

    public function testRequestRevisionSetsStatusToRequiresRevision(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getStatus')->willReturn('in_review');
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');

        $dpia->expects($this->once())->method('setStatus')->with('requires_revision');
        $dpia->expects($this->once())->method('setRejectionReason')->with('Needs more detail');
        $dpia->expects($this->once())->method('setReviewRequired')->with(true);

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->requestRevision($dpia, 'Needs more detail');

        $this->assertSame($dpia, $result);
    }

    public function testReopenThrowsExceptionForNonRequiresRevisionStatus(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getStatus')->willReturn('approved');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only DPIAs requiring revision can be reopened');

        $this->service->reopen($dpia);
    }

    public function testReopenSetsStatusToDraft(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getStatus')->willReturn('requires_revision');
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');

        $dpia->expects($this->once())->method('setStatus')->with('draft');
        $dpia->expects($this->once())->method('setRejectionReason')->with(null);

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->reopen($dpia);

        $this->assertSame($dpia, $result);
    }

    // =========================================================================
    // DPO Consultation Tests (Art. 35(4))
    // =========================================================================

    public function testRecordDPOConsultation(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');

        $dpia->expects($this->once())->method('setDataProtectionOfficer')->with($this->user);
        $dpia->expects($this->once())->method('setDpoConsultationDate');
        $dpia->expects($this->once())->method('setDpoAdvice')->with('Processing is acceptable');

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->recordDPOConsultation($dpia, $this->user, 'Processing is acceptable');

        $this->assertSame($dpia, $result);
    }

    // =========================================================================
    // Supervisory Authority Consultation Tests (Art. 36)
    // =========================================================================

    public function testRecordSupervisoryConsultation(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');

        $dpia->expects($this->once())->method('setSupervisoryConsultationDate');
        $dpia->expects($this->once())->method('setSupervisoryAuthorityFeedback')->with('No objections');

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->recordSupervisoryConsultation($dpia, 'No objections');

        $this->assertSame($dpia, $result);
    }

    // =========================================================================
    // Review Management Tests (Art. 35(11))
    // =========================================================================

    public function testMarkForReview(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');

        $dpia->expects($this->once())->method('setReviewRequired')->with(true);
        $dpia->expects($this->once())->method('setReviewReason')->with('Annual review');

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->markForReview($dpia, 'Annual review');

        $this->assertSame($dpia, $result);
    }

    public function testMarkForReviewWithDueDate(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');

        $dueDate = new DateTime('+30 days');

        $dpia->expects($this->once())->method('setReviewRequired')->with(true);
        $dpia->expects($this->once())->method('setReviewReason')->with('Process change');
        $dpia->expects($this->once())->method('setNextReviewDate')->with($dueDate);

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->markForReview($dpia, 'Process change', $dueDate);

        $this->assertSame($dpia, $result);
    }

    public function testCompleteReviewIncrementsVersion(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getId')->willReturn(1);
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');
        $dpia->method('getVersion')->willReturn('1.0');
        $dpia->method('getReviewFrequencyMonths')->willReturn(12);

        $dpia->expects($this->once())->method('setVersion')->with('1.1');
        $dpia->expects($this->once())->method('setReviewRequired')->with(false);
        $dpia->expects($this->once())->method('setLastReviewDate');
        $dpia->expects($this->once())->method('setReviewReason')->with(null);
        $dpia->expects($this->once())->method('setNextReviewDate');

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->completeReview($dpia);

        $this->assertSame($dpia, $result);
    }

    // =========================================================================
    // Validation Tests (Art. 35(7))
    // =========================================================================

    public function testValidateReturnsErrorsForMissingMandatoryFields(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getTitle')->willReturn(null);
        $dpia->method('getReferenceNumber')->willReturn(null);
        $dpia->method('getProcessingDescription')->willReturn(null);
        $dpia->method('getProcessingPurposes')->willReturn(null);
        $dpia->method('getDataCategories')->willReturn([]);
        $dpia->method('getDataSubjectCategories')->willReturn([]);
        $dpia->method('getNecessityAssessment')->willReturn(null);
        $dpia->method('getProportionalityAssessment')->willReturn(null);
        $dpia->method('getLegalBasis')->willReturn(null);
        $dpia->method('getIdentifiedRisks')->willReturn([]);
        $dpia->method('getRiskLevel')->willReturn(null);
        $dpia->method('getTechnicalMeasures')->willReturn(null);
        $dpia->method('getOrganizationalMeasures')->willReturn(null);
        $dpia->method('getStatus')->willReturn('draft');
        $dpia->method('getDpoConsultationDate')->willReturn(null);
        $dpia->method('getRequiresSupervisoryConsultation')->willReturn(false);
        $dpia->method('getSupervisoryConsultationDate')->willReturn(null);
        $dpia->method('getResidualRiskLevel')->willReturn(null);
        $dpia->method('isResidualRiskAcceptable')->willReturn(true);

        $errors = $this->service->validate($dpia);

        $this->assertContains('DPIA title is required', $errors);
        $this->assertContains('Reference number is required', $errors);
        $this->assertContains('Systematic description of processing operations is required (Art. 35(7)(a))', $errors);
        $this->assertContains('Purposes of processing are required (Art. 35(7)(a))', $errors);
        $this->assertContains('Categories of personal data are required (Art. 35(7)(a))', $errors);
        $this->assertContains('Categories of data subjects are required (Art. 35(7)(a))', $errors);
        $this->assertContains('Assessment of necessity is required (Art. 35(7)(b))', $errors);
        $this->assertContains('Assessment of proportionality is required (Art. 35(7)(b))', $errors);
        $this->assertContains('Legal basis for processing is required (Art. 35(7)(b))', $errors);
        $this->assertContains('Identified risks to rights and freedoms are required (Art. 35(7)(c))', $errors);
        $this->assertContains('Overall risk level assessment is required (Art. 35(7)(c))', $errors);
        $this->assertContains('Technical measures to mitigate risks are required (Art. 35(7)(d))', $errors);
        $this->assertContains('Organizational measures to mitigate risks are required (Art. 35(7)(d))', $errors);
    }

    public function testValidateReturnsErrorForMissingDPOConsultationInReview(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getTitle')->willReturn('Test DPIA');
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');
        $dpia->method('getProcessingDescription')->willReturn('Processing description');
        $dpia->method('getProcessingPurposes')->willReturn('Processing purposes');
        $dpia->method('getDataCategories')->willReturn(['name', 'email']);
        $dpia->method('getDataSubjectCategories')->willReturn(['customers']);
        $dpia->method('getNecessityAssessment')->willReturn('Necessary');
        $dpia->method('getProportionalityAssessment')->willReturn('Proportional');
        $dpia->method('getLegalBasis')->willReturn('consent');
        $dpia->method('getIdentifiedRisks')->willReturn(['risk1', 'risk2']);
        $dpia->method('getRiskLevel')->willReturn('medium');
        $dpia->method('getTechnicalMeasures')->willReturn('Encryption, access control');
        $dpia->method('getOrganizationalMeasures')->willReturn('Training, policies');
        $dpia->method('getStatus')->willReturn('in_review');
        $dpia->method('getDpoConsultationDate')->willReturn(null);
        $dpia->method('getRequiresSupervisoryConsultation')->willReturn(false);
        $dpia->method('getSupervisoryConsultationDate')->willReturn(null);
        $dpia->method('getResidualRiskLevel')->willReturn('low');
        $dpia->method('isResidualRiskAcceptable')->willReturn(true);

        $errors = $this->service->validate($dpia);

        $this->assertContains('DPO should be consulted before approval (Art. 35(4))', $errors);
    }

    public function testValidateReturnsErrorForMissingSupervisoryConsultation(): void
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getTitle')->willReturn('Test DPIA');
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');
        $dpia->method('getProcessingDescription')->willReturn('Processing description');
        $dpia->method('getProcessingPurposes')->willReturn('Processing purposes');
        $dpia->method('getDataCategories')->willReturn(['name', 'email']);
        $dpia->method('getDataSubjectCategories')->willReturn(['customers']);
        $dpia->method('getNecessityAssessment')->willReturn('Necessary');
        $dpia->method('getProportionalityAssessment')->willReturn('Proportional');
        $dpia->method('getLegalBasis')->willReturn('consent');
        $dpia->method('getIdentifiedRisks')->willReturn(['risk1', 'risk2']);
        $dpia->method('getRiskLevel')->willReturn('medium');
        $dpia->method('getTechnicalMeasures')->willReturn('Encryption, access control');
        $dpia->method('getOrganizationalMeasures')->willReturn('Training, policies');
        $dpia->method('getStatus')->willReturn('approved');
        $dpia->method('getDpoConsultationDate')->willReturn(new DateTime());
        $dpia->method('getRequiresSupervisoryConsultation')->willReturn(true);
        $dpia->method('getSupervisoryConsultationDate')->willReturn(null);
        $dpia->method('getResidualRiskLevel')->willReturn('low');
        $dpia->method('isResidualRiskAcceptable')->willReturn(true);

        $errors = $this->service->validate($dpia);

        $this->assertContains('Prior consultation with supervisory authority is required (Art. 36)', $errors);
    }

    public function testValidateReturnsEmptyArrayForCompleteDPIA(): void
    {
        $dpia = $this->createCompleteDPIAMock();

        $errors = $this->service->validate($dpia);

        $this->assertEmpty($errors);
    }

    // =========================================================================
    // Statistics Tests
    // =========================================================================

    public function testGetDashboardStatistics(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $stats = [
            'total' => 10,
            'draft' => 3,
            'in_review' => 2,
            'approved' => 5,
        ];

        $this->repository->method('getStatistics')
            ->with($this->tenant)
            ->willReturn($stats);

        $result = $this->service->getDashboardStatistics();

        $this->assertSame($stats, $result);
    }

    public function testCalculateComplianceScoreReturns100ForNoDPIAs(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);
        $this->repository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn([]);

        $result = $this->service->calculateComplianceScore();

        $this->assertSame(100, $result['overall_score']);
        $this->assertSame(100, $result['completeness_score']);
        $this->assertSame(100, $result['approval_score']);
        $this->assertSame(100, $result['review_compliance_score']);
        $this->assertSame(0, $result['total_dpias']);
    }

    public function testCalculateComplianceScoreWithMixedDPIAs(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $completeDPIA = $this->createMock(DataProtectionImpactAssessment::class);
        $completeDPIA->method('isComplete')->willReturn(true);
        $completeDPIA->method('getStatus')->willReturn('approved');

        $incompleteDPIA = $this->createMock(DataProtectionImpactAssessment::class);
        $incompleteDPIA->method('isComplete')->willReturn(false);
        $incompleteDPIA->method('getStatus')->willReturn('draft');

        $this->repository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn([$completeDPIA, $incompleteDPIA]);

        $this->repository->method('findDueForReview')
            ->with($this->tenant)
            ->willReturn([]);

        $result = $this->service->calculateComplianceScore();

        $this->assertSame(2, $result['total_dpias']);
        $this->assertSame(1, $result['complete_dpias']);
        $this->assertSame(1, $result['approved_dpias']);
        $this->assertSame(0, $result['due_for_review']);
        // Completeness: 50%, Approval: 50%, Review: 100%
        // Overall: 50*0.4 + 50*0.4 + 100*0.2 = 20 + 20 + 20 = 60
        $this->assertSame(60, $result['overall_score']);
    }

    public function testGenerateComplianceReport(): void
    {
        $dpia = $this->createCompleteDPIAMock();
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');
        $dpia->method('getTitle')->willReturn('Test DPIA');
        $dpia->method('getCompletenessPercentage')->willReturn(100);
        $dpia->method('isComplete')->willReturn(true);
        $dpia->method('getApprovalDate')->willReturn(new DateTime());
        $dpia->method('getNextReviewDate')->willReturn(new DateTime('+1 year'));

        $report = $this->service->generateComplianceReport($dpia);

        $this->assertArrayHasKey('dpia', $report);
        $this->assertArrayHasKey('reference_number', $report);
        $this->assertArrayHasKey('title', $report);
        $this->assertArrayHasKey('status', $report);
        $this->assertArrayHasKey('completeness_percentage', $report);
        $this->assertArrayHasKey('is_complete', $report);
        $this->assertArrayHasKey('validation_errors', $report);
        $this->assertArrayHasKey('is_compliant', $report);
        $this->assertArrayHasKey('dpo_consulted', $report);
        $this->assertArrayHasKey('supervisory_consulted', $report);

        $this->assertSame('DPIA-2025-001', $report['reference_number']);
        $this->assertSame('Test DPIA', $report['title']);
        $this->assertTrue($report['is_complete']);
        $this->assertTrue($report['is_compliant']);
    }

    // =========================================================================
    // Clone Test
    // =========================================================================

    public function testCloneDPIA(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);
        $this->security->method('getUser')->willReturn($this->user);
        $this->repository->method('getNextReferenceNumber')
            ->with($this->tenant)
            ->willReturn('DPIA-2025-002');

        $original = $this->createMock(DataProtectionImpactAssessment::class);
        $original->method('getId')->willReturn(1);
        $original->method('getReferenceNumber')->willReturn('DPIA-2025-001');
        $original->method('getProcessingDescription')->willReturn('Test description');
        $original->method('getProcessingPurposes')->willReturn('Test purposes');
        $original->method('getDataCategories')->willReturn(['name', 'email']);
        $original->method('getDataSubjectCategories')->willReturn(['customers']);
        $original->method('getEstimatedDataSubjects')->willReturn(5000);
        $original->method('getDataRetentionPeriod')->willReturn('5 years');
        $original->method('getDataFlowDescription')->willReturn('Data flow');
        $original->method('getNecessityAssessment')->willReturn('Necessary');
        $original->method('getProportionalityAssessment')->willReturn('Proportional');
        $original->method('getLegalBasis')->willReturn('consent');
        $original->method('getLegislativeCompliance')->willReturn('GDPR');
        $original->method('getTechnicalMeasures')->willReturn('Encryption');
        $original->method('getOrganizationalMeasures')->willReturn('Training');
        $original->method('getComplianceMeasures')->willReturn('Audits');
        $original->method('getImplementedControls')->willReturn(new ArrayCollection());

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $clone = $this->service->clone($original, 'Cloned DPIA');

        $this->assertInstanceOf(DataProtectionImpactAssessment::class, $clone);
        $this->assertSame('Cloned DPIA', $clone->getTitle());
        $this->assertSame('DPIA-2025-002', $clone->getReferenceNumber());
        $this->assertSame('draft', $clone->getStatus());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createCompleteDPIAMock(): MockObject
    {
        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getTitle')->willReturn('Test DPIA');
        $dpia->method('getReferenceNumber')->willReturn('DPIA-2025-001');
        $dpia->method('getProcessingDescription')->willReturn('Processing description');
        $dpia->method('getProcessingPurposes')->willReturn('Processing purposes');
        $dpia->method('getDataCategories')->willReturn(['name', 'email']);
        $dpia->method('getDataSubjectCategories')->willReturn(['customers']);
        $dpia->method('getNecessityAssessment')->willReturn('Necessary');
        $dpia->method('getProportionalityAssessment')->willReturn('Proportional');
        $dpia->method('getLegalBasis')->willReturn('consent');
        $dpia->method('getIdentifiedRisks')->willReturn(['risk1', 'risk2']);
        $dpia->method('getRiskLevel')->willReturn('medium');
        $dpia->method('getTechnicalMeasures')->willReturn('Encryption, access control');
        $dpia->method('getOrganizationalMeasures')->willReturn('Training, policies');
        $dpia->method('getStatus')->willReturn('approved');
        $dpia->method('getDpoConsultationDate')->willReturn(new DateTime());
        $dpia->method('getRequiresSupervisoryConsultation')->willReturn(false);
        $dpia->method('getSupervisoryConsultationDate')->willReturn(null);
        $dpia->method('getResidualRiskLevel')->willReturn('low');
        $dpia->method('isResidualRiskAcceptable')->willReturn(true);

        return $dpia;
    }
}
