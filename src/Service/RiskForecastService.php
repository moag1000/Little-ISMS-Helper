<?php

namespace App\Service;

use App\Entity\Risk;
use App\Entity\Incident;
use App\Entity\Asset;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\AssetRepository;
use DateTimeInterface;

/**
 * Risk Forecast Service
 *
 * Phase 7B: Predictive analytics for risk management.
 * Uses statistical methods to forecast risk trends and identify patterns.
 */
class RiskForecastService
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly AssetRepository $assetRepository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Get risk forecast data for visualization
     *
     * @param int $forecastMonths Number of months to forecast
     * @return array Forecast data
     */
    public function getRiskForecast(int $forecastMonths = 6): array
    {
        $historicalData = $this->getHistoricalRiskData(12);
        $forecast = $this->calculateForecast($historicalData, $forecastMonths);

        return [
            'historical' => $historicalData,
            'forecast' => $forecast,
            'trend' => $this->calculateTrend($historicalData),
            'confidence_interval' => $this->calculateConfidenceInterval($forecast),
        ];
    }

    /**
     * Get historical risk data
     *
     * @param int $months Number of historical months
     * @return array Monthly risk counts
     */
    private function getHistoricalRiskData(int $months): array
    {
        $data = [];
        $now = new \DateTime();
        $risks = $this->riskRepository->findAll();

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = (clone $now)->modify("-{$i} months");
            $monthStart = (clone $date)->modify('first day of this month')->setTime(0, 0, 0);
            $monthEnd = (clone $date)->modify('last day of this month')->setTime(23, 59, 59);

            // Count risks that existed at that point
            $monthRisks = array_filter($risks, fn(Risk $risk) => $risk->getCreatedAt() <= $monthEnd);

            // Count by level
            $low = count(array_filter($monthRisks, fn(Risk $r) => $r->getInherentRiskLevel() < 6));
            $medium = count(array_filter($monthRisks, fn(Risk $r) => $r->getInherentRiskLevel() >= 6 && $r->getInherentRiskLevel() < 12));
            $high = count(array_filter($monthRisks, fn(Risk $r) => $r->getInherentRiskLevel() >= 12 && $r->getInherentRiskLevel() < 20));
            $critical = count(array_filter($monthRisks, fn(Risk $r) => $r->getInherentRiskLevel() >= 20));

            // Count open vs closed
            $open = count(array_filter($monthRisks, fn(Risk $r) => $r->getStatus() !== 'closed'));
            $closed = count(array_filter($monthRisks, fn(Risk $r) => $r->getStatus() === 'closed'));

            $data[] = [
                'month' => $date->format('Y-m'),
                'label' => $date->format('M Y'),
                'total' => count($monthRisks),
                'low' => $low,
                'medium' => $medium,
                'high' => $high,
                'critical' => $critical,
                'open' => $open,
                'closed' => $closed,
                'high_critical' => $high + $critical,
            ];
        }

        return $data;
    }

    /**
     * Calculate forecast using simple linear regression
     *
     * @param array $historicalData Historical data points
     * @param int $forecastMonths Months to forecast
     * @return array Forecasted data points
     */
    private function calculateForecast(array $historicalData, int $forecastMonths): array
    {
        if (count($historicalData) < 3) {
            return [];
        }

        // Extract total values for regression
        $totals = array_column($historicalData, 'total');
        $highCritical = array_column($historicalData, 'high_critical');

        // Calculate linear regression for totals
        $totalRegression = $this->linearRegression($totals);
        $highCriticalRegression = $this->linearRegression($highCritical);

        // Generate forecast
        $forecast = [];
        $now = new \DateTime();
        $n = count($historicalData);

        for ($i = 1; $i <= $forecastMonths; $i++) {
            $date = (clone $now)->modify("+{$i} months");
            $x = $n + $i - 1;

            // Forecast values (ensure non-negative)
            $forecastedTotal = max(0, round($totalRegression['slope'] * $x + $totalRegression['intercept']));
            $forecastedHighCritical = max(0, round($highCriticalRegression['slope'] * $x + $highCriticalRegression['intercept']));

            // Distribute forecasted total across levels (based on historical distribution)
            $distribution = $this->calculateDistribution($historicalData);

            $forecast[] = [
                'month' => $date->format('Y-m'),
                'label' => $date->format('M Y'),
                'total' => $forecastedTotal,
                'high_critical' => $forecastedHighCritical,
                'low' => (int) round($forecastedTotal * $distribution['low']),
                'medium' => (int) round($forecastedTotal * $distribution['medium']),
                'high' => (int) round($forecastedTotal * $distribution['high']),
                'critical' => (int) round($forecastedTotal * $distribution['critical']),
                'is_forecast' => true,
            ];
        }

        return $forecast;
    }

    /**
     * Simple linear regression
     */
    private function linearRegression(array $values): array
    {
        $n = count($values);
        if ($n === 0) {
            return ['slope' => 0, 'intercept' => 0];
        }

        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumX += $i;
            $sumY += $values[$i];
            $sumXY += $i * $values[$i];
            $sumX2 += $i * $i;
        }

        $denominator = ($n * $sumX2) - ($sumX * $sumX);
        if ($denominator == 0) {
            return ['slope' => 0, 'intercept' => $sumY / $n];
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        return [
            'slope' => $slope,
            'intercept' => $intercept,
        ];
    }

    /**
     * Calculate risk level distribution from historical data
     */
    private function calculateDistribution(array $historicalData): array
    {
        $totalSum = array_sum(array_column($historicalData, 'total'));
        if ($totalSum === 0) {
            return ['low' => 0.25, 'medium' => 0.35, 'high' => 0.30, 'critical' => 0.10];
        }

        return [
            'low' => array_sum(array_column($historicalData, 'low')) / $totalSum,
            'medium' => array_sum(array_column($historicalData, 'medium')) / $totalSum,
            'high' => array_sum(array_column($historicalData, 'high')) / $totalSum,
            'critical' => array_sum(array_column($historicalData, 'critical')) / $totalSum,
        ];
    }

    /**
     * Calculate trend direction and magnitude
     */
    private function calculateTrend(array $historicalData): array
    {
        if (count($historicalData) < 2) {
            return ['direction' => 'stable', 'change' => 0, 'change_percentage' => 0];
        }

        $totals = array_column($historicalData, 'total');
        $regression = $this->linearRegression($totals);

        // Calculate percentage change over the period
        $firstValue = $totals[0] ?: 1;
        $lastValue = end($totals) ?: 1;
        $changePercentage = round((($lastValue - $firstValue) / $firstValue) * 100, 1);

        // Determine direction
        $direction = match (true) {
            $regression['slope'] > 0.5 => 'increasing',
            $regression['slope'] < -0.5 => 'decreasing',
            default => 'stable',
        };

        return [
            'direction' => $direction,
            'slope' => round($regression['slope'], 3),
            'change' => $lastValue - $firstValue,
            'change_percentage' => $changePercentage,
        ];
    }

    /**
     * Calculate confidence interval for forecast
     */
    private function calculateConfidenceInterval(array $forecast): array
    {
        if (empty($forecast)) {
            return ['lower' => [], 'upper' => []];
        }

        $lower = [];
        $upper = [];

        // Increase uncertainty as we go further into the future
        foreach ($forecast as $i => $point) {
            $uncertainty = 0.1 + ($i * 0.05); // 10% base + 5% per month
            $lower[] = [
                'month' => $point['month'],
                'total' => max(0, (int) round($point['total'] * (1 - $uncertainty))),
            ];
            $upper[] = [
                'month' => $point['month'],
                'total' => (int) round($point['total'] * (1 + $uncertainty)),
            ];
        }

        return [
            'lower' => $lower,
            'upper' => $upper,
        ];
    }

    /**
     * Get risk velocity metrics
     * Measures speed of risk creation vs closure
     *
     * @return array Velocity metrics
     */
    public function getRiskVelocity(): array
    {
        $risks = $this->riskRepository->findAll();
        $now = new \DateTime();

        // Last 30 days
        $thirtyDaysAgo = (clone $now)->modify('-30 days');

        $newRisks = count(array_filter($risks, fn(Risk $r) => $r->getCreatedAt() >= $thirtyDaysAgo));
        $closedRisks = count(array_filter($risks, fn(Risk $r) =>
            $r->getStatus() === 'closed' &&
            $r->getUpdatedAt() &&
            $r->getUpdatedAt() >= $thirtyDaysAgo
        ));

        $velocity = $newRisks - $closedRisks;

        // Last 90 days for trend
        $ninetyDaysAgo = (clone $now)->modify('-90 days');
        $newRisks90 = count(array_filter($risks, fn(Risk $r) => $r->getCreatedAt() >= $ninetyDaysAgo));
        $closedRisks90 = count(array_filter($risks, fn(Risk $r) =>
            $r->getStatus() === 'closed' &&
            $r->getUpdatedAt() &&
            $r->getUpdatedAt() >= $ninetyDaysAgo
        ));

        return [
            'last_30_days' => [
                'new_risks' => $newRisks,
                'closed_risks' => $closedRisks,
                'net_change' => $velocity,
                'velocity' => $velocity,
            ],
            'last_90_days' => [
                'new_risks' => $newRisks90,
                'closed_risks' => $closedRisks90,
                'net_change' => $newRisks90 - $closedRisks90,
                'monthly_average' => round(($newRisks90 - $closedRisks90) / 3, 1),
            ],
            'trend' => $velocity > 0 ? 'increasing' : ($velocity < 0 ? 'decreasing' : 'stable'),
            'status' => match (true) {
                $velocity > 5 => 'warning',
                $velocity > 0 => 'caution',
                $velocity < -5 => 'excellent',
                $velocity < 0 => 'good',
                default => 'stable',
            },
        ];
    }

    /**
     * Get incident probability by asset
     * Estimates likelihood of incidents based on asset characteristics
     *
     * @return array Asset risk profiles
     */
    public function getAssetIncidentProbability(): array
    {
        $assets = $this->assetRepository->findAll();
        $incidents = $this->incidentRepository->findAll();

        // Build incident history by asset
        $incidentsByAsset = [];
        foreach ($incidents as $incident) {
            foreach ($incident->getAffectedAssets() as $asset) {
                $assetId = $asset->getId();
                if (!isset($incidentsByAsset[$assetId])) {
                    $incidentsByAsset[$assetId] = [];
                }
                $incidentsByAsset[$assetId][] = $incident;
            }
        }

        $profiles = [];
        foreach ($assets as $asset) {
            $assetIncidents = $incidentsByAsset[$asset->getId()] ?? [];
            $incidentCount = count($assetIncidents);

            // Calculate criticality score (1-15)
            $criticality = (
                ($asset->getConfidentialityValue() ?? 1) +
                ($asset->getIntegrityValue() ?? 1) +
                ($asset->getAvailabilityValue() ?? 1)
            );

            // Calculate incident probability score (0-100)
            $probability = $this->calculateIncidentProbability($asset, $assetIncidents);

            $profiles[] = [
                'asset_id' => $asset->getId(),
                'asset_name' => $asset->getName(),
                'asset_type' => $asset->getAssetType(),
                'criticality' => $criticality,
                'criticality_level' => match (true) {
                    $criticality >= 12 => 'critical',
                    $criticality >= 9 => 'high',
                    $criticality >= 6 => 'medium',
                    default => 'low',
                },
                'historical_incidents' => $incidentCount,
                'incident_probability' => $probability,
                'risk_score' => round($criticality * ($probability / 100), 1),
            ];
        }

        // Sort by risk score descending
        usort($profiles, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);

        return [
            'profiles' => $profiles,
            'high_risk_assets' => array_slice(array_filter($profiles, fn($p) => $p['risk_score'] >= 10), 0, 10),
            'summary' => [
                'total_assets' => count($profiles),
                'high_risk_count' => count(array_filter($profiles, fn($p) => $p['risk_score'] >= 10)),
                'average_probability' => count($profiles) > 0
                    ? round(array_sum(array_column($profiles, 'incident_probability')) / count($profiles), 1)
                    : 0,
            ],
        ];
    }

    /**
     * Calculate incident probability for an asset
     */
    private function calculateIncidentProbability(Asset $asset, array $incidents): float
    {
        $score = 0;

        // Factor 1: Historical incidents (0-40 points)
        $incidentCount = count($incidents);
        $score += min(40, $incidentCount * 10);

        // Factor 2: Criticality (0-30 points)
        $criticality = (
            ($asset->getConfidentialityValue() ?? 1) +
            ($asset->getIntegrityValue() ?? 1) +
            ($asset->getAvailabilityValue() ?? 1)
        );
        $score += ($criticality / 15) * 30;

        // Factor 3: Asset type risk factor (0-20 points)
        $typeRisk = match ($asset->getAssetType()) {
            'server', 'database', 'network' => 20,
            'application', 'cloud_service' => 15,
            'workstation', 'laptop' => 10,
            'mobile_device' => 12,
            default => 8,
        };
        $score += $typeRisk;

        // Factor 4: Recent incident recency (0-10 points)
        if (!empty($incidents)) {
            $timestamps = array_filter(
                array_map(fn($i) => $i->getOccurredAt()?->getTimestamp(), $incidents),
                fn($ts) => $ts !== null
            );
            if (!empty($timestamps)) {
                $latestIncident = max($timestamps);
                $daysSince = (time() - $latestIncident) / 86400;
                $recencyScore = match (true) {
                    $daysSince <= 30 => 10,
                    $daysSince <= 90 => 7,
                    $daysSince <= 180 => 4,
                    default => 1,
                };
                $score += $recencyScore;
            }
        }

        return min(100, $score);
    }

    /**
     * Get anomaly detection data
     * Identifies unusual patterns in risk and incident data
     *
     * @return array Detected anomalies
     */
    public function getAnomalyDetection(): array
    {
        $anomalies = [];

        // Check for risk spikes
        $riskSpikes = $this->detectRiskSpikes();
        if (!empty($riskSpikes)) {
            $anomalies = array_merge($anomalies, $riskSpikes);
        }

        // Check for incident patterns
        $incidentPatterns = $this->detectIncidentPatterns();
        if (!empty($incidentPatterns)) {
            $anomalies = array_merge($anomalies, $incidentPatterns);
        }

        // Check for control drift
        $controlDrift = $this->detectControlDrift();
        if (!empty($controlDrift)) {
            $anomalies = array_merge($anomalies, $controlDrift);
        }

        // Sort by severity
        usort($anomalies, fn($a, $b) =>
            ($b['severity_score'] ?? 0) <=> ($a['severity_score'] ?? 0)
        );

        return [
            'anomalies' => $anomalies,
            'total_count' => count($anomalies),
            'by_type' => [
                'risk_spikes' => count(array_filter($anomalies, fn($a) => $a['type'] === 'risk_spike')),
                'incident_patterns' => count(array_filter($anomalies, fn($a) => $a['type'] === 'incident_pattern')),
                'control_drift' => count(array_filter($anomalies, fn($a) => $a['type'] === 'control_drift')),
            ],
        ];
    }

    /**
     * Detect unusual risk spikes
     */
    private function detectRiskSpikes(): array
    {
        $risks = $this->riskRepository->findAll();
        $now = new \DateTime();
        $spikes = [];

        // Group risks by creation week
        $weeklyRisks = [];
        foreach ($risks as $risk) {
            $week = $risk->getCreatedAt()->format('Y-W');
            if (!isset($weeklyRisks[$week])) {
                $weeklyRisks[$week] = 0;
            }
            $weeklyRisks[$week]++;
        }

        // Calculate average and standard deviation
        $values = array_values($weeklyRisks);
        if (count($values) < 4) {
            return [];
        }

        $avg = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn($v) => pow($v - $avg, 2), $values)) / count($values);
        $stdDev = sqrt($variance);

        // Find weeks with values > 2 standard deviations above average
        foreach ($weeklyRisks as $week => $count) {
            if ($count > $avg + (2 * $stdDev)) {
                $spikes[] = [
                    'type' => 'risk_spike',
                    'week' => $week,
                    'count' => $count,
                    'average' => round($avg, 1),
                    'deviation' => round(($count - $avg) / $stdDev, 2),
                    'severity' => 'warning',
                    'severity_score' => min(100, ($count / $avg) * 50),
                    'message' => "Unusual spike in risk creation during week {$week}: {$count} risks (avg: " . round($avg, 1) . ")",
                ];
            }
        }

        return $spikes;
    }

    /**
     * Detect incident patterns
     */
    private function detectIncidentPatterns(): array
    {
        $incidents = $this->incidentRepository->findAll();
        $patterns = [];

        // Group by category
        $byCategory = [];
        foreach ($incidents as $incident) {
            $category = $incident->getCategory() ?? 'unknown';
            if (!isset($byCategory[$category])) {
                $byCategory[$category] = [];
            }
            $byCategory[$category][] = $incident;
        }

        // Check for concentrated categories
        $total = count($incidents);
        foreach ($byCategory as $category => $categoryIncidents) {
            $count = count($categoryIncidents);
            $percentage = $total > 0 ? ($count / $total) * 100 : 0;

            if ($percentage > 40 && $count >= 5) {
                $patterns[] = [
                    'type' => 'incident_pattern',
                    'category' => $category,
                    'count' => $count,
                    'percentage' => round($percentage, 1),
                    'severity' => 'info',
                    'severity_score' => $percentage,
                    'message' => "High concentration of '{$category}' incidents: {$count} (" . round($percentage, 1) . "%)",
                ];
            }
        }

        return $patterns;
    }

    /**
     * Detect control drift (controls that haven't been reviewed)
     */
    private function detectControlDrift(): array
    {
        // This would be implemented with ControlRepository
        // For now, return empty as we don't have direct access
        return [];
    }

    /**
     * Get risk appetite compliance
     * Compares actual risk levels against defined appetite
     *
     * @return array Appetite compliance data
     */
    public function getRiskAppetiteCompliance(): array
    {
        $risks = $this->riskRepository->findAll();

        // Define default risk appetite thresholds
        $appetite = [
            'low_max' => 50,      // Maximum acceptable low risks
            'medium_max' => 30,   // Maximum acceptable medium risks
            'high_max' => 10,     // Maximum acceptable high risks
            'critical_max' => 2,  // Maximum acceptable critical risks
        ];

        // Count actual risks by level
        $actual = [
            'low' => count(array_filter($risks, fn(Risk $r) => $r->getInherentRiskLevel() < 6)),
            'medium' => count(array_filter($risks, fn(Risk $r) => $r->getInherentRiskLevel() >= 6 && $r->getInherentRiskLevel() < 12)),
            'high' => count(array_filter($risks, fn(Risk $r) => $r->getInherentRiskLevel() >= 12 && $r->getInherentRiskLevel() < 20)),
            'critical' => count(array_filter($risks, fn(Risk $r) => $r->getInherentRiskLevel() >= 20)),
        ];

        // Check compliance
        $breaches = [];
        foreach ($actual as $level => $count) {
            $max = $appetite[$level . '_max'];
            if ($count > $max) {
                $breaches[] = [
                    'level' => $level,
                    'actual' => $count,
                    'threshold' => $max,
                    'excess' => $count - $max,
                    'severity' => $level === 'critical' || $level === 'high' ? 'high' : 'medium',
                ];
            }
        }

        return [
            'appetite' => $appetite,
            'actual' => $actual,
            'breaches' => $breaches,
            'is_compliant' => empty($breaches),
            'compliance_score' => $this->calculateAppetiteComplianceScore($actual, $appetite),
        ];
    }

    /**
     * Calculate appetite compliance score
     */
    private function calculateAppetiteComplianceScore(array $actual, array $appetite): float
    {
        $score = 100;

        foreach ($actual as $level => $count) {
            $max = $appetite[$level . '_max'] ?? 100;
            if ($count > $max) {
                $excess = $count - $max;
                $penalty = match ($level) {
                    'critical' => $excess * 15,
                    'high' => $excess * 10,
                    'medium' => $excess * 5,
                    default => $excess * 2,
                };
                $score -= $penalty;
            }
        }

        return max(0, $score);
    }
}
