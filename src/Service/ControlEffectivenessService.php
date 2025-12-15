<?php

namespace App\Service;

use App\Entity\Control;
use App\Repository\ControlRepository;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\ComplianceRequirementRepository;
use DateTimeInterface;

/**
 * Control Effectiveness Service
 *
 * Phase 7B: Analyzes control performance and effectiveness metrics.
 * Provides data for control dashboards and performance analysis.
 */
class ControlEffectivenessService
{
    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Get control effectiveness dashboard data
     *
     * @return array Dashboard metrics and visualizations
     */
    public function getEffectivenessDashboard(): array
    {
        $controls = $this->controlRepository->findAll();

        $metrics = $this->calculateEffectivenessMetrics($controls);
        $performance = $this->getControlPerformance($controls);
        $orphans = $this->findOrphanedControls($controls);
        $aging = $this->getControlAgingAnalysis($controls);

        return [
            'metrics' => $metrics,
            'performance' => $performance,
            'orphaned_controls' => $orphans,
            'aging_analysis' => $aging,
            'implementation_status' => $this->getImplementationStatusDistribution($controls),
        ];
    }

    /**
     * Calculate overall effectiveness metrics
     */
    private function calculateEffectivenessMetrics(array $controls): array
    {
        $totalControls = count($controls);
        $implemented = 0;
        $withRisks = 0;
        $withIncidents = 0;
        $withRequirements = 0;
        $totalEffectivenessScore = 0;

        foreach ($controls as $control) {
            if ($control->getImplementationStatus() === 'implemented') {
                $implemented++;
            }

            $risks = $control->getRisks();
            if ($risks && count($risks) > 0) {
                $withRisks++;
            }

            $requirements = $this->requirementRepository->findByControl($control->getId());
            if (count($requirements) > 0) {
                $withRequirements++;
            }

            $totalEffectivenessScore += $this->calculateControlEffectiveness($control);
        }

        return [
            'total_controls' => $totalControls,
            'implemented' => $implemented,
            'implementation_rate' => $totalControls > 0
                ? round(($implemented / $totalControls) * 100, 1)
                : 0,
            'with_risks' => $withRisks,
            'risk_coverage' => $totalControls > 0
                ? round(($withRisks / $totalControls) * 100, 1)
                : 0,
            'with_requirements' => $withRequirements,
            'requirement_coverage' => $totalControls > 0
                ? round(($withRequirements / $totalControls) * 100, 1)
                : 0,
            'average_effectiveness' => $totalControls > 0
                ? round($totalEffectivenessScore / $totalControls, 1)
                : 0,
        ];
    }

    /**
     * Calculate effectiveness score for a single control (0-100)
     */
    public function calculateControlEffectiveness(Control $control): float
    {
        $score = 0;
        $factors = 0;

        // Factor 1: Implementation status (0-30 points)
        $implementationScore = match ($control->getImplementationStatus()) {
            'implemented' => 30,
            'partially_implemented' => 20,
            'planned' => 10,
            default => 0,
        };
        $score += $implementationScore;
        $factors++;

        // Factor 2: Implementation percentage (0-20 points)
        $percentage = $control->getImplementationPercentage() ?? 0;
        $score += ($percentage / 100) * 20;
        $factors++;

        // Factor 3: Risk reduction (0-25 points)
        $linkedRisks = $control->getRisks();
        if ($linkedRisks && count($linkedRisks) > 0) {
            $riskReductionScore = min(25, count($linkedRisks) * 5);
            $score += $riskReductionScore;
        }
        $factors++;

        // Factor 4: Framework coverage (0-15 points)
        $requirements = $this->requirementRepository->findByControl($control->getId());
        if (count($requirements) > 0) {
            $coverageScore = min(15, count($requirements) * 3);
            $score += $coverageScore;
        }
        $factors++;

        // Factor 5: Review freshness (0-10 points)
        $lastReview = $control->getLastReviewDate();
        if ($lastReview) {
            $daysSinceReview = (new \DateTime())->diff($lastReview)->days;
            $freshnessScore = match (true) {
                $daysSinceReview <= 90 => 10,
                $daysSinceReview <= 180 => 7,
                $daysSinceReview <= 365 => 4,
                default => 1,
            };
            $score += $freshnessScore;
        }
        $factors++;

        return min(100, $score);
    }

