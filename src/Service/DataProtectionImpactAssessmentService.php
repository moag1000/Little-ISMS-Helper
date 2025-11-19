<?php

namespace App\Service;

use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DataProtectionImpactAssessmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * CRITICAL-07: Data Protection Impact Assessment Service
 *
 * Service for managing DPIAs (Datenschutz-Folgenabschätzung) per GDPR Art. 35.
 * Provides CRUD operations, workflow management, validation, and compliance reporting.
 */
class DataProtectionImpactAssessmentService
{
    public function __construct(
        private DataProtectionImpactAssessmentRepository $repository,
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private Security $security,
        private AuditLogger $auditLogger
    ) {}

    // ============================================================================
    // CRUD Operations
    // ============================================================================

    /**
     * Create a new DPIA
     */
    public function create(DataProtectionImpactAssessment $dpia): DataProtectionImpactAssessment
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $user = $this->security->getUser();

        $dpia->setTenant($tenant);
        $dpia->setCreatedBy($user);
        $dpia->setUpdatedBy($user);
        $dpia->setConductor($user);

        // Auto-generate reference number if not set
        if (empty($dpia->getReferenceNumber())) {
            $dpia->setReferenceNumber($this->repository->getNextReferenceNumber($tenant));
        }

        $this->entityManager->persist($dpia);
        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.created',
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            [
                'reference_number' => $dpia->getReferenceNumber(),
                'title' => $dpia->getTitle(),
                'processing_activity_id' => $dpia->getProcessingActivity()?->getId(),
            ]
        );

        return $dpia;
    }

    /**
     * Update an existing DPIA
     */
    public function update(DataProtectionImpactAssessment $dpia): DataProtectionImpactAssessment
    {
        $user = $this->security->getUser();
        $dpia->setUpdatedBy($user);

        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.updated',
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            [
                'reference_number' => $dpia->getReferenceNumber(),
                'status' => $dpia->getStatus(),
                'completeness' => $dpia->getCompletenessPercentage(),
            ]
        );

        return $dpia;
    }

    /**
     * Delete a DPIA
     */
    public function delete(DataProtectionImpactAssessment $dpia): void
    {
        $id = $dpia->getId();
        $referenceNumber = $dpia->getReferenceNumber();

        $this->entityManager->remove($dpia);
        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.deleted',
            'DataProtectionImpactAssessment',
            $id,
            ['reference_number' => $referenceNumber]
        );
    }

    // ============================================================================
    // Finder Methods
    // ============================================================================

    /**
     * Find all DPIAs for current tenant
     */
    public function findAll(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findByTenant($tenant);
    }

    /**
     * Find DPIAs by status
     */
    public function findByStatus(string $status): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findByStatus($tenant, $status);
    }

    /**
     * Find draft DPIAs
     */
    public function findDrafts(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findDrafts($tenant);
    }

    /**
     * Find DPIAs in review
     */
    public function findInReview(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findInReview($tenant);
    }

    /**
     * Find approved DPIAs
     */
    public function findApproved(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findApproved($tenant);
    }

    /**
     * Find DPIAs requiring revision
     */
    public function findRequiringRevision(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findRequiringRevision($tenant);
    }

    /**
     * Find high-risk DPIAs
     */
    public function findHighRisk(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findHighRisk($tenant);
    }

    /**
     * Find DPIAs with unacceptable residual risk
     */
    public function findWithUnacceptableResidualRisk(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findWithUnacceptableResidualRisk($tenant);
    }

    /**
     * Find DPIAs requiring supervisory authority consultation (Art. 36)
     */
    public function findRequiringSupervisoryConsultation(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findRequiringSupervisoryConsultation($tenant);
    }

    /**
     * Find DPIAs due for review (Art. 35(11))
     */
    public function findDueForReview(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findDueForReview($tenant);
    }

    /**
     * Find incomplete DPIAs (missing mandatory fields)
     */
    public function findIncomplete(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findIncomplete($tenant);
    }

    /**
     * Find DPIAs awaiting DPO consultation (Art. 35(4))
     */
    public function findAwaitingDPOConsultation(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findAwaitingDPOConsultation($tenant);
    }

    /**
     * Find DPIA by processing activity
     */
    public function findByProcessingActivity(ProcessingActivity $processingActivity): ?DataProtectionImpactAssessment
    {
        return $this->repository->findByProcessingActivity($processingActivity);
    }

    /**
     * Search DPIAs by title or reference number
     */
    public function search(string $query): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->search($tenant, $query);
    }

    // ============================================================================
    // Workflow Management
    // ============================================================================

    /**
     * Submit DPIA for review (draft → in_review)
     */
    public function submitForReview(DataProtectionImpactAssessment $dpia): DataProtectionImpactAssessment
    {
        if ($dpia->getStatus() !== 'draft') {
            throw new \RuntimeException('Only draft DPIAs can be submitted for review');
        }

        if (!$dpia->isComplete()) {
            throw new \RuntimeException('DPIA must be complete before submission');
        }

        $dpia->setStatus('in_review');
        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.submitted_for_review',
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            [
                'reference_number' => $dpia->getReferenceNumber(),
                'completeness' => $dpia->getCompletenessPercentage(),
            ]
        );

        return $dpia;
    }

    /**
     * Approve DPIA (in_review → approved)
     */
    public function approve(DataProtectionImpactAssessment $dpia, User $approver, ?string $comments = null): DataProtectionImpactAssessment
    {
        if ($dpia->getStatus() !== 'in_review') {
            throw new \RuntimeException('Only DPIAs in review can be approved');
        }

        $dpia->setStatus('approved');
        $dpia->setApprover($approver);
        $dpia->setApprovalDate(new \DateTime());
        $dpia->setApprovalComments($comments);

        // Set next review date
        if ($dpia->getReviewFrequencyMonths() > 0) {
            $nextReview = new \DateTime();
            $nextReview->modify('+' . $dpia->getReviewFrequencyMonths() . ' months');
            $dpia->setNextReviewDate($nextReview);
        }

        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.approved',
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            [
                'reference_number' => $dpia->getReferenceNumber(),
                'approver_id' => $approver->getId(),
                'residual_risk_level' => $dpia->getResidualRiskLevel(),
            ]
        );

        // Update linked processing activity
        if ($processingActivity = $dpia->getProcessingActivity()) {
            $processingActivity->setDpiaCompleted(true);
            $processingActivity->setDpiaDate(new \DateTime());
            $this->entityManager->flush();
        }

        return $dpia;
    }

    /**
     * Reject DPIA (in_review → rejected)
     */
    public function reject(DataProtectionImpactAssessment $dpia, User $approver, string $reason): DataProtectionImpactAssessment
    {
        if ($dpia->getStatus() !== 'in_review') {
            throw new \RuntimeException('Only DPIAs in review can be rejected');
        }

        $dpia->setStatus('rejected');
        $dpia->setApprover($approver);
        $dpia->setRejectionReason($reason);

        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.rejected',
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            [
                'reference_number' => $dpia->getReferenceNumber(),
                'approver_id' => $approver->getId(),
                'reason' => $reason,
            ]
        );

        return $dpia;
    }

    /**
     * Request revision (in_review → requires_revision)
     */
    public function requestRevision(DataProtectionImpactAssessment $dpia, string $reason): DataProtectionImpactAssessment
    {
        if (!in_array($dpia->getStatus(), ['in_review', 'approved'])) {
            throw new \RuntimeException('DPIA must be in review or approved to request revision');
        }

        $dpia->setStatus('requires_revision');
        $dpia->setRejectionReason($reason);
        $dpia->setReviewRequired(true);

        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.revision_requested',
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            [
                'reference_number' => $dpia->getReferenceNumber(),
                'reason' => $reason,
            ]
        );

        return $dpia;
    }

    /**
     * Reopen DPIA for editing (requires_revision → draft)
     */
    public function reopen(DataProtectionImpactAssessment $dpia): DataProtectionImpactAssessment
    {
        if ($dpia->getStatus() !== 'requires_revision') {
            throw new \RuntimeException('Only DPIAs requiring revision can be reopened');
        }

        $dpia->setStatus('draft');
        $dpia->setRejectionReason(null);

        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.reopened',
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            ['reference_number' => $dpia->getReferenceNumber()]
        );

        return $dpia;
    }

    // ============================================================================
    // DPO Consultation (Art. 35(4))
    // ============================================================================

    /**
     * Record DPO consultation
     */
    public function recordDPOConsultation(DataProtectionImpactAssessment $dpia, User $dpo, string $advice): DataProtectionImpactAssessment
    {
        $dpia->setDataProtectionOfficer($dpo);
        $dpia->setDpoConsultationDate(new \DateTime());
        $dpia->setDpoAdvice($advice);

        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.dpo_consulted',
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            [
                'reference_number' => $dpia->getReferenceNumber(),
                'dpo_id' => $dpo->getId(),
            ]
        );

        return $dpia;
    }

    // ============================================================================
    // Supervisory Authority Consultation (Art. 36)
    // ============================================================================

    /**
     * Record supervisory authority consultation
     */
    public function recordSupervisoryConsultation(DataProtectionImpactAssessment $dpia, string $feedback): DataProtectionImpactAssessment
    {
        $dpia->setSupervisoryConsultationDate(new \DateTime());
        $dpia->setSupervisoryAuthorityFeedback($feedback);

        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.supervisory_consulted',
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            [
                'reference_number' => $dpia->getReferenceNumber(),
            ]
        );

        return $dpia;
    }

    // ============================================================================
    // Review Management (Art. 35(11))
    // ============================================================================

    /**
     * Mark DPIA for review when circumstances change
     */
    public function markForReview(DataProtectionImpactAssessment $dpia, string $reason, ?\DateTime $dueDate = null): DataProtectionImpactAssessment
    {
        $dpia->setReviewRequired(true);
        $dpia->setReviewReason($reason);

        if ($dueDate) {
            $dpia->setNextReviewDate($dueDate);
        }

        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.marked_for_review',
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            [
                'reference_number' => $dpia->getReferenceNumber(),
                'reason' => $reason,
            ]
        );

        return $dpia;
    }

    /**
     * Complete review (creates new version)
     */
    public function completeReview(DataProtectionImpactAssessment $dpia): DataProtectionImpactAssessment
    {
        // Increment version number
        $currentVersion = $dpia->getVersion();
        [$major, $minor] = explode('.', $currentVersion);
        $newVersion = $major . '.' . ((int)$minor + 1);
        $dpia->setVersion($newVersion);

        $dpia->setReviewRequired(false);
        $dpia->setLastReviewDate(new \DateTime());
        $dpia->setReviewReason(null);

        // Set next review date
        if ($dpia->getReviewFrequencyMonths() > 0) {
            $nextReview = new \DateTime();
            $nextReview->modify('+' . $dpia->getReviewFrequencyMonths() . ' months');
            $dpia->setNextReviewDate($nextReview);
        }

        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.review_completed',
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            [
                'reference_number' => $dpia->getReferenceNumber(),
                'version' => $newVersion,
            ]
        );

        return $dpia;
    }

    // ============================================================================
    // Validation
    // ============================================================================

    /**
     * Validate DPIA completeness per Art. 35(7) GDPR
     *
     * Returns array of validation errors (empty if valid)
     */
    public function validate(DataProtectionImpactAssessment $dpia): array
    {
        $errors = [];

        // Basic information
        if (empty($dpia->getTitle())) {
            $errors[] = 'DPIA title is required';
        }

        if (empty($dpia->getReferenceNumber())) {
            $errors[] = 'Reference number is required';
        }

        // Art. 35(7)(a) - Description of processing
        if (empty($dpia->getProcessingDescription())) {
            $errors[] = 'Systematic description of processing operations is required (Art. 35(7)(a))';
        }

        if (empty($dpia->getProcessingPurposes())) {
            $errors[] = 'Purposes of processing are required (Art. 35(7)(a))';
        }

        if (empty($dpia->getDataCategories())) {
            $errors[] = 'Categories of personal data are required (Art. 35(7)(a))';
        }

        if (empty($dpia->getDataSubjectCategories())) {
            $errors[] = 'Categories of data subjects are required (Art. 35(7)(a))';
        }

        // Art. 35(7)(b) - Necessity and proportionality
        if (empty($dpia->getNecessityAssessment())) {
            $errors[] = 'Assessment of necessity is required (Art. 35(7)(b))';
        }

        if (empty($dpia->getProportionalityAssessment())) {
            $errors[] = 'Assessment of proportionality is required (Art. 35(7)(b))';
        }

        if (empty($dpia->getLegalBasis())) {
            $errors[] = 'Legal basis for processing is required (Art. 35(7)(b))';
        }

        // Art. 35(7)(c) - Risk assessment
        if (empty($dpia->getIdentifiedRisks())) {
            $errors[] = 'Identified risks to rights and freedoms are required (Art. 35(7)(c))';
        }

        if (empty($dpia->getRiskLevel())) {
            $errors[] = 'Overall risk level assessment is required (Art. 35(7)(c))';
        }

        // Art. 35(7)(d) - Measures to address risks
        if (empty($dpia->getTechnicalMeasures())) {
            $errors[] = 'Technical measures to mitigate risks are required (Art. 35(7)(d))';
        }

        if (empty($dpia->getOrganizationalMeasures())) {
            $errors[] = 'Organizational measures to mitigate risks are required (Art. 35(7)(d))';
        }

        // Art. 35(4) - DPO consultation warning
        if ($dpia->getStatus() === 'in_review' && !$dpia->getDpoConsultationDate()) {
            $errors[] = 'DPO should be consulted before approval (Art. 35(4))';
        }

        // Art. 36 - Supervisory authority consultation check
        if ($dpia->getRequiresSupervisoryConsultation() && !$dpia->getSupervisoryConsultationDate()) {
            $errors[] = 'Prior consultation with supervisory authority is required (Art. 36)';
        }

        // Residual risk check
        if ($dpia->getRiskLevel() && empty($dpia->getResidualRiskLevel())) {
            $errors[] = 'Residual risk assessment is required after defining mitigation measures';
        }

        if (!$dpia->isResidualRiskAcceptable() && $dpia->getStatus() === 'approved') {
            $errors[] = 'Cannot approve DPIA with high/critical residual risk without supervisory consultation (Art. 36)';
        }

        return $errors;
    }

    // ============================================================================
    // Statistics & Reporting
    // ============================================================================

    /**
     * Get dashboard statistics
     */
    public function getDashboardStatistics(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->getStatistics($tenant);
    }

    /**
     * Calculate compliance score
     */
    public function calculateComplianceScore(): array
    {
        $all = $this->findAll();
        $totalCount = count($all);

        if ($totalCount === 0) {
            return [
                'overall_score' => 100,
                'completeness_score' => 100,
                'approval_score' => 100,
                'total_dpias' => 0,
            ];
        }

        // Completeness: all DPIAs have all mandatory fields
        $completeCount = count(array_filter($all, fn($dpia) => $dpia->isComplete()));
        $completenessScore = ($completeCount / $totalCount) * 100;

        // Approval: approved DPIAs / total DPIAs
        $approvedCount = count(array_filter($all, fn($dpia) => $dpia->getStatus() === 'approved'));
        $approvalScore = ($approvedCount / $totalCount) * 100;

        // Review compliance: DPIAs not overdue for review
        $dueForReview = count($this->findDueForReview());
        $reviewCompliance = $totalCount > 0 ? (($totalCount - $dueForReview) / $totalCount) * 100 : 100;

        // Overall: weighted average (40% completeness, 40% approval, 20% review)
        $overallScore = ($completenessScore * 0.4) + ($approvalScore * 0.4) + ($reviewCompliance * 0.2);

        return [
            'overall_score' => (int) round($overallScore),
            'completeness_score' => (int) round($completenessScore),
            'approval_score' => (int) round($approvalScore),
            'review_compliance_score' => (int) round($reviewCompliance),
            'total_dpias' => $totalCount,
            'complete_dpias' => $completeCount,
            'approved_dpias' => $approvedCount,
            'due_for_review' => $dueForReview,
        ];
    }

    /**
     * Generate DPIA compliance report
     */
    public function generateComplianceReport(DataProtectionImpactAssessment $dpia): array
    {
        $errors = $this->validate($dpia);

        return [
            'dpia' => $dpia,
            'reference_number' => $dpia->getReferenceNumber(),
            'title' => $dpia->getTitle(),
            'status' => $dpia->getStatus(),
            'completeness_percentage' => $dpia->getCompletenessPercentage(),
            'is_complete' => $dpia->isComplete(),
            'validation_errors' => $errors,
            'is_compliant' => empty($errors),
            'risk_level' => $dpia->getRiskLevel(),
            'residual_risk_level' => $dpia->getResidualRiskLevel(),
            'residual_risk_acceptable' => $dpia->isResidualRiskAcceptable(),
            'dpo_consulted' => $dpia->getDpoConsultationDate() !== null,
            'supervisory_consulted' => $dpia->getSupervisoryConsultationDate() !== null,
            'requires_supervisory_consultation' => $dpia->getRequiresSupervisoryConsultation(),
            'approval_date' => $dpia->getApprovalDate(),
            'next_review_date' => $dpia->getNextReviewDate(),
            'review_overdue' => $dpia->getNextReviewDate() && $dpia->getNextReviewDate() < new \DateTime(),
        ];
    }

    /**
     * Clone DPIA (for creating new version or similar assessment)
     */
    public function clone(DataProtectionImpactAssessment $original, string $newTitle): DataProtectionImpactAssessment
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $user = $this->security->getUser();

        $clone = new DataProtectionImpactAssessment();
        $clone->setTenant($tenant);
        $clone->setTitle($newTitle);
        $clone->setReferenceNumber($this->repository->getNextReferenceNumber($tenant));

        // Copy processing details
        $clone->setProcessingDescription($original->getProcessingDescription());
        $clone->setProcessingPurposes($original->getProcessingPurposes());
        $clone->setDataCategories($original->getDataCategories());
        $clone->setDataSubjectCategories($original->getDataSubjectCategories());
        $clone->setEstimatedDataSubjects($original->getEstimatedDataSubjects());
        $clone->setDataRetentionPeriod($original->getDataRetentionPeriod());
        $clone->setDataFlowDescription($original->getDataFlowDescription());

        // Copy assessments
        $clone->setNecessityAssessment($original->getNecessityAssessment());
        $clone->setProportionalityAssessment($original->getProportionalityAssessment());
        $clone->setLegalBasis($original->getLegalBasis());
        $clone->setLegislativeCompliance($original->getLegislativeCompliance());

        // Copy measures (but not risks - those should be reassessed)
        $clone->setTechnicalMeasures($original->getTechnicalMeasures());
        $clone->setOrganizationalMeasures($original->getOrganizationalMeasures());
        $clone->setComplianceMeasures($original->getComplianceMeasures());

        // Copy controls
        foreach ($original->getImplementedControls() as $control) {
            $clone->addImplementedControl($control);
        }

        // Set as draft
        $clone->setStatus('draft');
        $clone->setConductor($user);
        $clone->setCreatedBy($user);
        $clone->setUpdatedBy($user);

        $this->entityManager->persist($clone);
        $this->entityManager->flush();

        $this->auditLogger->log(
            'dpia.cloned',
            'DataProtectionImpactAssessment',
            $clone->getId(),
            [
                'original_id' => $original->getId(),
                'original_reference' => $original->getReferenceNumber(),
                'new_reference' => $clone->getReferenceNumber(),
            ]
        );

        return $clone;
    }
}
