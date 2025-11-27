<?php

namespace App\Service;

use DateTime;
use DateTimeInterface;
use RuntimeException;
use App\Entity\ProcessingActivity;
use App\Repository\ProcessingActivityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * CRITICAL-06: Processing Activity Service for GDPR Art. 30 VVT
 *
 * Service for managing Processing Activities (Verarbeitungstätigkeiten).
 * Provides CRUD operations, validation, compliance checking, and reporting.
 */
class ProcessingActivityService
{
    public function __construct(
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Create a new processing activity
     */
    public function create(ProcessingActivity $processingActivity): ProcessingActivity
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $user = $this->security->getUser();

        $processingActivity->setTenant($tenant);
        $processingActivity->setCreatedBy($user);
        $processingActivity->setUpdatedBy($user);

        $this->entityManager->persist($processingActivity);
        $this->entityManager->flush();

        $this->auditLogger->logCreate(
            'ProcessingActivity',
            $processingActivity->getId(),
            [
                'name' => $processingActivity->getName(),
                'legal_basis' => $processingActivity->getLegalBasis(),
            ],
            'processing_activity.created'
        );

        return $processingActivity;
    }

    /**
     * Update an existing processing activity
     */
    public function update(ProcessingActivity $processingActivity): ProcessingActivity
    {
        $user = $this->security->getUser();
        $processingActivity->setUpdatedBy($user);

        $this->entityManager->flush();

        $this->auditLogger->logUpdate(
            'ProcessingActivity',
            $processingActivity->getId(),
            [], // oldValues not tracked here
            [
                'name' => $processingActivity->getName(),
                'completeness' => $processingActivity->getCompletenessPercentage(),
            ],
            'processing_activity.updated'
        );

        return $processingActivity;
    }

    /**
     * Delete a processing activity
     */
    public function delete(ProcessingActivity $processingActivity): void
    {
        $id = $processingActivity->getId();
        $name = $processingActivity->getName();

        $this->entityManager->remove($processingActivity);
        $this->entityManager->flush();

        $this->auditLogger->logDelete(
            'ProcessingActivity',
            $id,
            ['name' => $name],
            'processing_activity.deleted'
        );
    }

    /**
     * Find all processing activities for current tenant
     */
    public function findAll(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->processingActivityRepository->findByTenant($tenant);
    }

    /**
     * Find active processing activities
     */
    public function findActive(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->processingActivityRepository->findActiveByTenant($tenant);
    }

    /**
     * Find processing activities requiring DPIA
     */
    public function findRequiringDPIA(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->processingActivityRepository->findRequiringDPIA($tenant);
    }

    /**
     * Find incomplete processing activities (missing required fields)
     */
    public function findIncomplete(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->processingActivityRepository->findIncomplete($tenant);
    }

    /**
     * Find processing activities due for review
     */
    public function findDueForReview(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->processingActivityRepository->findDueForReview($tenant);
    }

