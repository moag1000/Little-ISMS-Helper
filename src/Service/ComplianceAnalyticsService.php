<?php

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\Control;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;

/**
 * Compliance Analytics Service
 *
 * Phase 7B: Advanced analytics for multi-framework compliance analysis.
 * Provides data for dashboards, charts, and comparative analysis.
 */
class ComplianceAnalyticsService
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly ControlRepository $controlRepository,
        private readonly TenantContext $tenantContext,
        private readonly ComplianceAssessmentService $assessmentService,
    ) {
    }

    /**
     * Get multi-framework comparison data for visualization
     *
     * @return array Data for stacked bar charts
     */
    public function getFrameworkComparison(): array
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $tenant = $this->tenantContext->getCurrentTenant();
        $comparison = [];

        foreach ($frameworks as $framework) {
            // Skip framework statistics if no tenant context (e.g., admin without tenant)
            if ($tenant === null) {
                $stats = ['total' => 0, 'applicable' => 0, 'fulfilled' => 0, 'in_progress' => 0];
            } else {
                $stats = $this->requirementRepository->getFrameworkStatisticsForTenant($framework, $tenant);
            }

            $comparison[] = [
                'id' => $framework->id,
                'name' => $framework->getName(),
                'code' => $framework->getCode(),
                'version' => $framework->getVersion(),
                'mandatory' => $framework->isMandatory(),
                'total' => $stats['total'],
                'applicable' => $stats['applicable'],
                'fulfilled' => $stats['fulfilled'],
                'in_progress' => $stats['in_progress'] ?? 0,
                'not_started' => $stats['applicable'] - ($stats['fulfilled'] + ($stats['in_progress'] ?? 0)),
                'compliance_percentage' => $stats['applicable'] > 0
                    ? round(($stats['fulfilled'] / $stats['applicable']) * 100, 1)
                    : 0,
            ];
        }

        // Sort by compliance percentage descending
        usort($comparison, fn($a, $b) => $b['compliance_percentage'] <=> $a['compliance_percentage']);

        return [
            'frameworks' => $comparison,
            'summary' => $this->calculateComplianceSummary($comparison),
        ];
    }

    /**
     * Calculate control coverage matrix
     * Shows which controls cover which frameworks
     *
     * @return array Matrix data for heat map visualization
     */
    public function getControlCoverageMatrix(): array
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $controls = $this->controlRepository->findAll();

        $matrix = [];
        $controlCoverage = [];

        foreach ($controls as $control) {
            $coveredFrameworks = [];
            $requirements = $this->requirementRepository->findByControl($control->getId());

            foreach ($requirements as $requirement) {
                $framework = $requirement->getFramework();
                if ($framework && !in_array($framework->getCode(), $coveredFrameworks, true)) {
                    $coveredFrameworks[] = $framework->getCode();
                }
            }

            if (count($coveredFrameworks) > 0) {
                $controlCoverage[$control->getControlId()] = [
                    'control_id' => $control->getControlId(),
                    'name' => $control->getName(),
                    'category' => $control->getCategory(),
                    'status' => $control->getImplementationStatus(),
                    'frameworks_covered' => $coveredFrameworks,
                    'coverage_count' => count($coveredFrameworks),
                ];
            }
        }

        // Sort by coverage count descending
        uasort($controlCoverage, fn($a, $b) => $b['coverage_count'] <=> $a['coverage_count']);

        // Create matrix for visualization
        foreach ($frameworks as $framework) {
            $frameworkControls = [];
            foreach ($controlCoverage as $control) {
                if (in_array($framework->getCode(), $control['frameworks_covered'], true)) {
                    $frameworkControls[] = $control['control_id'];
                }
            }
            $matrix[$framework->getCode()] = [
                'framework' => $framework->getName(),
                'controls' => $frameworkControls,
                'count' => count($frameworkControls),
            ];
        }

        return [
            'matrix' => $matrix,
            'controls' => array_values(array_slice($controlCoverage, 0, 50)), // Top 50 multi-framework controls
            'total_controls' => count($controls),
            'multi_framework_controls' => count(array_filter($controlCoverage, fn($c) => $c['coverage_count'] > 1)),
        ];
    }

    /**
     * Get framework overlap analysis
     * Data for Venn diagram visualization
     *
     * @return array Overlap data between frameworks
     */
    public function getFrameworkOverlap(): array
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $overlaps = [];

        // Calculate pairwise overlaps
        for ($i = 0; $i < count($frameworks); $i++) {
            for ($j = $i + 1; $j < count($frameworks); $j++) {
                $fw1 = $frameworks[$i];
                $fw2 = $frameworks[$j];

                $overlap = $this->calculateFrameworkOverlap($fw1, $fw2);
                if ($overlap['shared_controls'] > 0) {
                    $overlaps[] = [
                        'framework1' => $fw1->getCode(),
                        'framework2' => $fw2->getCode(),
                        'framework1_name' => $fw1->getName(),
                        'framework2_name' => $fw2->getName(),
                        'shared_controls' => $overlap['shared_controls'],
                        'overlap_percentage' => $overlap['overlap_percentage'],
                        'controls' => $overlap['controls'],
                    ];
                }
            }
        }

        // Sort by overlap percentage descending
        usort($overlaps, fn($a, $b) => $b['overlap_percentage'] <=> $a['overlap_percentage']);

        return [
            'overlaps' => $overlaps,
            'frameworks' => array_map(fn($f) => [
                'code' => $f->getCode(),
                'name' => $f->getName(),
                'requirement_count' => count($this->requirementRepository->findByFramework($f)),
            ], $frameworks),
        ];
    }

    /**
     * Calculate overlap between two frameworks
     */
    private function calculateFrameworkOverlap(ComplianceFramework $fw1, ComplianceFramework $fw2): array
    {
        $controls1 = $this->getFrameworkControls($fw1);
        $controls2 = $this->getFrameworkControls($fw2);

        $sharedControls = array_intersect($controls1, $controls2);
        $unionControls = array_unique(array_merge($controls1, $controls2));

        $overlapPercentage = count($unionControls) > 0
            ? round((count($sharedControls) / count($unionControls)) * 100, 1)
            : 0;

        return [
            'shared_controls' => count($sharedControls),
            'overlap_percentage' => $overlapPercentage,
            'controls' => array_values($sharedControls),
        ];
    }

    /**
     * Get all controls mapped to a framework
     */
    private function getFrameworkControls(ComplianceFramework $framework): array
    {
        $requirements = $this->requirementRepository->findByFramework($framework);
        $controls = [];

        foreach ($requirements as $requirement) {
            foreach ($requirement->getMappedControls() as $control) {
                $controls[] = $control->getControlId();
            }
        }

        return array_unique($controls);
    }

    /**
     * Get gap analysis across all frameworks
     *
     * @return array Gaps grouped by severity and framework
     */
    public function getGapAnalysis(): array
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $allGaps = [];

        foreach ($frameworks as $framework) {
            $gaps = $this->requirementRepository->findGapsByFramework($framework);

            foreach ($gaps as $gap) {
                // Use the direct fulfillment percentage from the requirement
                $percentage = $gap->getFulfillmentPercentage();

                $allGaps[] = [
                    'framework' => $framework->getCode(),
                    'framework_name' => $framework->getName(),
                    'requirement_id' => $gap->getRequirementId(),
                    'title' => $gap->getTitle(),
                    'category' => $gap->getCategory(),
                    'priority' => $gap->getPriority(),
                    'fulfillment' => $percentage,
                    'gap_size' => 100 - $percentage,
                    'has_mapped_controls' => !$gap->getMappedControls()->isEmpty(),
                    'control_count' => count($gap->getMappedControls()),
                ];
            }
        }

        // Group by priority
        $byPriority = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];

        foreach ($allGaps as $gap) {
            $priority = $gap['priority'] ?? 'medium';
            if (isset($byPriority[$priority])) {
                $byPriority[$priority][] = $gap;
            }
        }

        // Sort each priority group by gap size descending
        foreach ($byPriority as &$gaps) {
            usort($gaps, fn($a, $b) => $b['gap_size'] <=> $a['gap_size']);
        }

        return [
            'by_priority' => $byPriority,
            'summary' => [
                'total_gaps' => count($allGaps),
                'critical' => count($byPriority['critical']),
                'high' => count($byPriority['high']),
                'medium' => count($byPriority['medium']),
                'low' => count($byPriority['low']),
            ],
            'by_framework' => $this->groupGapsByFramework($allGaps),
        ];
    }

    /**
     * Group gaps by framework
     */
    private function groupGapsByFramework(array $gaps): array
    {
        $byFramework = [];

        foreach ($gaps as $gap) {
            $code = $gap['framework'];
            if (!isset($byFramework[$code])) {
                $byFramework[$code] = [
                    'framework' => $gap['framework_name'],
                    'code' => $code,
                    'gaps' => [],
                    'total' => 0,
                ];
            }
            $byFramework[$code]['gaps'][] = $gap;
            $byFramework[$code]['total']++;
        }

        return array_values($byFramework);
    }

    /**
     * Calculate transitive compliance
     * If a control satisfies ISO 27001 and is mapped to DORA,
     * implementing the control provides compliance for both
     *
     * @return array Transitive compliance data
     */
    public function getTransitiveCompliance(): array
    {
        $controls = $this->controlRepository->findBy(['implementationStatus' => 'implemented']);
        $transitiveData = [];

        foreach ($controls as $control) {
            $requirements = $this->requirementRepository->findByControl($control->getId());
            $satisfiedRequirements = [];

            foreach ($requirements as $requirement) {
                $framework = $requirement->getFramework();
                $satisfiedRequirements[] = [
                    'framework' => $framework ? $framework->getCode() : 'Unknown',
                    'requirement_id' => $requirement->getRequirementId(),
                    'title' => $requirement->getTitle(),
                ];
            }

            if (count($satisfiedRequirements) > 1) {
                $transitiveData[] = [
                    'control_id' => $control->getControlId(),
                    'control_name' => $control->getName(),
                    'implementation_status' => $control->getImplementationStatus(),
                    'frameworks_satisfied' => array_unique(array_column($satisfiedRequirements, 'framework')),
                    'requirements_satisfied' => $satisfiedRequirements,
                    'transitive_value' => count($satisfiedRequirements),
                ];
            }
        }

        // Sort by transitive value descending
        usort($transitiveData, fn($a, $b) => $b['transitive_value'] <=> $a['transitive_value']);

        // Calculate total transitive compliance value
        $totalTransitiveRequirements = 0;
        foreach ($transitiveData as $item) {
            $totalTransitiveRequirements += $item['transitive_value'];
        }

        return [
            'controls' => $transitiveData,
            'summary' => [
                'multi_framework_controls' => count($transitiveData),
                'total_requirements_satisfied' => $totalTransitiveRequirements,
                'efficiency_score' => count($transitiveData) > 0
                    ? round($totalTransitiveRequirements / count($transitiveData), 1)
                    : 0,
            ],
        ];
    }

    /**
     * Get compliance roadmap data
     * Timeline of compliance targets and current status
     *
     * @return array Roadmap data for timeline visualization
     */
    public function getComplianceRoadmap(): array
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $tenant = $this->tenantContext->getCurrentTenant();
        $roadmap = [];

        foreach ($frameworks as $framework) {
            // Skip framework statistics if no tenant context
            if ($tenant === null) {
                $stats = ['total' => 0, 'applicable' => 0, 'fulfilled' => 0];
            } else {
                $stats = $this->requirementRepository->getFrameworkStatisticsForTenant($framework, $tenant);
            }
            $compliance = $stats['applicable'] > 0
                ? round(($stats['fulfilled'] / $stats['applicable']) * 100, 1)
                : 0;

            // Estimate time to completion based on current progress
            $remainingGaps = $stats['applicable'] - $stats['fulfilled'];
            $estimatedWeeks = $this->estimateTimeToCompletion($remainingGaps, $compliance);

            $roadmap[] = [
                'framework' => $framework->getName(),
                'code' => $framework->getCode(),
                'mandatory' => $framework->isMandatory(),
                'current_compliance' => $compliance,
                'target_compliance' => 100,
                'gaps_remaining' => $remainingGaps,
                'estimated_weeks' => $estimatedWeeks,
                'status' => $this->determineRoadmapStatus($compliance, $framework->isMandatory()),
                'priority' => $framework->isMandatory() ? 'high' : 'medium',
            ];
        }

        // Sort by priority (mandatory first) then by compliance
        usort($roadmap, function ($a, $b) {
            if ($a['mandatory'] !== $b['mandatory']) {
                return $b['mandatory'] <=> $a['mandatory'];
            }
            return $a['current_compliance'] <=> $b['current_compliance'];
        });

        return [
            'roadmap' => $roadmap,
            'summary' => [
                'frameworks_compliant' => count(array_filter($roadmap, fn($r) => $r['current_compliance'] >= 100)),
                'frameworks_in_progress' => count(array_filter($roadmap, fn($r) => $r['current_compliance'] > 0 && $r['current_compliance'] < 100)),
                'frameworks_not_started' => count(array_filter($roadmap, fn($r) => $r['current_compliance'] === 0.0)),
                'average_compliance' => count($roadmap) > 0
                    ? round(array_sum(array_column($roadmap, 'current_compliance')) / count($roadmap), 1)
                    : 0,
            ],
        ];
    }

    /**
     * Estimate weeks to completion
     */
    private function estimateTimeToCompletion(int $remainingGaps, float $currentCompliance): int
    {
        if ($remainingGaps === 0) {
            return 0;
        }

        // Assume 2-5 requirements per week based on complexity
        $requirementsPerWeek = match (true) {
            $remainingGaps <= 10 => 5,
            $remainingGaps <= 50 => 3,
            default => 2,
        };

        return (int) ceil($remainingGaps / $requirementsPerWeek);
    }

    /**
     * Determine roadmap status
     */
    private function determineRoadmapStatus(float $compliance, bool $mandatory): string
    {
        if ($compliance >= 100) {
            return 'completed';
        }
        if ($compliance >= 80) {
            return 'on_track';
        }
        if ($compliance >= 50) {
            return $mandatory ? 'at_risk' : 'in_progress';
        }
        return $mandatory ? 'critical' : 'planning';
    }

    /**
     * Calculate compliance summary statistics
     */
    private function calculateComplianceSummary(array $comparison): array
    {
        if (count($comparison) === 0) {
            return [
                'average_compliance' => 0,
                'highest_compliance' => null,
                'lowest_compliance' => null,
                'mandatory_compliance' => 0,
            ];
        }

        $mandatoryFrameworks = array_filter($comparison, fn($f) => $f['mandatory']);
        $mandatoryCompliance = count($mandatoryFrameworks) > 0
            ? round(array_sum(array_column($mandatoryFrameworks, 'compliance_percentage')) / count($mandatoryFrameworks), 1)
            : 0;

        return [
            'average_compliance' => round(array_sum(array_column($comparison, 'compliance_percentage')) / count($comparison), 1),
            'highest_compliance' => $comparison[0] ?? null,
            'lowest_compliance' => end($comparison) ?: null,
            'mandatory_compliance' => $mandatoryCompliance,
            'total_frameworks' => count($comparison),
            'total_requirements' => array_sum(array_column($comparison, 'total')),
            'total_fulfilled' => array_sum(array_column($comparison, 'fulfilled')),
        ];
    }

    /**
     * Get executive compliance summary
     * High-level overview for management dashboards
     *
     * @return array Executive summary data
     */
    public function getExecutiveSummary(): array
    {
        $comparison = $this->getFrameworkComparison();
        $gapAnalysis = $this->getGapAnalysis();
        $transitiveCompliance = $this->getTransitiveCompliance();

        return [
            'overall_compliance' => $comparison['summary']['average_compliance'],
            'mandatory_compliance' => $comparison['summary']['mandatory_compliance'],
            'frameworks' => [
                'total' => $comparison['summary']['total_frameworks'],
                'compliant' => count(array_filter($comparison['frameworks'], fn($f) => $f['compliance_percentage'] >= 100)),
                'at_risk' => count(array_filter($comparison['frameworks'], fn($f) => $f['mandatory'] && $f['compliance_percentage'] < 80)),
            ],
            'gaps' => [
                'total' => $gapAnalysis['summary']['total_gaps'],
                'critical' => $gapAnalysis['summary']['critical'],
                'high' => $gapAnalysis['summary']['high'],
            ],
            'efficiency' => [
                'multi_framework_controls' => $transitiveCompliance['summary']['multi_framework_controls'],
                'transitive_satisfaction' => $transitiveCompliance['summary']['total_requirements_satisfied'],
            ],
            'trend' => $this->calculateComplianceTrend(),
        ];
    }

    /**
     * Calculate compliance trend (mock - would need historical data in production)
     */
    private function calculateComplianceTrend(): array
    {
        // In production, this would query historical compliance snapshots
        // For now, return a placeholder trend
        return [
            'direction' => 'up',
            'change' => 2.5,
            'period' => 'last_month',
        ];
    }
}
