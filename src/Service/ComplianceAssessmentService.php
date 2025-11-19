<?php

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for assessing compliance and calculating fulfillment percentages
 *
 * Architecture: Tenant-aware assessment service
 * - Assessments are tenant-specific (each tenant assesses their own fulfillment)
 * - Updates ComplianceRequirementFulfillment instead of deprecated ComplianceRequirement fields
 */
class ComplianceAssessmentService
{
    public function __construct(
        private ComplianceRequirementRepository $requirementRepository,
        private ComplianceMappingService $mappingService,
        private EntityManagerInterface $entityManager,
        private ComplianceRequirementFulfillmentService $fulfillmentService,
        private TenantContext $tenantContext
    ) {}

    /**
     * Assess entire framework and update all requirement fulfillment percentages
     *
     * Architecture: Tenant-specific assessment
     * - Updates the current tenant's ComplianceRequirementFulfillment records
     * - Does NOT modify the global ComplianceRequirement entity
     */
    public function assessFramework(ComplianceFramework $framework): array
    {
        $requirements = $this->requirementRepository->findByFramework($framework);
        $assessmentResults = [];
        $tenant = $this->tenantContext->getCurrentTenant();

        foreach ($requirements as $requirement) {
            $result = $this->assessRequirement($requirement);
            $assessmentResults[] = $result;

            // Update tenant-specific fulfillment (not global requirement!)
            $fulfillment = $this->fulfillmentService->getOrCreateFulfillment($tenant, $requirement);

            // Only update if tenant can edit (not inherited from parent)
            if ($this->fulfillmentService->canEditFulfillment($fulfillment, $tenant)) {
                $fulfillment->setFulfillmentPercentage($result['calculated_fulfillment']);
                $fulfillment->setLastReviewDate(new \DateTimeImmutable());
                $fulfillment->setUpdatedAt(new \DateTimeImmutable());

                // Auto-update status based on percentage
                if ($result['calculated_fulfillment'] >= 100) {
                    $fulfillment->setStatus('implemented');
                } elseif ($result['calculated_fulfillment'] > 0) {
                    $fulfillment->setStatus('in_progress');
                } else {
                    $fulfillment->setStatus('not_started');
                }

                if (!$fulfillment->getId()) {
                    $this->entityManager->persist($fulfillment);
                }
            }
        }

        $this->entityManager->flush();

        return [
            'framework' => $framework->getName(),
            'assessment_date' => new \DateTimeImmutable(),
            'total_requirements' => count($requirements),
            'requirements_assessed' => count($assessmentResults),
            'overall_compliance' => $framework->getCompliancePercentage(),
            'details' => $assessmentResults,
        ];
    }

    /**
     * Assess a single requirement based on mapped data sources
     *
     * Architecture: Tenant-aware assessment
     * - Checks tenant-specific applicability from ComplianceRequirementFulfillment
     */
    public function assessRequirement(ComplianceRequirement $requirement): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $fulfillment = $this->fulfillmentService->getOrCreateFulfillment($tenant, $requirement);

        if (!$fulfillment->isApplicable()) {
            return [
                'requirement_id' => $requirement->getRequirementId(),
                'calculated_fulfillment' => 0,
                'reason' => 'Not applicable',
                'data_sources' => [],
            ];
        }

        $dataAnalysis = $this->mappingService->getDataReuseAnalysis($requirement);
        $calculatedFulfillment = $this->calculateFulfillmentFromSources($dataAnalysis['sources']);

