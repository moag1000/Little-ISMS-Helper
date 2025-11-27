<?php

namespace App\Service;

use App\Entity\Risk;
use App\Entity\RiskAppetite;
use App\Repository\RiskRepository;
use App\Repository\RiskAppetiteRepository;
use Psr\Log\LoggerInterface;

/**
 * RiskAppetitePrioritizationService
 *
 * Phase 6F-D: Data Reuse Integration
 * Auto-prioritizes risks based on organizational risk appetite
 *
 * Data Reuse Benefit:
 * - Automatic risk prioritization based on appetite thresholds
 * - Dashboard: "5 risks exceed appetite" alerts
 * - Executive reporting with appetite vs. actual risk visualization
 *
 * ISO 27005:2022 Compliance:
 * - Risk appetite must be formally defined and approved
 * - Risks exceeding appetite require escalation
 */
class RiskAppetitePrioritizationService
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly RiskAppetiteRepository $riskAppetiteRepository,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Get applicable risk appetite for a given risk
     *
     * Priority:
     * 1. Category-specific appetite (if defined)
     * 2. Global appetite
     * 3. null if no appetite defined
     */
    public function getApplicableAppetite(Risk $risk): ?RiskAppetite
    {
        $tenant = $risk->getTenant();

        // Try to find category-specific appetite
        // Note: We need to map risk to a category first
        $category = $this->determineRiskCategory($risk);

        if ($category) {
            $categoryAppetite = $this->riskAppetiteRepository->findOneBy([
                'tenant' => $tenant,
                'category' => $category,
                'isActive' => true
            ]);

            if ($categoryAppetite instanceof RiskAppetite) {
                return $categoryAppetite;
            }
        }

        // Fall back to global appetite
        $globalAppetite = $this->riskAppetiteRepository->findOneBy([
            'tenant' => $tenant,
            'category' => null,
            'isActive' => true
        ]);

        return $globalAppetite;
    }

    /**
     * Check if a risk exceeds organizational risk appetite
     */
    public function exceedsAppetite(Risk $risk): bool
    {
        $appetite = $this->getApplicableAppetite($risk);

        if (!$appetite instanceof RiskAppetite) {
            // No appetite defined = cannot determine
            return false;
        }

        $residualRiskLevel = $risk->getResidualRiskLevel();
        return $residualRiskLevel > $appetite->getMaxAcceptableRisk();
    }

    /**
     * Get priority level for a risk based on appetite
     *
     * Returns: 'critical', 'high', 'medium', 'low', 'acceptable'
     */
    public function getPriorityLevel(Risk $risk): string
    {
        $appetite = $this->getApplicableAppetite($risk);

        if (!$appetite instanceof RiskAppetite) {
            // Fall back to absolute risk level if no appetite defined
            $residualRisk = $risk->getResidualRiskLevel();
            return $this->getAbsolutePriority($residualRisk);
        }

        $residualRisk = $risk->getResidualRiskLevel();
        $maxAcceptable = $appetite->getMaxAcceptableRisk();

        // Calculate how much risk exceeds appetite
        $exceedance = $residualRisk - $maxAcceptable;

        if ($exceedance <= 0) {
            return 'acceptable'; // Within appetite
        } elseif ($exceedance <= 3) {
            return 'medium'; // Slightly above appetite
        } elseif ($exceedance <= 6) {
            return 'high'; // Significantly above appetite
        } else {
            return 'critical'; // Far exceeds appetite
        }
    }

    /**
     * Get detailed appetite analysis for a risk
     *
     * @return array{appetite: RiskAppetite|null, within_appetite: bool, priority: string, exceedance: int, percentage: float, requires_action: bool, recommendation: string}
     */
    public function analyzeRiskAppetite(Risk $risk): array
    {
        $appetite = $this->getApplicableAppetite($risk);
        $residualRisk = $risk->getResidualRiskLevel();

        if (!$appetite instanceof RiskAppetite) {
            return [
                'appetite' => null,
                'within_appetite' => null, // Unknown
                'priority' => $this->getAbsolutePriority($residualRisk),
                'exceedance' => 0,
                'percentage' => 0.0,
                'requires_action' => $residualRisk >= 16,
                'recommendation' => 'No risk appetite defined. Please set organizational risk appetite.'
            ];
        }

        $maxAcceptable = $appetite->getMaxAcceptableRisk();
        $withinAppetite = $residualRisk <= $maxAcceptable;
        $exceedance = max(0, $residualRisk - $maxAcceptable);
        $percentage = $appetite->getAppetitePercentage($residualRisk);
        $priority = $this->getPriorityLevel($risk);

        $recommendation = $this->getRecommendation($withinAppetite, $exceedance);

        return [
            'appetite' => $appetite,
            'within_appetite' => $withinAppetite,
            'priority' => $priority,
            'exceedance' => $exceedance,
            'percentage' => $percentage,
            'requires_action' => !$withinAppetite,
            'recommendation' => $recommendation
        ];
    }

    /**
     * Find all risks exceeding appetite
     *
     * @return array<int, array{risk: Risk, appetite: RiskAppetite, exceedance: int, priority: string}>
     */
    public function findRisksExceedingAppetite(): array
    {
        $allRisks = $this->riskRepository->findAll();
        $exceedingRisks = [];

        foreach ($allRisks as $allRisk) {
            if ($this->exceedsAppetite($allRisk)) {
                $analysis = $this->analyzeRiskAppetite($allRisk);

                $exceedingRisks[] = [
                    'risk' => $allRisk,
                    'appetite' => $analysis['appetite'],
                    'exceedance' => $analysis['exceedance'],
                    'priority' => $analysis['priority'],
                    'percentage' => $analysis['percentage']
                ];
            }
        }

        // Sort by exceedance (highest first)
        usort($exceedingRisks, fn(array $a, array $b): int => $b['exceedance'] <=> $a['exceedance']);

        $this->logger->info('Found risks exceeding appetite', [
            'count' => count($exceedingRisks),
            'total_risks' => count($allRisks)
        ]);

        return $exceedingRisks;
    }

    /**
     * Get dashboard statistics for risk appetite compliance
     *
     * @return array{total_risks: int, within_appetite: int, exceeding_appetite: int, critical: int, high: int, medium: int, acceptable: int, compliance_rate: float}
     */
    public function getDashboardStatistics(): array
    {
        $allRisks = $this->riskRepository->findAll();

        $stats = [
            'total_risks' => count($allRisks),
            'within_appetite' => 0,
            'exceeding_appetite' => 0,
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'acceptable' => 0,
            'no_appetite_defined' => 0
        ];

        foreach ($allRisks as $allRisk) {
            $analysis = $this->analyzeRiskAppetite($allRisk);

            if ($analysis['appetite'] === null) {
                $stats['no_appetite_defined']++;
                continue;
            }

            if ($analysis['within_appetite']) {
                $stats['within_appetite']++;
                $stats['acceptable']++;
            } else {
                $stats['exceeding_appetite']++;

                switch ($analysis['priority']) {
                    case 'critical':
                        $stats['critical']++;
                        break;
                    case 'high':
                        $stats['high']++;
                        break;
                    case 'medium':
                        $stats['medium']++;
                        break;
                }
            }
        }

        $applicableRisks = $stats['total_risks'] - $stats['no_appetite_defined'];
        $stats['compliance_rate'] = $applicableRisks > 0
            ? round(($stats['within_appetite'] / $applicableRisks) * 100, 2)
            : 0.0;

        return $stats;
    }

    /**
     * Get prioritized risk list for dashboard
     *
     * Returns risks sorted by:
     * 1. Exceeds appetite (highest priority)
     * 2. Exceedance amount
     * 3. Residual risk level
     *
     * @param int $limit Maximum number of risks to return
     * @return array<int, array{risk: Risk, analysis: array}>
     */
    public function getPrioritizedRisks(int $limit = 10): array
    {
        $allRisks = $this->riskRepository->findAll();
        $prioritized = [];

        foreach ($allRisks as $allRisk) {
            $analysis = $this->analyzeRiskAppetite($allRisk);

            $prioritized[] = [
                'risk' => $allRisk,
                'analysis' => $analysis,
                // Composite score for sorting
                'score' => $this->calculatePriorityScore($allRisk, $analysis)
            ];
        }

        // Sort by priority score (highest first)
        usort($prioritized, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($prioritized, 0, $limit);
    }

    // Helper Methods
    /**
     * Determine risk category based on risk properties
     *
     * This is a simplified mapping - can be extended with more sophisticated logic
     */
    private function determineRiskCategory(Risk $risk): ?string
    {
        $description = strtolower((string) $risk->getDescription());
        $title = strtolower((string) $risk->getTitle());
        $combined = $description . ' ' . $title;
        if (str_contains($combined, 'financial') || str_contains($combined, 'monetary')) {
            return 'Financial';
        }
        if (str_contains($combined, 'operational') || str_contains($combined, 'process')) {
            return 'Operational';
        }
        if (str_contains($combined, 'compliance') || str_contains($combined, 'regulatory')) {
            return 'Compliance';
        }
        if (str_contains($combined, 'strategic') || str_contains($combined, 'business')) {
            return 'Strategic';
        }

        if (str_contains($combined, 'reputation') || str_contains($combined, 'brand')) {
            return 'Reputational';
        }

        return null; // Use global appetite
    }

    /**
     * Get absolute priority when no appetite is defined
     */
    private function getAbsolutePriority(int $riskLevel): string
    {
        if ($riskLevel >= 20) {
            return 'critical';
        }
        if ($riskLevel >= 12) {
            return 'high';
        }
        if ($riskLevel >= 6) {
            return 'medium';
        }
        else {
            return 'low';
        }
    }

    /**
     * Get recommendation based on appetite analysis
     */
    private function getRecommendation(bool $withinAppetite, int $exceedance): string
    {
        if ($withinAppetite) {
            return 'Risk is within organizational appetite. Continue monitoring.';
        }
        if ($exceedance <= 3) {
            return sprintf(
                'Risk slightly exceeds appetite by %d points. Review treatment options.',
                $exceedance
            );
        }

        if ($exceedance <= 6) {
            return sprintf(
                'Risk significantly exceeds appetite by %d points. Additional controls required.',
                $exceedance
            );
        }
        else {
            return sprintf(
                'Risk far exceeds appetite by %d points. Immediate action and executive escalation required.',
                $exceedance
            );
        }
    }

    /**
     * Calculate composite priority score for sorting
     */
    private function calculatePriorityScore(Risk $risk, array $analysis): int
    {
        $score = 0;

        // Base score from residual risk
        $score += $risk->getResidualRiskLevel() * 10;

        // Bonus for exceeding appetite
        if ($analysis['requires_action']) {
            $score += $analysis['exceedance'] * 20;
        }

        // Bonus for priority level
        $priorityBonus = match($analysis['priority']) {
            'critical' => 100,
            'high' => 60,
            'medium' => 30,
            'low' => 10,
            'acceptable' => 0,
            default => 0
        };

        return $score + $priorityBonus;
    }
}
