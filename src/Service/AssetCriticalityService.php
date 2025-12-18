<?php

namespace App\Service;

use App\Entity\Asset;
use App\Entity\Incident;
use App\Repository\AssetRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;

/**
 * Asset Criticality Service
 *
 * Phase 7B: Asset risk profiling and criticality analytics.
 * Analyzes assets based on CIA values, incident history, and risk exposure.
 */
class AssetCriticalityService
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly RiskRepository $riskRepository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Get comprehensive asset criticality dashboard
     *
     * @return array Dashboard data with profiles, distributions, and insights
     */
    public function getCriticalityDashboard(): array
    {
        $assets = $this->assetRepository->findAll();
        $incidents = $this->incidentRepository->findAll();

        $incidentsByAsset = $this->buildIncidentIndex($incidents);
        $profiles = $this->buildAssetProfiles($assets, $incidentsByAsset);

        return [
            'profiles' => $profiles,
            'high_risk_assets' => $this->getHighRiskAssets($profiles),
            'distribution' => $this->calculateDistribution($profiles),
            'by_type' => $this->groupByType($profiles),
            'summary' => $this->calculateSummary($profiles),
        ];
    }

    /**
     * Get asset incident probability analysis
     *
     * @return array Incident probability data per asset
     */
    public function getAssetIncidentProbability(): array
    {
        $assets = $this->assetRepository->findAll();
        $incidents = $this->incidentRepository->findAll();

        $incidentsByAsset = $this->buildIncidentIndex($incidents);
        $profiles = [];

        foreach ($assets as $asset) {
            $assetIncidents = $incidentsByAsset[$asset->getId()] ?? [];
            $probability = $this->calculateIncidentProbability($asset, $assetIncidents);

            $criticality = $this->calculateCriticality($asset);

            $profiles[] = [
                'asset_id' => $asset->getId(),
                'asset_name' => $asset->getName(),
                'asset_type' => $asset->getAssetType(),
                'criticality' => $criticality,
                'criticality_level' => $this->getCriticalityLevel($criticality),
                'historical_incidents' => count($assetIncidents),
                'incident_probability' => $probability,
                'risk_score' => round($criticality * ($probability / 100), 1),
            ];
        }

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
     * Get asset vulnerability matrix (CIA vs Risk Count)
     *
     * @return array Matrix data for bubble chart visualization
     */
    public function getVulnerabilityMatrix(): array
    {
        $assets = $this->assetRepository->findAll();
        $risks = $this->riskRepository->findAll();

        // Build risk count per asset
        $risksByAsset = [];
        foreach ($risks as $risk) {
            $asset = $risk->getAsset();
            if ($asset !== null) {
                $assetId = $asset->getId();
                if (!isset($risksByAsset[$assetId])) {
                    $risksByAsset[$assetId] = 0;
                }
                $risksByAsset[$assetId]++;
            }
        }

        $matrix = [];
        foreach ($assets as $asset) {
            $criticality = $this->calculateCriticality($asset);
            $riskCount = $risksByAsset[$asset->getId()] ?? 0;

            $matrix[] = [
                'asset_id' => $asset->getId(),
                'asset_name' => $asset->getName(),
                'asset_type' => $asset->getAssetType(),
                'x' => $criticality,  // CIA value (1-15)
                'y' => $riskCount,    // Number of linked risks
                'r' => max(5, min(30, $riskCount * 3 + 5)), // Bubble size
                'criticality_level' => $this->getCriticalityLevel($criticality),
            ];
        }

        return [
            'matrix' => $matrix,
            'summary' => [
                'total_assets' => count($matrix),
                'assets_with_risks' => count(array_filter($matrix, fn($m) => $m['y'] > 0)),
                'average_criticality' => count($matrix) > 0
                    ? round(array_sum(array_column($matrix, 'x')) / count($matrix), 1)
                    : 0,
                'total_risk_links' => array_sum(array_column($matrix, 'y')),
            ],
        ];
    }

    /**
     * Get asset type analysis
     *
     * @return array Analysis grouped by asset type
     */
    public function getTypeAnalysis(): array
    {
        $assets = $this->assetRepository->findAll();

        $byType = [];
        foreach ($assets as $asset) {
            $type = $asset->getAssetType() ?? 'unknown';
            if (!isset($byType[$type])) {
                $byType[$type] = [
                    'count' => 0,
                    'total_criticality' => 0,
                    'critical_count' => 0,
                    'high_count' => 0,
                ];
            }

            $criticality = $this->calculateCriticality($asset);
            $byType[$type]['count']++;
            $byType[$type]['total_criticality'] += $criticality;

            $level = $this->getCriticalityLevel($criticality);
            if ($level === 'critical') {
                $byType[$type]['critical_count']++;
            } elseif ($level === 'high') {
                $byType[$type]['high_count']++;
            }
        }

        $result = [];
        foreach ($byType as $type => $data) {
            $result[] = [
                'type' => $type,
                'count' => $data['count'],
                'average_criticality' => $data['count'] > 0
                    ? round($data['total_criticality'] / $data['count'], 1)
                    : 0,
                'critical_count' => $data['critical_count'],
                'high_count' => $data['high_count'],
                'high_risk_percentage' => $data['count'] > 0
                    ? round(($data['critical_count'] + $data['high_count']) / $data['count'] * 100, 1)
                    : 0,
            ];
        }

        usort($result, fn($a, $b) => $b['average_criticality'] <=> $a['average_criticality']);

        return $result;
    }

    /**
     * Get supply chain risk analysis (supplier assets)
     *
     * @return array Supplier concentration and risk data
     */
    public function getSupplyChainRisk(): array
    {
        $assets = $this->assetRepository->findAll();

        // Filter supplier-related assets
        $supplierAssets = array_filter($assets, fn(Asset $a) =>
            in_array($a->getAssetType(), ['supplier', 'cloud_service', 'third_party', 'external_service'], true)
        );

        $profiles = [];
        foreach ($supplierAssets as $asset) {
            $criticality = $this->calculateCriticality($asset);
            $profiles[] = [
                'asset_id' => $asset->getId(),
                'asset_name' => $asset->getName(),
                'asset_type' => $asset->getAssetType(),
                'criticality' => $criticality,
                'criticality_level' => $this->getCriticalityLevel($criticality),
                'owner' => $asset->getOwner(),
            ];
        }

        usort($profiles, fn($a, $b) => $b['criticality'] <=> $a['criticality']);

        // Concentration analysis
        $byOwner = [];
        foreach ($profiles as $profile) {
            $owner = $profile['owner'] ?? 'Unknown';
            if (!isset($byOwner[$owner])) {
                $byOwner[$owner] = 0;
            }
            $byOwner[$owner]++;
        }

        arsort($byOwner);

        return [
            'suppliers' => $profiles,
            'concentration' => array_slice($byOwner, 0, 10, true),
            'summary' => [
                'total_suppliers' => count($profiles),
                'critical_suppliers' => count(array_filter($profiles, fn($p) => $p['criticality_level'] === 'critical')),
                'unique_owners' => count($byOwner),
            ],
        ];
    }

    // ==================== Private Helper Methods ====================

    private function buildIncidentIndex(array $incidents): array
    {
        $index = [];
        foreach ($incidents as $incident) {
            foreach ($incident->getAffectedAssets() as $asset) {
                $assetId = $asset->getId();
                if (!isset($index[$assetId])) {
                    $index[$assetId] = [];
                }
                $index[$assetId][] = $incident;
            }
        }
        return $index;
    }

    private function buildAssetProfiles(array $assets, array $incidentsByAsset): array
    {
        $profiles = [];
        foreach ($assets as $asset) {
            $assetIncidents = $incidentsByAsset[$asset->getId()] ?? [];
            $criticality = $this->calculateCriticality($asset);
            $probability = $this->calculateIncidentProbability($asset, $assetIncidents);

            $profiles[] = [
                'asset_id' => $asset->getId(),
                'asset_name' => $asset->getName(),
                'asset_type' => $asset->getAssetType(),
                'owner' => $asset->getOwner(),
                'confidentiality' => $asset->getConfidentialityValue() ?? 1,
                'integrity' => $asset->getIntegrityValue() ?? 1,
                'availability' => $asset->getAvailabilityValue() ?? 1,
                'criticality' => $criticality,
                'criticality_level' => $this->getCriticalityLevel($criticality),
                'historical_incidents' => count($assetIncidents),
                'incident_probability' => $probability,
                'risk_score' => round($criticality * ($probability / 100), 1),
            ];
        }

        usort($profiles, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);

        return $profiles;
    }

    private function calculateCriticality(Asset $asset): int
    {
        return (
            ($asset->getConfidentialityValue() ?? 1) +
            ($asset->getIntegrityValue() ?? 1) +
            ($asset->getAvailabilityValue() ?? 1)
        );
    }

    private function getCriticalityLevel(int $criticality): string
    {
        return match (true) {
            $criticality >= 12 => 'critical',
            $criticality >= 9 => 'high',
            $criticality >= 6 => 'medium',
            default => 'low',
        };
    }

    private function calculateIncidentProbability(Asset $asset, array $incidents): float
    {
        $score = 0;

        // Factor 1: Historical incidents (0-40 points)
        $incidentCount = count($incidents);
        $score += min(40, $incidentCount * 10);

        // Factor 2: Criticality (0-30 points)
        $criticality = $this->calculateCriticality($asset);
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

    private function getHighRiskAssets(array $profiles): array
    {
        return array_slice(
            array_filter($profiles, fn($p) => $p['risk_score'] >= 10),
            0,
            10
        );
    }

    private function calculateDistribution(array $profiles): array
    {
        $distribution = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        foreach ($profiles as $profile) {
            $distribution[$profile['criticality_level']]++;
        }

        return $distribution;
    }

    private function groupByType(array $profiles): array
    {
        $byType = [];
        foreach ($profiles as $profile) {
            $type = $profile['asset_type'] ?? 'unknown';
            if (!isset($byType[$type])) {
                $byType[$type] = [
                    'count' => 0,
                    'critical' => 0,
                    'high' => 0,
                ];
            }
            $byType[$type]['count']++;
            if ($profile['criticality_level'] === 'critical') {
                $byType[$type]['critical']++;
            } elseif ($profile['criticality_level'] === 'high') {
                $byType[$type]['high']++;
            }
        }

        return $byType;
    }

    private function calculateSummary(array $profiles): array
    {
        $totalAssets = count($profiles);

        return [
            'total_assets' => $totalAssets,
            'high_risk_count' => count(array_filter($profiles, fn($p) => $p['risk_score'] >= 10)),
            'critical_count' => count(array_filter($profiles, fn($p) => $p['criticality_level'] === 'critical')),
            'average_criticality' => $totalAssets > 0
                ? round(array_sum(array_column($profiles, 'criticality')) / $totalAssets, 1)
                : 0,
            'average_probability' => $totalAssets > 0
                ? round(array_sum(array_column($profiles, 'incident_probability')) / $totalAssets, 1)
                : 0,
            'with_incidents' => count(array_filter($profiles, fn($p) => $p['historical_incidents'] > 0)),
        ];
    }
}