        return [
            'requirement_id' => $requirement->getRequirementId(),
            'title' => $requirement->getTitle(),
            'calculated_fulfillment' => $calculatedFulfillment,
            'confidence' => $dataAnalysis['confidence'],
            'data_sources' => $dataAnalysis['sources'],
            'gaps' => $this->identifyGaps($requirement, $calculatedFulfillment),
        ];
    }

    /**
     * Calculate fulfillment percentage from multiple data sources
     */
    private function calculateFulfillmentFromSources(array $sources): int
    {
        if (empty($sources)) {
            return 0;
        }

        $totalContribution = 0;
        $sourceCount = 0;

        foreach ($sources as $sourceType => $sourceData) {
            if (isset($sourceData['contribution'])) {
                $totalContribution += $sourceData['contribution'];
                $sourceCount++;
            }
        }

        if ($sourceCount === 0) {
            return 0;
        }

        // Average contribution across all sources
        return (int) round($totalContribution / $sourceCount);
    }

    /**
     * Identify gaps between current state and full compliance
     */
    private function identifyGaps(ComplianceRequirement $requirement, int $currentFulfillment): array
    {
        $gaps = [];

        if ($currentFulfillment < 100) {
            $gap = 100 - $currentFulfillment;

            if (!$requirement->getMappedControls()->isEmpty()) {
                $partialControls = [];
                foreach ($requirement->getMappedControls() as $control) {
                    if ($control->getImplementationStatus() !== 'implemented'
                        || ($control->getImplementationPercentage() ?? 0) < 100) {
                        $partialControls[] = [
                            'control_id' => $control->getControlId(),
                            'status' => $control->getImplementationStatus(),
                            'implementation' => $control->getImplementationPercentage() ?? 0,
                        ];
                    }
                }

                if (!empty($partialControls)) {
                    $gaps[] = [
                        'type' => 'incomplete_controls',
                        'severity' => $gap > 50 ? 'high' : 'medium',
                        'description' => 'Mapped controls are not fully implemented',
                        'details' => $partialControls,
                    ];
                }
            } else {
                $gaps[] = [
                    'type' => 'no_controls_mapped',
                    'severity' => 'high',
                    'description' => 'No ISO 27001 controls mapped to this requirement',
                    'recommendation' => 'Map relevant controls to leverage existing ISMS data',
                ];
            }

            $mapping = $requirement->getDataSourceMapping() ?? [];

            // Check for missing BCM data if required
            if (isset($mapping['bcm_required']) && $mapping['bcm_required'] === true) {
                $gaps[] = [
                    'type' => 'bcm_data_needed',
                    'severity' => 'medium',
                    'description' => 'Business continuity data is required but may be incomplete',
                    'recommendation' => 'Complete Business Impact Analysis for critical processes',
                ];
            }

            // Check for missing incident data if required
            if (isset($mapping['incident_management']) && $mapping['incident_management'] === true) {
                $gaps[] = [
                    'type' => 'incident_data_needed',
                    'severity' => 'medium',
                    'description' => 'Incident management evidence is required',
                    'recommendation' => 'Document and track security incidents',
                ];
            }
        }

        return $gaps;
    }

    /**
     * Get compliance dashboard data for a framework
     */
    public function getComplianceDashboard(ComplianceFramework $framework): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $stats = $this->requirementRepository->getFrameworkStatisticsForTenant($framework, $tenant);
        $gaps = $this->requirementRepository->findGapsByFramework($framework);
        $criticalGaps = $this->requirementRepository->findByFrameworkAndPriority($framework, 'critical');

        // Calculate data reuse metrics
        $requirements = $this->requirementRepository->findApplicableByFramework($framework);
        $totalTimeSavings = 0;

        foreach ($requirements as $requirement) {
            $reuseValue = $this->mappingService->calculateDataReuseValue($requirement);
            $totalTimeSavings += $reuseValue['estimated_hours_saved'];
        }

        return [
            'framework' => [
                'id' => $framework->getId(),
                'code' => $framework->getCode(),
                'name' => $framework->getName(),
                'version' => $framework->getVersion(),
                'mandatory' => $framework->isMandatory(),
            ],
            'statistics' => $stats,
            'compliance_percentage' => $framework->getCompliancePercentage(),
            'gaps' => [
                'total' => count($gaps),
                'critical' => count($criticalGaps),
                'list' => array_slice($gaps, 0, 10), // Top 10 gaps
            ],
            'data_reuse' => [
                'total_hours_saved' => $totalTimeSavings,
                'total_days_saved' => round($totalTimeSavings / 8, 1),
            ],
            'recommendations' => $this->generateRecommendations($framework, $gaps),
        ];
    }

    /**
     * Generate recommendations for improving compliance
     */
    private function generateRecommendations(ComplianceFramework $framework, array $gaps): array
    {
        $recommendations = [];

        if (count($gaps) > 0) {
            // Prioritize critical gaps
            $criticalGaps = array_filter($gaps, fn($gap) => $gap->getPriority() === 'critical');

            if (count($criticalGaps) > 0) {
                $recommendations[] = [
                    'priority' => 'high',
                    'title' => 'Address Critical Gaps First',
                    'description' => sprintf(
                        'Focus on %d critical requirements with low fulfillment',
                        count($criticalGaps)
                    ),
                    'action' => 'Review critical requirements and map to existing controls',
                ];
            }

            // Check for unmapped requirements
            $unmappedCount = 0;
            foreach ($gaps as $gap) {
                if ($gap->getMappedControls()->isEmpty()) {
                    $unmappedCount++;
                }
            }

            if ($unmappedCount > 0) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'title' => 'Map Requirements to ISO 27001 Controls',
                    'description' => sprintf(
                        '%d requirements have no mapped controls. Mapping them will enable automatic data reuse.',
                        $unmappedCount
                    ),
                    'action' => 'Use compliance mapping tool to link requirements with existing controls',
                ];
            }

            // Suggest leveraging BCM data
            $recommendations[] = [
                'priority' => 'medium',
                'title' => 'Leverage BCM Data for Resilience Requirements',
                'description' => 'Business continuity data can automatically satisfy many resilience-related requirements',
                'action' => 'Complete Business Impact Analysis to maximize data reuse',
            ];
        } else {
            $recommendations[] = [
                'priority' => 'low',
                'title' => 'Maintain Compliance',
                'description' => 'All requirements are currently fulfilled. Continue regular assessments.',
                'action' => 'Schedule periodic compliance reviews',
            ];
        }

        return $recommendations;
    }

    /**
     * Compare compliance across multiple frameworks
     */
    public function compareFrameworks(array $frameworks): array
    {
        $comparison = [];

        $tenant = $this->tenantContext->getCurrentTenant();
        foreach ($frameworks as $framework) {
            $stats = $this->requirementRepository->getFrameworkStatisticsForTenant($framework, $tenant);
            $comparison[] = [
                'framework' => $framework->getName(),
                'code' => $framework->getCode(),
                'compliance_percentage' => $framework->getCompliancePercentage(),
                'total_requirements' => $stats['total'],
                'fulfilled' => $stats['fulfilled'],
                'gaps' => $stats['applicable'] - $stats['fulfilled'],
            ];
        }

        return $comparison;
    }
}