    /**
     * Search processing activities
     */
    public function search(string $query): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->processingActivityRepository->search($tenant, $query);
    }

    /**
     * Get dashboard statistics
     */
    public function getDashboardStatistics(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $stats = $this->processingActivityRepository->getStatistics($tenant);

        // Add compliance score
        $all = $this->findAll();
        $completeCount = count(array_filter($all, fn($pa) => $pa->isComplete()));
        $stats['completeness_rate'] = count($all) > 0 ? round(($completeCount / count($all)) * 100) : 0;

        // Add breakdown by legal basis
        $stats['by_legal_basis'] = $this->processingActivityRepository->countByLegalBasis($tenant);

        // Add breakdown by risk level
        $stats['by_risk_level'] = $this->processingActivityRepository->countByRiskLevel($tenant);

        return $stats;
    }

    /**
     * Validate processing activity completeness per Art. 30 GDPR
     *
     * Returns array of validation errors (empty if valid)
     */
    public function validate(ProcessingActivity $processingActivity): array
    {
        $errors = [];

        // Mandatory fields per Art. 30(1)
        if (in_array($processingActivity->getName(), [null, '', '0'], true)) {
            $errors[] = 'Name of processing activity is required (Art. 30(1)(a))';
        }

        if ($processingActivity->getPurposes() === []) {
            $errors[] = 'Purpose(s) of processing are required (Art. 30(1)(a))';
        }

        if ($processingActivity->getDataSubjectCategories() === []) {
            $errors[] = 'Categories of data subjects are required (Art. 30(1)(b))';
        }

        if ($processingActivity->getPersonalDataCategories() === []) {
            $errors[] = 'Categories of personal data are required (Art. 30(1)(c))';
        }

        if (in_array($processingActivity->getLegalBasis(), [null, '', '0'], true)) {
            $errors[] = 'Legal basis for processing is required (Art. 6 GDPR)';
        }

        if (in_array($processingActivity->getRetentionPeriod(), [null, '', '0'], true)) {
            $errors[] = 'Retention period/deletion deadline is required (Art. 30(1)(f))';
        }

        if (in_array($processingActivity->getTechnicalOrganizationalMeasures(), [null, '', '0'], true)) {
            $errors[] = 'Technical and organizational measures must be described (Art. 30(1)(g))';
        }

        // Conditional validations
        if ($processingActivity->getLegalBasis() === 'legitimate_interests' && in_array($processingActivity->getLegalBasisDetails(), [null, '', '0'], true)) {
            $errors[] = 'Legitimate interests must be detailed and documented (Art. 6(1)(f))';
        }

        if ($processingActivity->getProcessesSpecialCategories() && in_array($processingActivity->getLegalBasisSpecialCategories(), [null, '', '0'], true)) {
            $errors[] = 'Legal basis for processing special categories must be specified (Art. 9(2))';
        }

        if ($processingActivity->getProcessesSpecialCategories() && in_array($processingActivity->getSpecialCategoriesDetails(), [null, []], true)) {
            $errors[] = 'Which special categories are processed must be specified (Art. 9)';
        }

        if ($processingActivity->getHasThirdCountryTransfer() && in_array($processingActivity->getThirdCountries(), [null, []], true)) {
            $errors[] = 'Third countries receiving data must be listed (Art. 30(1)(e))';
        }

        if ($processingActivity->getHasThirdCountryTransfer() && in_array($processingActivity->getTransferSafeguards(), [null, '', '0'], true)) {
            $errors[] = 'Legal safeguards for third country transfer must be specified (Art. 44-49)';
        }

        if ($processingActivity->getInvolvesProcessors() && in_array($processingActivity->getProcessors(), [null, []], true)) {
            $errors[] = 'Processor details must be provided (Art. 28)';
        }

        if ($processingActivity->getIsJointController() && in_array($processingActivity->getJointControllerDetails(), [null, []], true)) {
            $errors[] = 'Joint controller arrangement must be documented (Art. 26)';
        }

        if ($processingActivity->getHasAutomatedDecisionMaking() && in_array($processingActivity->getAutomatedDecisionMakingDetails(), [null, '', '0'], true)) {
            $errors[] = 'Automated decision-making logic and implications must be explained (Art. 22)';
        }

        // DPIA requirement check
        if ($processingActivity->requiresDPIA() && !$processingActivity->getDpiaCompleted()) {
            $errors[] = 'Data Protection Impact Assessment (DPIA) is required for this processing activity (Art. 35)';
        }

        return $errors;
    }

    /**
     * Check if processing activity is compliant with GDPR Art. 30
     */
    public function isCompliant(ProcessingActivity $processingActivity): bool
    {
        return $this->validate($processingActivity) === [];
    }

    /**
     * Generate compliance report for a processing activity
     */
    public function generateComplianceReport(ProcessingActivity $processingActivity): array
    {
        $errors = $this->validate($processingActivity);
        $completeness = $processingActivity->getCompletenessPercentage();

        return [
            'processing_activity_id' => $processingActivity->getId(),
            'name' => $processingActivity->getName(),
            'status' => $processingActivity->getStatus(),
            'completeness_percentage' => $completeness,
            'is_compliant' => $errors === [],
            'validation_errors' => $errors,
            'compliance_checks' => [
                'art_30_mandatory_fields' => $completeness === 100,
                'legal_basis_documented' => !in_array($processingActivity->getLegalBasis(), [null, '', '0'], true),
                'retention_period_defined' => !in_array($processingActivity->getRetentionPeriod(), [null, '', '0'], true),
                'toms_documented' => !in_array($processingActivity->getTechnicalOrganizationalMeasures(), [null, '', '0'], true),
                'dpia_completed_if_required' => !$processingActivity->requiresDPIA() || $processingActivity->getDpiaCompleted(),
                'special_categories_legal_basis' => !$processingActivity->getProcessesSpecialCategories() || !in_array($processingActivity->getLegalBasisSpecialCategories(), [null, '', '0'], true),
                'third_country_safeguards' => !$processingActivity->getHasThirdCountryTransfer() || !in_array($processingActivity->getTransferSafeguards(), [null, '', '0'], true),
            ],
            'risk_assessment' => [
                'is_high_risk' => $processingActivity->getIsHighRisk(),
                'requires_dpia' => $processingActivity->requiresDPIA(),
                'dpia_completed' => $processingActivity->getDpiaCompleted(),
                'risk_level' => $processingActivity->getRiskLevel(),
                'processes_special_categories' => $processingActivity->getProcessesSpecialCategories(),
                'processes_criminal_data' => $processingActivity->getProcessesCriminalData(),
                'has_automated_decision_making' => $processingActivity->getHasAutomatedDecisionMaking(),
                'has_third_country_transfer' => $processingActivity->getHasThirdCountryTransfer(),
            ],
        ];
    }

    /**
     * Generate VVT export data (all processing activities for a tenant)
     *
     * Returns array suitable for PDF/CSV export per Art. 30 requirements
     */
    public function generateVVTExport(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $processingActivities = $this->processingActivityRepository->findActiveByTenant($tenant);

        $export = [
            'tenant' => [
                'name' => $tenant->getName(),
                'code' => $tenant->getCode(),
            ],
            'generated_at' => new DateTime(),
            'generated_by' => $this->security->getUser()?->getUserIdentifier(),
            'total_activities' => count($processingActivities),
            'processing_activities' => [],
        ];

        foreach ($processingActivities as $processingActivity) {
            $export['processing_activities'][] = [
                'id' => $processingActivity->getId(),
                'name' => $processingActivity->getName(),
                'description' => $processingActivity->getDescription(),

                // Art. 30(1)(a)
                'purposes' => $processingActivity->getPurposes(),

                // Art. 30(1)(b)
                'data_subject_categories' => $processingActivity->getDataSubjectCategories(),
                'estimated_data_subjects_count' => $processingActivity->getEstimatedDataSubjectsCount(),

                // Art. 30(1)(c)
                'personal_data_categories' => $processingActivity->getPersonalDataCategories(),
                'processes_special_categories' => $processingActivity->getProcessesSpecialCategories(),
                'special_categories_details' => $processingActivity->getSpecialCategoriesDetails(),
                'processes_criminal_data' => $processingActivity->getProcessesCriminalData(),

                // Art. 30(1)(d)
                'recipient_categories' => $processingActivity->getRecipientCategories(),
                'recipient_details' => $processingActivity->getRecipientDetails(),

                // Art. 30(1)(e)
                'has_third_country_transfer' => $processingActivity->getHasThirdCountryTransfer(),
                'third_countries' => $processingActivity->getThirdCountries(),
                'transfer_safeguards' => $processingActivity->getTransferSafeguards(),

                // Art. 30(1)(f)
                'retention_period' => $processingActivity->getRetentionPeriod(),
                'retention_period_days' => $processingActivity->getRetentionPeriodDays(),
                'retention_legal_basis' => $processingActivity->getRetentionLegalBasis(),

                // Art. 30(1)(g)
                'technical_organizational_measures' => $processingActivity->getTechnicalOrganizationalMeasures(),
                'implemented_controls' => array_map(
                    fn($control): array => [
                        'id' => $control->getControlId(),
                        'name' => $control->getName(),
                    ],
                    $processingActivity->getImplementedControls()->toArray()
                ),

                // Legal basis
                'legal_basis' => $processingActivity->getLegalBasis(),
                'legal_basis_details' => $processingActivity->getLegalBasisDetails(),
                'legal_basis_special_categories' => $processingActivity->getLegalBasisSpecialCategories(),

                // Organizational
                'responsible_department' => $processingActivity->getResponsibleDepartment(),
                'contact_person' => $processingActivity->getContactPerson()?->getUsername(),
                'data_protection_officer' => $processingActivity->getDataProtectionOfficer()?->getUsername(),

                // Processors & Joint Controllers
                'involves_processors' => $processingActivity->getInvolvesProcessors(),
                'processors' => $processingActivity->getProcessors(),
                'is_joint_controller' => $processingActivity->getIsJointController(),
                'joint_controller_details' => $processingActivity->getJointControllerDetails(),

                // Risk & DPIA
                'is_high_risk' => $processingActivity->getIsHighRisk(),
                'requires_dpia' => $processingActivity->requiresDPIA(),
                'dpia_completed' => $processingActivity->getDpiaCompleted(),
                'dpia_date' => $processingActivity->getDpiaDate()?->format('Y-m-d'),
                'risk_level' => $processingActivity->getRiskLevel(),

                // Automated decision-making
                'has_automated_decision_making' => $processingActivity->getHasAutomatedDecisionMaking(),
                'automated_decision_making_details' => $processingActivity->getAutomatedDecisionMakingDetails(),

                // Data sources
                'data_sources' => $processingActivity->getDataSources(),

                // Status & Dates
                'status' => $processingActivity->getStatus(),
                'start_date' => $processingActivity->getStartDate()?->format('Y-m-d'),
                'end_date' => $processingActivity->getEndDate()?->format('Y-m-d'),
                'last_review_date' => $processingActivity->getLastReviewDate()?->format('Y-m-d'),
                'next_review_date' => $processingActivity->getNextReviewDate()?->format('Y-m-d'),

                // Compliance
                'completeness_percentage' => $processingActivity->getCompletenessPercentage(),
                'is_complete' => $processingActivity->isComplete(),
            ];
        }

        return $export;
    }

    /**
     * Mark a processing activity for review
     */
    public function markForReview(ProcessingActivity $processingActivity, ?DateTimeInterface $reviewDate = null): void
    {
        if (!$reviewDate instanceof DateTimeInterface) {
            // Default: review in 12 months
            $reviewDate = new DateTime('+12 months');
        }

        $processingActivity->setNextReviewDate($reviewDate);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'processing_activity.marked_for_review',
            'ProcessingActivity',
            $processingActivity->getId(),
            ['review_date' => $reviewDate->format('Y-m-d')]
        );
    }

    /**
     * Complete review of a processing activity
     */
    public function completeReview(ProcessingActivity $processingActivity): void
    {
        $processingActivity->setLastReviewDate(new DateTime());
        $processingActivity->setNextReviewDate(new DateTime('+12 months')); // Schedule next review

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'processing_activity.review_completed',
            'ProcessingActivity',
            $processingActivity->getId(),
            []
        );
    }

    /**
     * Activate a draft processing activity
     */
    public function activate(ProcessingActivity $processingActivity): void
    {
        // Validate before activation
        $errors = $this->validate($processingActivity);
        if ($errors !== []) {
            throw new RuntimeException(
                'Cannot activate processing activity with validation errors: ' . implode(', ', $errors)
            );
        }

        $processingActivity->setStatus('active');
        $processingActivity->setStartDate(new DateTime());

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'processing_activity.activated',
            'ProcessingActivity',
            $processingActivity->getId(),
            ['name' => $processingActivity->getName()]
        );
    }

    /**
     * Archive a processing activity (when processing ends)
     */
    public function archive(ProcessingActivity $processingActivity): void
    {
        $processingActivity->setStatus('archived');
        $processingActivity->setEndDate(new DateTime());

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'processing_activity.archived',
            'ProcessingActivity',
            $processingActivity->getId(),
            ['name' => $processingActivity->getName()]
        );
    }

    /**
     * Clone a processing activity (for creating similar activities)
     */
    public function clone(ProcessingActivity $processingActivity, string $newName): ProcessingActivity
    {
        $clone = new ProcessingActivity();
        $clone->setName($newName);
        $clone->setDescription($processingActivity->getDescription());
        $clone->setPurposes($processingActivity->getPurposes());
        $clone->setDataSubjectCategories($processingActivity->getDataSubjectCategories());
        $clone->setPersonalDataCategories($processingActivity->getPersonalDataCategories());
        $clone->setProcessesSpecialCategories($processingActivity->getProcessesSpecialCategories());
        $clone->setSpecialCategoriesDetails($processingActivity->getSpecialCategoriesDetails());
        $clone->setProcessesCriminalData($processingActivity->getProcessesCriminalData());
        $clone->setRecipientCategories($processingActivity->getRecipientCategories());
        $clone->setHasThirdCountryTransfer($processingActivity->getHasThirdCountryTransfer());
        $clone->setThirdCountries($processingActivity->getThirdCountries());
        $clone->setTransferSafeguards($processingActivity->getTransferSafeguards());
        $clone->setRetentionPeriod($processingActivity->getRetentionPeriod());
        $clone->setRetentionPeriodDays($processingActivity->getRetentionPeriodDays());
        $clone->setRetentionLegalBasis($processingActivity->getRetentionLegalBasis());
        $clone->setTechnicalOrganizationalMeasures($processingActivity->getTechnicalOrganizationalMeasures());
        $clone->setLegalBasis($processingActivity->getLegalBasis());
        $clone->setLegalBasisDetails($processingActivity->getLegalBasisDetails());
        $clone->setLegalBasisSpecialCategories($processingActivity->getLegalBasisSpecialCategories());
        $clone->setResponsibleDepartment($processingActivity->getResponsibleDepartment());
        $clone->setContactPerson($processingActivity->getContactPerson());
        $clone->setDataProtectionOfficer($processingActivity->getDataProtectionOfficer());
        $clone->setInvolvesProcessors($processingActivity->getInvolvesProcessors());
        $clone->setProcessors($processingActivity->getProcessors());
        $clone->setIsJointController($processingActivity->getIsJointController());
        $clone->setJointControllerDetails($processingActivity->getJointControllerDetails());
        $clone->setIsHighRisk($processingActivity->getIsHighRisk());
        $clone->setRiskLevel($processingActivity->getRiskLevel());
        $clone->setDataSources($processingActivity->getDataSources());
        $clone->setHasAutomatedDecisionMaking($processingActivity->getHasAutomatedDecisionMaking());
        $clone->setAutomatedDecisionMakingDetails($processingActivity->getAutomatedDecisionMakingDetails());

        // Clone controls
        foreach ($processingActivity->getImplementedControls() as $implementedControl) {
            $clone->addImplementedControl($implementedControl);
        }

        // Set status to draft
        $clone->setStatus('draft');

        return $this->create($clone);
    }

    /**
     * Get activities processing special categories (high risk)
     */
    public function findProcessingSpecialCategories(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->processingActivityRepository->findProcessingSpecialCategories($tenant);
    }

    /**
     * Get activities with third country transfers
     */
    public function findWithThirdCountryTransfers(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->processingActivityRepository->findWithThirdCountryTransfers($tenant);
    }

    /**
     * Calculate overall VVT compliance score for tenant
     */
    public function calculateComplianceScore(): array
    {
        $all = $this->findAll();

        if ($all === []) {
            return [
                'overall_score' => 0,
                'total_activities' => 0,
                'complete_activities' => 0,
                'incomplete_activities' => 0,
                'dpia_required' => 0,
                'dpia_completed' => 0,
                'average_completeness' => 0,
            ];
        }

        $completeCount = 0;
        $dpiaRequiredCount = 0;
        $dpiaCompletedCount = 0;
        $totalCompleteness = 0;

        foreach ($all as $pa) {
            if ($pa->isComplete()) {
                $completeCount++;
            }

            if ($pa->requiresDPIA()) {
                $dpiaRequiredCount++;
                if ($pa->getDpiaCompleted()) {
                    $dpiaCompletedCount++;
                }
            }

            $totalCompleteness += $pa->getCompletenessPercentage();
        }

        $avgCompleteness = round($totalCompleteness / count($all));

        // Overall score: 70% completeness + 30% DPIA compliance
        $completenessScore = ($completeCount / count($all)) * 70;
        $dpiaScore = $dpiaRequiredCount > 0
            ? ($dpiaCompletedCount / $dpiaRequiredCount) * 30
            : 30; // Full score if no DPIA required

        $overallScore = round($completenessScore + $dpiaScore);

        return [
            'overall_score' => $overallScore,
            'total_activities' => count($all),
            'complete_activities' => $completeCount,
            'incomplete_activities' => count($all) - $completeCount,
            'dpia_required' => $dpiaRequiredCount,
            'dpia_completed' => $dpiaCompletedCount,
            'average_completeness' => $avgCompleteness,
        ];
    }
}
