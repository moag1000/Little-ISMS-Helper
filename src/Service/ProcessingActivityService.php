<?php

namespace App\Service;

use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\User;
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
        private ProcessingActivityRepository $repository,
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private Security $security,
        private AuditLogger $auditLogger
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
        return $this->repository->findByTenant($tenant);
    }

    /**
     * Find active processing activities
     */
    public function findActive(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findActiveByTenant($tenant);
    }

    /**
     * Find processing activities requiring DPIA
     */
    public function findRequiringDPIA(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findRequiringDPIA($tenant);
    }

    /**
     * Find incomplete processing activities (missing required fields)
     */
    public function findIncomplete(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findIncomplete($tenant);
    }

    /**
     * Find processing activities due for review
     */
    public function findDueForReview(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findDueForReview($tenant);
    }

    /**
     * Search processing activities
     */
    public function search(string $query): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->search($tenant, $query);
    }

    /**
     * Get dashboard statistics
     */
    public function getDashboardStatistics(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $stats = $this->repository->getStatistics($tenant);

        // Add compliance score
        $all = $this->findAll();
        $completeCount = count(array_filter($all, fn($pa) => $pa->isComplete()));
        $stats['completeness_rate'] = count($all) > 0 ? round(($completeCount / count($all)) * 100) : 0;

        // Add breakdown by legal basis
        $stats['by_legal_basis'] = $this->repository->countByLegalBasis($tenant);

        // Add breakdown by risk level
        $stats['by_risk_level'] = $this->repository->countByRiskLevel($tenant);

        return $stats;
    }

    /**
     * Validate processing activity completeness per Art. 30 GDPR
     *
     * Returns array of validation errors (empty if valid)
     */
    public function validate(ProcessingActivity $pa): array
    {
        $errors = [];

        // Mandatory fields per Art. 30(1)
        if (empty($pa->getName())) {
            $errors[] = 'Name of processing activity is required (Art. 30(1)(a))';
        }

        if (empty($pa->getPurposes())) {
            $errors[] = 'Purpose(s) of processing are required (Art. 30(1)(a))';
        }

        if (empty($pa->getDataSubjectCategories())) {
            $errors[] = 'Categories of data subjects are required (Art. 30(1)(b))';
        }

        if (empty($pa->getPersonalDataCategories())) {
            $errors[] = 'Categories of personal data are required (Art. 30(1)(c))';
        }

        if (empty($pa->getLegalBasis())) {
            $errors[] = 'Legal basis for processing is required (Art. 6 GDPR)';
        }

        if (empty($pa->getRetentionPeriod())) {
            $errors[] = 'Retention period/deletion deadline is required (Art. 30(1)(f))';
        }

        if (empty($pa->getTechnicalOrganizationalMeasures())) {
            $errors[] = 'Technical and organizational measures must be described (Art. 30(1)(g))';
        }

        // Conditional validations
        if ($pa->getLegalBasis() === 'legitimate_interests' && empty($pa->getLegalBasisDetails())) {
            $errors[] = 'Legitimate interests must be detailed and documented (Art. 6(1)(f))';
        }

        if ($pa->getProcessesSpecialCategories() && empty($pa->getLegalBasisSpecialCategories())) {
            $errors[] = 'Legal basis for processing special categories must be specified (Art. 9(2))';
        }

        if ($pa->getProcessesSpecialCategories() && empty($pa->getSpecialCategoriesDetails())) {
            $errors[] = 'Which special categories are processed must be specified (Art. 9)';
        }

        if ($pa->getHasThirdCountryTransfer() && empty($pa->getThirdCountries())) {
            $errors[] = 'Third countries receiving data must be listed (Art. 30(1)(e))';
        }

        if ($pa->getHasThirdCountryTransfer() && empty($pa->getTransferSafeguards())) {
            $errors[] = 'Legal safeguards for third country transfer must be specified (Art. 44-49)';
        }

        if ($pa->getInvolvesProcessors() && empty($pa->getProcessors())) {
            $errors[] = 'Processor details must be provided (Art. 28)';
        }

        if ($pa->getIsJointController() && empty($pa->getJointControllerDetails())) {
            $errors[] = 'Joint controller arrangement must be documented (Art. 26)';
        }

        if ($pa->getHasAutomatedDecisionMaking() && empty($pa->getAutomatedDecisionMakingDetails())) {
            $errors[] = 'Automated decision-making logic and implications must be explained (Art. 22)';
        }

        // DPIA requirement check
        if ($pa->requiresDPIA() && !$pa->getDpiaCompleted()) {
            $errors[] = 'Data Protection Impact Assessment (DPIA) is required for this processing activity (Art. 35)';
        }

        return $errors;
    }

    /**
     * Check if processing activity is compliant with GDPR Art. 30
     */
    public function isCompliant(ProcessingActivity $pa): bool
    {
        return empty($this->validate($pa));
    }

    /**
     * Generate compliance report for a processing activity
     */
    public function generateComplianceReport(ProcessingActivity $pa): array
    {
        $errors = $this->validate($pa);
        $completeness = $pa->getCompletenessPercentage();

        return [
            'processing_activity_id' => $pa->getId(),
            'name' => $pa->getName(),
            'status' => $pa->getStatus(),
            'completeness_percentage' => $completeness,
            'is_compliant' => empty($errors),
            'validation_errors' => $errors,
            'compliance_checks' => [
                'art_30_mandatory_fields' => $completeness === 100,
                'legal_basis_documented' => !empty($pa->getLegalBasis()),
                'retention_period_defined' => !empty($pa->getRetentionPeriod()),
                'toms_documented' => !empty($pa->getTechnicalOrganizationalMeasures()),
                'dpia_completed_if_required' => !$pa->requiresDPIA() || $pa->getDpiaCompleted(),
                'special_categories_legal_basis' => !$pa->getProcessesSpecialCategories() || !empty($pa->getLegalBasisSpecialCategories()),
                'third_country_safeguards' => !$pa->getHasThirdCountryTransfer() || !empty($pa->getTransferSafeguards()),
            ],
            'risk_assessment' => [
                'is_high_risk' => $pa->getIsHighRisk(),
                'requires_dpia' => $pa->requiresDPIA(),
                'dpia_completed' => $pa->getDpiaCompleted(),
                'risk_level' => $pa->getRiskLevel(),
                'processes_special_categories' => $pa->getProcessesSpecialCategories(),
                'processes_criminal_data' => $pa->getProcessesCriminalData(),
                'has_automated_decision_making' => $pa->getHasAutomatedDecisionMaking(),
                'has_third_country_transfer' => $pa->getHasThirdCountryTransfer(),
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
        $processingActivities = $this->repository->findActiveByTenant($tenant);

        $export = [
            'tenant' => [
                'name' => $tenant->getName(),
                'code' => $tenant->getCode(),
            ],
            'generated_at' => new \DateTime(),
            'generated_by' => $this->security->getUser()?->getUserIdentifier(),
            'total_activities' => count($processingActivities),
            'processing_activities' => [],
        ];

        foreach ($processingActivities as $pa) {
            $export['processing_activities'][] = [
                'id' => $pa->getId(),
                'name' => $pa->getName(),
                'description' => $pa->getDescription(),

                // Art. 30(1)(a)
                'purposes' => $pa->getPurposes(),

                // Art. 30(1)(b)
                'data_subject_categories' => $pa->getDataSubjectCategories(),
                'estimated_data_subjects_count' => $pa->getEstimatedDataSubjectsCount(),

                // Art. 30(1)(c)
                'personal_data_categories' => $pa->getPersonalDataCategories(),
                'processes_special_categories' => $pa->getProcessesSpecialCategories(),
                'special_categories_details' => $pa->getSpecialCategoriesDetails(),
                'processes_criminal_data' => $pa->getProcessesCriminalData(),

                // Art. 30(1)(d)
                'recipient_categories' => $pa->getRecipientCategories(),
                'recipient_details' => $pa->getRecipientDetails(),

                // Art. 30(1)(e)
                'has_third_country_transfer' => $pa->getHasThirdCountryTransfer(),
                'third_countries' => $pa->getThirdCountries(),
                'transfer_safeguards' => $pa->getTransferSafeguards(),

                // Art. 30(1)(f)
                'retention_period' => $pa->getRetentionPeriod(),
                'retention_period_days' => $pa->getRetentionPeriodDays(),
                'retention_legal_basis' => $pa->getRetentionLegalBasis(),

                // Art. 30(1)(g)
                'technical_organizational_measures' => $pa->getTechnicalOrganizationalMeasures(),
                'implemented_controls' => array_map(
                    fn($control) => [
                        'id' => $control->getControlId(),
                        'name' => $control->getName(),
                    ],
                    $pa->getImplementedControls()->toArray()
                ),

                // Legal basis
                'legal_basis' => $pa->getLegalBasis(),
                'legal_basis_details' => $pa->getLegalBasisDetails(),
                'legal_basis_special_categories' => $pa->getLegalBasisSpecialCategories(),

                // Organizational
                'responsible_department' => $pa->getResponsibleDepartment(),
                'contact_person' => $pa->getContactPerson()?->getUsername(),
                'data_protection_officer' => $pa->getDataProtectionOfficer()?->getUsername(),

                // Processors & Joint Controllers
                'involves_processors' => $pa->getInvolvesProcessors(),
                'processors' => $pa->getProcessors(),
                'is_joint_controller' => $pa->getIsJointController(),
                'joint_controller_details' => $pa->getJointControllerDetails(),

                // Risk & DPIA
                'is_high_risk' => $pa->getIsHighRisk(),
                'requires_dpia' => $pa->requiresDPIA(),
                'dpia_completed' => $pa->getDpiaCompleted(),
                'dpia_date' => $pa->getDpiaDate()?->format('Y-m-d'),
                'risk_level' => $pa->getRiskLevel(),

                // Automated decision-making
                'has_automated_decision_making' => $pa->getHasAutomatedDecisionMaking(),
                'automated_decision_making_details' => $pa->getAutomatedDecisionMakingDetails(),

                // Data sources
                'data_sources' => $pa->getDataSources(),

                // Status & Dates
                'status' => $pa->getStatus(),
                'start_date' => $pa->getStartDate()?->format('Y-m-d'),
                'end_date' => $pa->getEndDate()?->format('Y-m-d'),
                'last_review_date' => $pa->getLastReviewDate()?->format('Y-m-d'),
                'next_review_date' => $pa->getNextReviewDate()?->format('Y-m-d'),

                // Compliance
                'completeness_percentage' => $pa->getCompletenessPercentage(),
                'is_complete' => $pa->isComplete(),
            ];
        }

        return $export;
    }

    /**
     * Mark a processing activity for review
     */
    public function markForReview(ProcessingActivity $pa, ?\DateTimeInterface $reviewDate = null): void
    {
        if ($reviewDate === null) {
            // Default: review in 12 months
            $reviewDate = new \DateTime('+12 months');
        }

        $pa->setNextReviewDate($reviewDate);
        $this->entityManager->flush();

        $this->auditLogger->log(
            'processing_activity.marked_for_review',
            'ProcessingActivity',
            $pa->getId(),
            ['review_date' => $reviewDate->format('Y-m-d')]
        );
    }

    /**
     * Complete review of a processing activity
     */
    public function completeReview(ProcessingActivity $pa): void
    {
        $pa->setLastReviewDate(new \DateTime());
        $pa->setNextReviewDate(new \DateTime('+12 months')); // Schedule next review

        $this->entityManager->flush();

        $this->auditLogger->log(
            'processing_activity.review_completed',
            'ProcessingActivity',
            $pa->getId(),
            []
        );
    }

    /**
     * Activate a draft processing activity
     */
    public function activate(ProcessingActivity $pa): void
    {
        // Validate before activation
        $errors = $this->validate($pa);
        if (!empty($errors)) {
            throw new \RuntimeException(
                'Cannot activate processing activity with validation errors: ' . implode(', ', $errors)
            );
        }

        $pa->setStatus('active');
        $pa->setStartDate(new \DateTime());

        $this->entityManager->flush();

        $this->auditLogger->log(
            'processing_activity.activated',
            'ProcessingActivity',
            $pa->getId(),
            ['name' => $pa->getName()]
        );
    }

    /**
     * Archive a processing activity (when processing ends)
     */
    public function archive(ProcessingActivity $pa): void
    {
        $pa->setStatus('archived');
        $pa->setEndDate(new \DateTime());

        $this->entityManager->flush();

        $this->auditLogger->log(
            'processing_activity.archived',
            'ProcessingActivity',
            $pa->getId(),
            ['name' => $pa->getName()]
        );
    }

    /**
     * Clone a processing activity (for creating similar activities)
     */
    public function clone(ProcessingActivity $source, string $newName): ProcessingActivity
    {
        $clone = new ProcessingActivity();
        $clone->setName($newName);
        $clone->setDescription($source->getDescription());
        $clone->setPurposes($source->getPurposes());
        $clone->setDataSubjectCategories($source->getDataSubjectCategories());
        $clone->setPersonalDataCategories($source->getPersonalDataCategories());
        $clone->setProcessesSpecialCategories($source->getProcessesSpecialCategories());
        $clone->setSpecialCategoriesDetails($source->getSpecialCategoriesDetails());
        $clone->setProcessesCriminalData($source->getProcessesCriminalData());
        $clone->setRecipientCategories($source->getRecipientCategories());
        $clone->setHasThirdCountryTransfer($source->getHasThirdCountryTransfer());
        $clone->setThirdCountries($source->getThirdCountries());
        $clone->setTransferSafeguards($source->getTransferSafeguards());
        $clone->setRetentionPeriod($source->getRetentionPeriod());
        $clone->setRetentionPeriodDays($source->getRetentionPeriodDays());
        $clone->setRetentionLegalBasis($source->getRetentionLegalBasis());
        $clone->setTechnicalOrganizationalMeasures($source->getTechnicalOrganizationalMeasures());
        $clone->setLegalBasis($source->getLegalBasis());
        $clone->setLegalBasisDetails($source->getLegalBasisDetails());
        $clone->setLegalBasisSpecialCategories($source->getLegalBasisSpecialCategories());
        $clone->setResponsibleDepartment($source->getResponsibleDepartment());
        $clone->setContactPerson($source->getContactPerson());
        $clone->setDataProtectionOfficer($source->getDataProtectionOfficer());
        $clone->setInvolvesProcessors($source->getInvolvesProcessors());
        $clone->setProcessors($source->getProcessors());
        $clone->setIsJointController($source->getIsJointController());
        $clone->setJointControllerDetails($source->getJointControllerDetails());
        $clone->setIsHighRisk($source->getIsHighRisk());
        $clone->setRiskLevel($source->getRiskLevel());
        $clone->setDataSources($source->getDataSources());
        $clone->setHasAutomatedDecisionMaking($source->getHasAutomatedDecisionMaking());
        $clone->setAutomatedDecisionMakingDetails($source->getAutomatedDecisionMakingDetails());

        // Clone controls
        foreach ($source->getImplementedControls() as $control) {
            $clone->addImplementedControl($control);
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
        return $this->repository->findProcessingSpecialCategories($tenant);
    }

    /**
     * Get activities with third country transfers
     */
    public function findWithThirdCountryTransfers(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $this->repository->findWithThirdCountryTransfers($tenant);
    }

    /**
     * Calculate overall VVT compliance score for tenant
     */
    public function calculateComplianceScore(): array
    {
        $all = $this->findAll();

        if (empty($all)) {
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
