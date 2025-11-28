<?php

namespace App\Service;

use DateTimeImmutable;
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
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly ComplianceMappingService $complianceMappingService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceRequirementFulfillmentService $complianceRequirementFulfillmentService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Assess entire framework and update all requirement fulfillment percentages
     *
     * Architecture: Tenant-specific assessment
     * - Updates the current tenant's ComplianceRequirementFulfillment records
     * - Does NOT modify the global ComplianceRequirement entity
     */
    public function assessFramework(ComplianceFramework $complianceFramework): array
    {
        $requirements = $this->complianceRequirementRepository->findByFramework($complianceFramework);
        $assessmentResults = [];
        $tenant = $this->tenantContext->getCurrentTenant();

        foreach ($requirements as $requirement) {
            $result = $this->assessRequirement($requirement);
            $assessmentResults[] = $result;

            // Update tenant-specific fulfillment (not global requirement!)
            $fulfillment = $this->complianceRequirementFulfillmentService->getOrCreateFulfillment($tenant, $requirement);

            // Only update if tenant can edit (not inherited from parent)
            if ($this->complianceRequirementFulfillmentService->canEditFulfillment($fulfillment, $tenant)) {
                $fulfillment->setFulfillmentPercentage($result['calculated_fulfillment']);
                $fulfillment->setLastReviewDate(new DateTimeImmutable());
                $fulfillment->setUpdatedAt(new DateTimeImmutable());

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

        // Calculate overall compliance from tenant-specific statistics
        $tenant = $this->tenantContext->getCurrentTenant();
        $stats = $this->complianceRequirementRepository->getFrameworkStatisticsForTenant($complianceFramework, $tenant);
        $overallCompliance = $stats['applicable'] > 0
            ? round(($stats['fulfilled'] / $stats['applicable']) * 100, 2)
            : 0;

        return [
            'framework' => $complianceFramework->getName(),
            'assessment_date' => new DateTimeImmutable(),
            'total_requirements' => count($requirements),
            'requirements_assessed' => count($assessmentResults),
            'overall_compliance' => $overallCompliance,
            'details' => $assessmentResults,
        ];
    }

    /**
     * Assess a single requirement based on mapped data sources
     *
     * Architecture: Tenant-aware assessment
     * - Checks tenant-specific applicability from ComplianceRequirementFulfillment
     */
    public function assessRequirement(ComplianceRequirement $complianceRequirement): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $fulfillment = $this->complianceRequirementFulfillmentService->getOrCreateFulfillment($tenant, $complianceRequirement);

        if (!$fulfillment->isApplicable()) {
            return [
                'requirement_id' => $complianceRequirement->getRequirementId(),
                'calculated_fulfillment' => 0,
                'reason' => 'Not applicable',
                'data_sources' => [],
            ];
        }

        $dataAnalysis = $this->complianceMappingService->getDataReuseAnalysis($complianceRequirement);
        $calculatedFulfillment = $this->calculateFulfillmentFromSources($dataAnalysis['sources']);

        return [
            'requirement_id' => $complianceRequirement->getRequirementId(),
            'title' => $complianceRequirement->getTitle(),
            'calculated_fulfillment' => $calculatedFulfillment,
            'confidence' => $dataAnalysis['confidence'],
            'data_sources' => $dataAnalysis['sources'],
            'gaps' => $this->identifyGaps($complianceRequirement, $calculatedFulfillment),
        ];
    }

    /**
     * Calculate fulfillment percentage from multiple data sources
     */
    private function calculateFulfillmentFromSources(array $sources): int
    {
        if ($sources === []) {
            return 0;
        }

        $totalContribution = 0;
        $sourceCount = 0;

        foreach ($sources as $source) {
            if (isset($source['contribution'])) {
                $totalContribution += $source['contribution'];
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
    private function identifyGaps(ComplianceRequirement $complianceRequirement, int $currentFulfillment): array
    {
        $gaps = [];

        if ($currentFulfillment < 100) {
            $gap = 100 - $currentFulfillment;

            if (!$complianceRequirement->getMappedControls()->isEmpty()) {
                $partialControls = [];
                foreach ($complianceRequirement->getMappedControls() as $mappedControl) {
                    if ($mappedControl->getImplementationStatus() !== 'implemented'
                        || ($mappedControl->getImplementationPercentage() ?? 0) < 100) {
                        $partialControls[] = [
                            'control_id' => $mappedControl->getControlId(),
                            'status' => $mappedControl->getImplementationStatus(),
                            'implementation' => $mappedControl->getImplementationPercentage() ?? 0,
                        ];
                    }
                }

                if ($partialControls !== []) {
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

            $mapping = $complianceRequirement->getDataSourceMapping() ?? [];

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
    public function getComplianceDashboard(ComplianceFramework $complianceFramework): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $stats = $this->complianceRequirementRepository->getFrameworkStatisticsForTenant($complianceFramework, $tenant);
        $gaps = $this->complianceRequirementRepository->findGapsByFramework($complianceFramework);
        $criticalGaps = $this->complianceRequirementRepository->findByFrameworkAndPriority($complianceFramework, 'critical');

        // Calculate data reuse metrics
        $requirements = $this->complianceRequirementRepository->findApplicableByFramework($complianceFramework);
        $totalTimeSavings = 0;

        foreach ($requirements as $requirement) {
            $reuseValue = $this->complianceMappingService->calculateDataReuseValue($requirement);
            $totalTimeSavings += $reuseValue['estimated_hours_saved'];
        }

        // Calculate compliance percentage from tenant-specific statistics
        $compliancePercentage = 0;
        if ($stats['applicable'] > 0) {
            $compliancePercentage = round(($stats['fulfilled'] / $stats['applicable']) * 100, 2);
        }

        return [
            'framework' => [
                'id' => $complianceFramework->id,
                'code' => $complianceFramework->getCode(),
                'name' => $complianceFramework->getName(),
                'version' => $complianceFramework->getVersion(),
                'mandatory' => $complianceFramework->isMandatory(),
            ],
            'statistics' => $stats,
            'compliance_percentage' => $compliancePercentage,
            'gaps' => [
                'total' => count($gaps),
                'critical' => count($criticalGaps),
                'list' => array_slice($gaps, 0, 10), // Top 10 gaps
            ],
            'data_reuse' => [
                'total_hours_saved' => $totalTimeSavings,
                'total_days_saved' => round($totalTimeSavings / 8, 1),
            ],
            'recommendations' => $this->generateRecommendations($gaps),
        ];
    }

    /**
     * Generate recommendations for improving compliance
     */
    private function generateRecommendations(array $gaps): array
    {
        $recommendations = [];

        if (count($gaps) > 0) {
            // Prioritize critical gaps
            $criticalGaps = array_filter($gaps, fn($gap): bool => $gap->getPriority() === 'critical');

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
            $stats = $this->complianceRequirementRepository->getFrameworkStatisticsForTenant($framework, $tenant);

            // Calculate compliance percentage from tenant-specific statistics
            $compliancePercentage = $stats['applicable'] > 0
                ? round(($stats['fulfilled'] / $stats['applicable']) * 100, 2)
                : 0;

            $comparison[] = [
                'framework' => $framework->getName(),
                'code' => $framework->getCode(),
                'compliance_percentage' => $compliancePercentage,
                'total_requirements' => $stats['total'],
                'fulfilled' => $stats['fulfilled'],
                'gaps' => $stats['applicable'] - $stats['fulfilled'],
            ];
        }

        return $comparison;
    }
}
