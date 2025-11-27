<?php

namespace App\Service;

use RuntimeException;
use DateTime;
use DateTimeInterface;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
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
        private readonly DataProtectionImpactAssessmentRepository $dataProtectionImpactAssessmentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly AuditLogger $auditLogger
    ) {}

    // ============================================================================
    // CRUD Operations
    // ============================================================================

    /**
     * Create a new DPIA
     */
    public function create(DataProtectionImpactAssessment $dataProtectionImpactAssessment): DataProtectionImpactAssessment
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $user = $this->security->getUser();

        $dataProtectionImpactAssessment->setTenant($tenant);
        $dataProtectionImpactAssessment->setCreatedBy($user);
        $dataProtectionImpactAssessment->setUpdatedBy($user);
        $dataProtectionImpactAssessment->setConductor($user);

        // Auto-generate reference number if not set
        if (in_array($dataProtectionImpactAssessment->getReferenceNumber(), [null, '', '0'], true)) {
            $dataProtectionImpactAssessment->setReferenceNumber($this->dataProtectionImpactAssessmentRepository->getNextReferenceNumber($tenant));
        }

        $this->entityManager->persist($dataProtectionImpactAssessment);
        $this->entityManager->flush();

        $this->auditLogger->logCreate(
            'DataProtectionImpactAssessment',
            $dataProtectionImpactAssessment->getId(),
            [
                'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
                'title' => $dataProtectionImpactAssessment->getTitle(),
                'processing_activity_id' => $dataProtectionImpactAssessment->getProcessingActivity()?->getId(),
            ],
            'DPIA created: ' . $dataProtectionImpactAssessment->getReferenceNumber()
        );

        return $dataProtectionImpactAssessment;
    }

    /**
     * Update an existing DPIA
     */
    public function update(DataProtectionImpactAssessment $dataProtectionImpactAssessment): DataProtectionImpactAssessment
    {
        $user = $this->security->getUser();
        $dataProtectionImpactAssessment->setUpdatedBy($user);

        $this->entityManager->flush();

        $this->auditLogger->logUpdate(
            'DataProtectionImpactAssessment',
            $dataProtectionImpactAssessment->getId(),
            [],
            [
                'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
                'status' => $dataProtectionImpactAssessment->getStatus(),
                'completeness' => $dataProtectionImpactAssessment->getCompletenessPercentage(),
            ]
        );

        return $dataProtectionImpactAssessment;
    }

    /**
     * Delete a DPIA
     */
    public function delete(DataProtectionImpactAssessment $dataProtectionImpactAssessment): void
    {
        $id = $dataProtectionImpactAssessment->getId();
        $referenceNumber = $dataProtectionImpactAssessment->getReferenceNumber();

        $this->entityManager->remove($dataProtectionImpactAssessment);
        $this->entityManager->flush();

        $this->auditLogger->logDelete(
            'DataProtectionImpactAssessment',
            $id,
            ['reference_number' => $referenceNumber],
            'DPIA deleted: ' . $referenceNumber
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
        return $this->dataProtectionImpactAssessmentRepository->findByTenant($tenant);
    }

    /**
     * Find DPIAs by status
     */
    public function findByStatus(string $status): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->findByStatus($tenant, $status);
    }

    /**
     * Find draft DPIAs
     */
    public function findDrafts(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->findDrafts($tenant);
    }

    /**
     * Find DPIAs in review
     */
    public function findInReview(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->findInReview($tenant);
    }

    /**
     * Find approved DPIAs
     */
    public function findApproved(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->findApproved($tenant);
    }

    /**
     * Find DPIAs requiring revision
     */
    public function findRequiringRevision(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->findRequiringRevision($tenant);
    }

    /**
     * Find high-risk DPIAs
     */
    public function findHighRisk(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->findHighRisk($tenant);
    }

    /**
     * Find DPIAs with unacceptable residual risk
     */
    public function findWithUnacceptableResidualRisk(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->findWithUnacceptableResidualRisk($tenant);
    }

    /**
     * Find DPIAs requiring supervisory authority consultation (Art. 36)
     */
    public function findRequiringSupervisoryConsultation(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->findRequiringSupervisoryConsultation($tenant);
    }

    /**
     * Find DPIAs due for review (Art. 35(11))
     */
    public function findDueForReview(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->findDueForReview($tenant);
    }

    /**
     * Find incomplete DPIAs (missing mandatory fields)
     */
    public function findIncomplete(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->findIncomplete($tenant);
    }

    /**
     * Find DPIAs awaiting DPO consultation (Art. 35(4))
     */
    public function findAwaitingDPOConsultation(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->findAwaitingDPOConsultation($tenant);
    }

    /**
     * Find DPIA by processing activity
     */
    public function findByProcessingActivity(ProcessingActivity $processingActivity): ?DataProtectionImpactAssessment
    {
        return $this->dataProtectionImpactAssessmentRepository->findByProcessingActivity($processingActivity);
    }

    /**
     * Search DPIAs by title or reference number
     */
    public function search(string $query): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->dataProtectionImpactAssessmentRepository->search($tenant, $query);
    }

    // ============================================================================
    // Workflow Management
    // ============================================================================

    /**
     * Submit DPIA for review (draft → in_review)
     */
    public function submitForReview(DataProtectionImpactAssessment $dataProtectionImpactAssessment): DataProtectionImpactAssessment
    {
        if ($dataProtectionImpactAssessment->getStatus() !== 'draft') {
            throw new RuntimeException('Only draft DPIAs can be submitted for review');
        }

        if (!$dataProtectionImpactAssessment->isComplete()) {
            throw new RuntimeException('DPIA must be complete before submission');
        }

        $dataProtectionImpactAssessment->setStatus('in_review');
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'dpia.submitted_for_review',
            'DataProtectionImpactAssessment',
            $dataProtectionImpactAssessment->getId(),
            [
                'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
                'completeness' => $dataProtectionImpactAssessment->getCompletenessPercentage(),
            ]
        );

        return $dataProtectionImpactAssessment;
    }

    /**
     * Approve DPIA (in_review → approved)
     */
    public function approve(DataProtectionImpactAssessment $dataProtectionImpactAssessment, User $user, ?string $comments = null): DataProtectionImpactAssessment
    {
        if ($dataProtectionImpactAssessment->getStatus() !== 'in_review') {
            throw new RuntimeException('Only DPIAs in review can be approved');
        }

        $dataProtectionImpactAssessment->setStatus('approved');
        $dataProtectionImpactAssessment->setApprover($user);
        $dataProtectionImpactAssessment->setApprovalDate(new DateTime());
        $dataProtectionImpactAssessment->setApprovalComments($comments);

        // Set next review date
        if ($dataProtectionImpactAssessment->getReviewFrequencyMonths() > 0) {
            $nextReview = new DateTime();
            $nextReview->modify('+' . $dataProtectionImpactAssessment->getReviewFrequencyMonths() . ' months');
            $dataProtectionImpactAssessment->setNextReviewDate($nextReview);
        }

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'dpia.approved',
            'DataProtectionImpactAssessment',
            $dataProtectionImpactAssessment->getId(),
            [
                'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
                'approver_id' => $user->getId(),
                'residual_risk_level' => $dataProtectionImpactAssessment->getResidualRiskLevel(),
            ]
        );

        // Update linked processing activity
        if (($processingActivity = $dataProtectionImpactAssessment->getProcessingActivity()) instanceof ProcessingActivity) {
            $processingActivity->setDpiaCompleted(true);
            $processingActivity->setDpiaDate(new DateTime());
            $this->entityManager->flush();
        }

        return $dataProtectionImpactAssessment;
    }

    /**
     * Reject DPIA (in_review → rejected)
     */
    public function reject(DataProtectionImpactAssessment $dataProtectionImpactAssessment, User $user, string $reason): DataProtectionImpactAssessment
    {
        if ($dataProtectionImpactAssessment->getStatus() !== 'in_review') {
            throw new RuntimeException('Only DPIAs in review can be rejected');
        }

        $dataProtectionImpactAssessment->setStatus('rejected');
        $dataProtectionImpactAssessment->setApprover($user);
        $dataProtectionImpactAssessment->setRejectionReason($reason);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'dpia.rejected',
            'DataProtectionImpactAssessment',
            $dataProtectionImpactAssessment->getId(),
            [
                'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
                'approver_id' => $user->getId(),
                'reason' => $reason,
            ]
        );

        return $dataProtectionImpactAssessment;
    }

    /**
     * Request revision (in_review → requires_revision)
     */
    public function requestRevision(DataProtectionImpactAssessment $dataProtectionImpactAssessment, string $reason): DataProtectionImpactAssessment
    {
        if (!in_array($dataProtectionImpactAssessment->getStatus(), ['in_review', 'approved'])) {
            throw new RuntimeException('DPIA must be in review or approved to request revision');
        }

        $dataProtectionImpactAssessment->setStatus('requires_revision');
        $dataProtectionImpactAssessment->setRejectionReason($reason);
        $dataProtectionImpactAssessment->setReviewRequired(true);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'dpia.revision_requested',
            'DataProtectionImpactAssessment',
            $dataProtectionImpactAssessment->getId(),
            [
                'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
                'reason' => $reason,
            ]
        );

        return $dataProtectionImpactAssessment;
    }

    /**
     * Reopen DPIA for editing (requires_revision → draft)
     */
    public function reopen(DataProtectionImpactAssessment $dataProtectionImpactAssessment): DataProtectionImpactAssessment
    {
        if ($dataProtectionImpactAssessment->getStatus() !== 'requires_revision') {
            throw new RuntimeException('Only DPIAs requiring revision can be reopened');
        }

        $dataProtectionImpactAssessment->setStatus('draft');
        $dataProtectionImpactAssessment->setRejectionReason(null);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'dpia.reopened',
            'DataProtectionImpactAssessment',
            $dataProtectionImpactAssessment->getId(),
            ['reference_number' => $dataProtectionImpactAssessment->getReferenceNumber()]
        );

        return $dataProtectionImpactAssessment;
    }

    // ============================================================================
    // DPO Consultation (Art. 35(4))
    // ============================================================================

    /**
     * Record DPO consultation
     */
    public function recordDPOConsultation(DataProtectionImpactAssessment $dataProtectionImpactAssessment, User $user, string $advice): DataProtectionImpactAssessment
    {
        $dataProtectionImpactAssessment->setDataProtectionOfficer($user);
        $dataProtectionImpactAssessment->setDpoConsultationDate(new DateTime());
        $dataProtectionImpactAssessment->setDpoAdvice($advice);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'dpia.dpo_consulted',
            'DataProtectionImpactAssessment',
            $dataProtectionImpactAssessment->getId(),
            [
                'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
                'dpo_id' => $user->getId(),
            ]
        );

        return $dataProtectionImpactAssessment;
    }

    // ============================================================================
    // Supervisory Authority Consultation (Art. 36)
    // ============================================================================

    /**
     * Record supervisory authority consultation
     */
    public function recordSupervisoryConsultation(DataProtectionImpactAssessment $dataProtectionImpactAssessment, string $feedback): DataProtectionImpactAssessment
    {
        $dataProtectionImpactAssessment->setSupervisoryConsultationDate(new DateTime());
        $dataProtectionImpactAssessment->setSupervisoryAuthorityFeedback($feedback);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'dpia.supervisory_consulted',
            'DataProtectionImpactAssessment',
            $dataProtectionImpactAssessment->getId(),
            [
                'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
            ]
        );

        return $dataProtectionImpactAssessment;
    }

    // ============================================================================
    // Review Management (Art. 35(11))
    // ============================================================================

    /**
     * Mark DPIA for review when circumstances change
     */
    public function markForReview(DataProtectionImpactAssessment $dataProtectionImpactAssessment, string $reason, ?DateTime $dueDate = null): DataProtectionImpactAssessment
    {
        $dataProtectionImpactAssessment->setReviewRequired(true);
        $dataProtectionImpactAssessment->setReviewReason($reason);

        if ($dueDate instanceof DateTime) {
            $dataProtectionImpactAssessment->setNextReviewDate($dueDate);
        }

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'dpia.marked_for_review',
            'DataProtectionImpactAssessment',
            $dataProtectionImpactAssessment->getId(),
            [
                'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
                'reason' => $reason,
            ]
        );

        return $dataProtectionImpactAssessment;
    }

    /**
     * Complete review (creates new version)
     */
    public function completeReview(DataProtectionImpactAssessment $dataProtectionImpactAssessment): DataProtectionImpactAssessment
    {
        // Increment version number
        $currentVersion = $dataProtectionImpactAssessment->getVersion();
        [$major, $minor] = explode('.', $currentVersion);
        $newVersion = $major . '.' . ((int)$minor + 1);
        $dataProtectionImpactAssessment->setVersion($newVersion);

        $dataProtectionImpactAssessment->setReviewRequired(false);
        $dataProtectionImpactAssessment->setLastReviewDate(new DateTime());
        $dataProtectionImpactAssessment->setReviewReason(null);

        // Set next review date
        if ($dataProtectionImpactAssessment->getReviewFrequencyMonths() > 0) {
            $nextReview = new DateTime();
            $nextReview->modify('+' . $dataProtectionImpactAssessment->getReviewFrequencyMonths() . ' months');
            $dataProtectionImpactAssessment->setNextReviewDate($nextReview);
        }

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'dpia.review_completed',
            'DataProtectionImpactAssessment',
            $dataProtectionImpactAssessment->getId(),
            [
                'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
                'version' => $newVersion,
            ]
        );

        return $dataProtectionImpactAssessment;
    }

    // ============================================================================
    // Validation
    // ============================================================================

    /**
     * Validate DPIA completeness per Art. 35(7) GDPR
     *
     * Returns array of validation errors (empty if valid)
     */
    public function validate(DataProtectionImpactAssessment $dataProtectionImpactAssessment): array
    {
        $errors = [];

        // Basic information
        if (in_array($dataProtectionImpactAssessment->getTitle(), [null, '', '0'], true)) {
            $errors[] = 'DPIA title is required';
        }

        if (in_array($dataProtectionImpactAssessment->getReferenceNumber(), [null, '', '0'], true)) {
            $errors[] = 'Reference number is required';
        }

        // Art. 35(7)(a) - Description of processing
        if (in_array($dataProtectionImpactAssessment->getProcessingDescription(), [null, '', '0'], true)) {
            $errors[] = 'Systematic description of processing operations is required (Art. 35(7)(a))';
        }

        if (in_array($dataProtectionImpactAssessment->getProcessingPurposes(), [null, '', '0'], true)) {
            $errors[] = 'Purposes of processing are required (Art. 35(7)(a))';
        }

        if ($dataProtectionImpactAssessment->getDataCategories() === []) {
            $errors[] = 'Categories of personal data are required (Art. 35(7)(a))';
        }

        if ($dataProtectionImpactAssessment->getDataSubjectCategories() === []) {
            $errors[] = 'Categories of data subjects are required (Art. 35(7)(a))';
        }

        // Art. 35(7)(b) - Necessity and proportionality
        if (in_array($dataProtectionImpactAssessment->getNecessityAssessment(), [null, '', '0'], true)) {
            $errors[] = 'Assessment of necessity is required (Art. 35(7)(b))';
        }

        if (in_array($dataProtectionImpactAssessment->getProportionalityAssessment(), [null, '', '0'], true)) {
            $errors[] = 'Assessment of proportionality is required (Art. 35(7)(b))';
        }

        if (in_array($dataProtectionImpactAssessment->getLegalBasis(), [null, '', '0'], true)) {
            $errors[] = 'Legal basis for processing is required (Art. 35(7)(b))';
        }

        // Art. 35(7)(c) - Risk assessment
        if ($dataProtectionImpactAssessment->getIdentifiedRisks() === []) {
            $errors[] = 'Identified risks to rights and freedoms are required (Art. 35(7)(c))';
        }

        if (in_array($dataProtectionImpactAssessment->getRiskLevel(), [null, '', '0'], true)) {
            $errors[] = 'Overall risk level assessment is required (Art. 35(7)(c))';
        }

        // Art. 35(7)(d) - Measures to address risks
        if (in_array($dataProtectionImpactAssessment->getTechnicalMeasures(), [null, '', '0'], true)) {
            $errors[] = 'Technical measures to mitigate risks are required (Art. 35(7)(d))';
        }

        if (in_array($dataProtectionImpactAssessment->getOrganizationalMeasures(), [null, '', '0'], true)) {
            $errors[] = 'Organizational measures to mitigate risks are required (Art. 35(7)(d))';
        }

        // Art. 35(4) - DPO consultation warning
        if ($dataProtectionImpactAssessment->getStatus() === 'in_review' && !$dataProtectionImpactAssessment->getDpoConsultationDate()) {
            $errors[] = 'DPO should be consulted before approval (Art. 35(4))';
        }

        // Art. 36 - Supervisory authority consultation check
        if ($dataProtectionImpactAssessment->getRequiresSupervisoryConsultation() && !$dataProtectionImpactAssessment->getSupervisoryConsultationDate()) {
            $errors[] = 'Prior consultation with supervisory authority is required (Art. 36)';
        }

        // Residual risk check
        if ($dataProtectionImpactAssessment->getRiskLevel() && in_array($dataProtectionImpactAssessment->getResidualRiskLevel(), [null, '', '0'], true)) {
            $errors[] = 'Residual risk assessment is required after defining mitigation measures';
        }

        if (!$dataProtectionImpactAssessment->isResidualRiskAcceptable() && $dataProtectionImpactAssessment->getStatus() === 'approved') {
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
        return $this->dataProtectionImpactAssessmentRepository->getStatistics($tenant);
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
                'review_compliance_score' => 100,
                'total_dpias' => 0,
                'complete_dpias' => 0,
                'approved_dpias' => 0,
                'due_for_review' => 0,
            ];
        }

        // Completeness: all DPIAs have all mandatory fields
        $completeCount = count(array_filter($all, fn($dpia) => $dpia->isComplete()));
        $completenessScore = ($completeCount / $totalCount) * 100;

        // Approval: approved DPIAs / total DPIAs
        $approvedCount = count(array_filter($all, fn($dpia): bool => $dpia->getStatus() === 'approved'));
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
    public function generateComplianceReport(DataProtectionImpactAssessment $dataProtectionImpactAssessment): array
    {
        $errors = $this->validate($dataProtectionImpactAssessment);

        return [
            'dpia' => $dataProtectionImpactAssessment,
            'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
            'title' => $dataProtectionImpactAssessment->getTitle(),
            'status' => $dataProtectionImpactAssessment->getStatus(),
            'completeness_percentage' => $dataProtectionImpactAssessment->getCompletenessPercentage(),
            'is_complete' => $dataProtectionImpactAssessment->isComplete(),
            'validation_errors' => $errors,
            'is_compliant' => $errors === [],
            'risk_level' => $dataProtectionImpactAssessment->getRiskLevel(),
            'residual_risk_level' => $dataProtectionImpactAssessment->getResidualRiskLevel(),
            'residual_risk_acceptable' => $dataProtectionImpactAssessment->isResidualRiskAcceptable(),
            'dpo_consulted' => $dataProtectionImpactAssessment->getDpoConsultationDate() instanceof DateTimeInterface,
            'supervisory_consulted' => $dataProtectionImpactAssessment->getSupervisoryConsultationDate() instanceof DateTimeInterface,
            'requires_supervisory_consultation' => $dataProtectionImpactAssessment->getRequiresSupervisoryConsultation(),
            'approval_date' => $dataProtectionImpactAssessment->getApprovalDate(),
            'next_review_date' => $dataProtectionImpactAssessment->getNextReviewDate(),
            'review_overdue' => $dataProtectionImpactAssessment->getNextReviewDate() instanceof DateTimeInterface && $dataProtectionImpactAssessment->getNextReviewDate() < new DateTime(),
        ];
    }

    /**
     * Clone DPIA (for creating new version or similar assessment)
     */
    public function clone(DataProtectionImpactAssessment $dataProtectionImpactAssessment, string $newTitle): DataProtectionImpactAssessment
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $user = $this->security->getUser();

        $clone = new DataProtectionImpactAssessment();
        $clone->setTenant($tenant);
        $clone->setTitle($newTitle);
        $clone->setReferenceNumber($this->dataProtectionImpactAssessmentRepository->getNextReferenceNumber($tenant));

        // Copy processing details
        $clone->setProcessingDescription($dataProtectionImpactAssessment->getProcessingDescription());
        $clone->setProcessingPurposes($dataProtectionImpactAssessment->getProcessingPurposes());
        $clone->setDataCategories($dataProtectionImpactAssessment->getDataCategories());
        $clone->setDataSubjectCategories($dataProtectionImpactAssessment->getDataSubjectCategories());
        $clone->setEstimatedDataSubjects($dataProtectionImpactAssessment->getEstimatedDataSubjects());
        $clone->setDataRetentionPeriod($dataProtectionImpactAssessment->getDataRetentionPeriod());
        $clone->setDataFlowDescription($dataProtectionImpactAssessment->getDataFlowDescription());

        // Copy assessments
        $clone->setNecessityAssessment($dataProtectionImpactAssessment->getNecessityAssessment());
        $clone->setProportionalityAssessment($dataProtectionImpactAssessment->getProportionalityAssessment());
        $clone->setLegalBasis($dataProtectionImpactAssessment->getLegalBasis());
        $clone->setLegislativeCompliance($dataProtectionImpactAssessment->getLegislativeCompliance());

        // Copy measures (but not risks - those should be reassessed)
        $clone->setTechnicalMeasures($dataProtectionImpactAssessment->getTechnicalMeasures());
        $clone->setOrganizationalMeasures($dataProtectionImpactAssessment->getOrganizationalMeasures());
        $clone->setComplianceMeasures($dataProtectionImpactAssessment->getComplianceMeasures());

        // Copy controls
        foreach ($dataProtectionImpactAssessment->getImplementedControls() as $implementedControl) {
            $clone->addImplementedControl($implementedControl);
        }

        // Set as draft
        $clone->setStatus('draft');
        $clone->setConductor($user);
        $clone->setCreatedBy($user);
        $clone->setUpdatedBy($user);

        $this->entityManager->persist($clone);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'dpia.cloned',
            'DataProtectionImpactAssessment',
            $clone->getId(),
            [
                'original_id' => $dataProtectionImpactAssessment->getId(),
                'original_reference' => $dataProtectionImpactAssessment->getReferenceNumber(),
                'new_reference' => $clone->getReferenceNumber(),
            ]
        );

        return $clone;
    }
}