    /**
     * Get control performance ranking
     *
     * @return array Top and bottom performing controls
     */
    public function getControlPerformance(array $controls): array
    {
        $performance = [];

        foreach ($controls as $control) {
            $effectiveness = $this->calculateControlEffectiveness($control);
            $linkedRisks = $control->getRisks();

            $performance[] = [
                'control_id' => $control->getControlId(),
                'name' => $control->getName(),
                'category' => $control->getCategory(),
                'implementation_status' => $control->getImplementationStatus(),
                'implementation_percentage' => $control->getImplementationPercentage() ?? 0,
                'effectiveness_score' => $effectiveness,
                'linked_risks' => $linkedRisks ? count($linkedRisks) : 0,
                'last_review' => $control->getLastReviewDate()?->format('Y-m-d'),
                'days_since_review' => $control->getLastReviewDate()
                    ? (new \DateTime())->diff($control->getLastReviewDate())->days
                    : null,
            ];
        }

        // Sort by effectiveness score
        usort($performance, fn($a, $b) => $b['effectiveness_score'] <=> $a['effectiveness_score']);

        return [
            'top_performing' => array_slice($performance, 0, 10),
            'bottom_performing' => array_slice(array_reverse($performance), 0, 10),
            'all' => $performance,
        ];
    }

    /**
     * Find orphaned controls (no risks, no requirements)
     */
    private function findOrphanedControls(array $controls): array
    {
        $orphans = [];

        foreach ($controls as $control) {
            $linkedRisks = $control->getRisks();
            $hasRisks = $linkedRisks && count($linkedRisks) > 0;
            $hasRequirements = count($this->requirementRepository->findByControl($control->getId())) > 0;

            if (!$hasRisks && !$hasRequirements) {
                $orphans[] = [
                    'control_id' => $control->getControlId(),
                    'name' => $control->getName(),
                    'category' => $control->getCategory(),
                    'implementation_status' => $control->getImplementationStatus(),
                    'reason' => 'No linked risks or compliance requirements',
                    'recommendation' => 'Consider linking to relevant risks or mapping to compliance requirements',
                ];
            }
        }

        return [
            'count' => count($orphans),
            'controls' => $orphans,
        ];
    }

    /**
     * Get control aging analysis (time since last review)
     */
    private function getControlAgingAnalysis(array $controls): array
    {
        $aging = [
            'current' => 0,      // < 90 days
            'due_soon' => 0,    // 90-180 days
            'overdue' => 0,     // 180-365 days
            'critical' => 0,    // > 365 days
            'never_reviewed' => 0,
        ];

        $overdueControls = [];
        $now = new \DateTime();

        foreach ($controls as $control) {
            $lastReview = $control->getLastReviewDate();

            if (!$lastReview) {
                $aging['never_reviewed']++;
                $overdueControls[] = [
                    'control_id' => $control->getControlId(),
                    'name' => $control->getName(),
                    'days_overdue' => null,
                    'status' => 'never_reviewed',
                ];
                continue;
            }

            $daysSince = $now->diff($lastReview)->days;

            match (true) {
                $daysSince <= 90 => $aging['current']++,
                $daysSince <= 180 => $aging['due_soon']++,
                $daysSince <= 365 => (function () use (&$aging, &$overdueControls, $control, $daysSince) {
                    $aging['overdue']++;
                    $overdueControls[] = [
                        'control_id' => $control->getControlId(),
                        'name' => $control->getName(),
                        'days_overdue' => $daysSince - 180,
                        'status' => 'overdue',
                    ];
                })(),
                default => (function () use (&$aging, &$overdueControls, $control, $daysSince) {
                    $aging['critical']++;
                    $overdueControls[] = [
                        'control_id' => $control->getControlId(),
                        'name' => $control->getName(),
                        'days_overdue' => $daysSince - 365,
                        'status' => 'critical',
                    ];
                })(),
            };
        }

        // Sort overdue controls by days overdue
        usort($overdueControls, fn($a, $b) => ($b['days_overdue'] ?? 9999) <=> ($a['days_overdue'] ?? 9999));

        return [
            'distribution' => $aging,
            'overdue_controls' => array_slice($overdueControls, 0, 20),
            'review_compliance_rate' => count($controls) > 0
                ? round((($aging['current'] + $aging['due_soon']) / count($controls)) * 100, 1)
                : 0,
        ];
    }

    /**
     * Get implementation status distribution
     */
    private function getImplementationStatusDistribution(array $controls): array
    {
        $distribution = [
            'implemented' => 0,
            'partially_implemented' => 0,
            'planned' => 0,
            'not_applicable' => 0,
            'not_implemented' => 0,
        ];

        foreach ($controls as $control) {
            $status = $control->getImplementationStatus() ?? 'not_implemented';
            if (isset($distribution[$status])) {
                $distribution[$status]++;
            } else {
                $distribution['not_implemented']++;
            }
        }

        return $distribution;
    }

    /**
     * Get control-to-risk effectiveness matrix
     * Shows how controls reduce risks
     *
     * @return array Matrix data for visualization
     */
    public function getControlRiskMatrix(): array
    {
        $controls = $this->controlRepository->findBy(['implementationStatus' => 'implemented']);
        $matrix = [];

        foreach ($controls as $control) {
            $linkedRisks = $control->getRisks();
            if (!$linkedRisks || count($linkedRisks) === 0) {
                continue;
            }

            $riskReduction = [];
            foreach ($linkedRisks as $risk) {
                $inherent = $risk->getInherentRiskLevel();
                $residual = $risk->getResidualRiskLevel() ?? $inherent;
                $reduction = $inherent - $residual;

                $riskReduction[] = [
                    'risk_id' => $risk->getId(),
                    'risk_title' => $risk->getTitle(),
                    'inherent_score' => $inherent,
                    'residual_score' => $residual,
                    'reduction' => $reduction,
                    'reduction_percentage' => $inherent > 0
                        ? round(($reduction / $inherent) * 100, 1)
                        : 0,
                ];
            }

            $totalReduction = array_sum(array_column($riskReduction, 'reduction'));
            $avgReductionPct = count($riskReduction) > 0
                ? round(array_sum(array_column($riskReduction, 'reduction_percentage')) / count($riskReduction), 1)
                : 0;

            $matrix[] = [
                'control_id' => $control->getControlId(),
                'control_name' => $control->getName(),
                'risks_mitigated' => count($riskReduction),
                'total_reduction' => $totalReduction,
                'average_reduction_percentage' => $avgReductionPct,
                'effectiveness_score' => $this->calculateControlEffectiveness($control),
                'risks' => $riskReduction,
            ];
        }

        // Sort by total reduction descending
        usort($matrix, fn($a, $b) => $b['total_reduction'] <=> $a['total_reduction']);

        return [
            'matrix' => $matrix,
            'summary' => [
                'controls_with_risks' => count($matrix),
                'total_risk_reduction' => array_sum(array_column($matrix, 'total_reduction')),
                'average_reduction' => count($matrix) > 0
                    ? round(array_sum(array_column($matrix, 'average_reduction_percentage')) / count($matrix), 1)
                    : 0,
            ],
        ];
    }

    /**
     * Get control category performance
     *
     * @return array Performance by ISO 27001 Annex category
     */
    public function getCategoryPerformance(): array
    {
        $controls = $this->controlRepository->findAll();
        $categories = [];

        foreach ($controls as $control) {
            $category = $control->getCategory() ?? 'Unknown';

            if (!isset($categories[$category])) {
                $categories[$category] = [
                    'category' => $category,
                    'total' => 0,
                    'implemented' => 0,
                    'effectiveness_sum' => 0,
                    'controls' => [],
                ];
            }

            $categories[$category]['total']++;
            if ($control->getImplementationStatus() === 'implemented') {
                $categories[$category]['implemented']++;
            }
            $categories[$category]['effectiveness_sum'] += $this->calculateControlEffectiveness($control);
            $categories[$category]['controls'][] = $control->getControlId();
        }

        // Calculate averages
        $result = [];
        foreach ($categories as $category => $data) {
            $result[] = [
                'category' => $category,
                'total_controls' => $data['total'],
                'implemented' => $data['implemented'],
                'implementation_rate' => $data['total'] > 0
                    ? round(($data['implemented'] / $data['total']) * 100, 1)
                    : 0,
                'average_effectiveness' => $data['total'] > 0
                    ? round($data['effectiveness_sum'] / $data['total'], 1)
                    : 0,
            ];
        }

        // Sort by category name (A.5, A.6, etc.)
        usort($result, fn($a, $b) => strcmp($a['category'], $b['category']));

        return $result;
    }

    /**
     * Get control implementation progress over time (mock historical data)
     *
     * @param int $months Number of months to analyze
     * @return array Trend data for line chart
     */
    public function getImplementationTrend(int $months = 12): array
    {
        // In production, this would query historical snapshots
        // For now, we generate trend based on current data
        $controls = $this->controlRepository->findAll();
        $implemented = count(array_filter($controls, fn($c) => $c->getImplementationStatus() === 'implemented'));
        $total = count($controls);

        $trend = [];
        $now = new \DateTime();

        // Generate simulated historical data
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = (clone $now)->modify("-{$i} months");

            // Simulate growth (rough approximation)
            $factor = 1 - ($i * 0.03);
            $monthlyImplemented = (int) round($implemented * $factor);

            $trend[] = [
                'month' => $date->format('M Y'),
                'implemented' => max(0, $monthlyImplemented),
                'total' => $total,
                'rate' => $total > 0 ? round(($monthlyImplemented / $total) * 100, 1) : 0,
            ];
        }

        return $trend;
    }
}
