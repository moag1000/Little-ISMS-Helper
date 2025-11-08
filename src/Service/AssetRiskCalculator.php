<?php

namespace App\Service;

use App\Entity\Asset;
use DateTimeImmutable;

/**
 * Asset Risk Calculator Service
 *
 * Calculates risk metrics for assets based on:
 * - CIA (Confidentiality, Integrity, Availability) values
 * - Active risks associated with the asset
 * - Recent security incidents
 * - Control coverage (protection measures)
 *
 * Separation of Concerns:
 * - Moves business logic out of Asset entity
 * - Makes risk calculation testable and reusable
 * - Follows Symfony best practice for service layer
 *
 * ISO 27001 Compliance:
 * - A.8.2.1: Asset classification
 * - A.12.6.1: Management of technical vulnerabilities
 */
class AssetRiskCalculator
{
    /**
     * Calculate comprehensive risk score for an asset
     *
     * Risk Score Formula:
     * - Base Score: CIA values (0-5 each) * 10 = max 150 points
     * - Active Risks: count * 5 points each
     * - Recent Incidents (6 months): count * 10 points each
     * - Control Coverage: reduces by 3 points per control (max -30)
     *
     * @return float Score between 0 and 100
     */
    public function calculateRiskScore(Asset $asset): float
    {
        $score = 0;

        // Base score from CIA values
        $score += $asset->getTotalValue() * 10;

        // Risks impact
        $activeRisks = $asset->getRisks()->filter(fn($r) => $r->getStatus() === 'active')->count();
        $score += $activeRisks * 5;

        // Incidents impact (recent incidents = higher risk)
        $recentIncidents = $this->countRecentIncidents($asset);
        $score += $recentIncidents * 10;

        // Control coverage (more controls = lower risk)
        $controlCount = $asset->getProtectingControls()->count();
        $score -= min($controlCount * 3, 30); // Max 30 points reduction

        return max(0, min(100, $score));
    }

    /**
     * Check if asset is classified as high-risk
     *
     * Threshold: Risk score >= 70
     *
     * @return bool True if asset requires immediate attention
     */
    public function isHighRisk(Asset $asset): bool
    {
        return $this->calculateRiskScore($asset) >= 70;
    }

    /**
     * Determine the protection status of an asset
     *
     * Classification:
     * - unprotected: No controls but has active risks
     * - under_protected: Controls < Active risks
     * - adequately_protected: Controls >= Active risks
     * - unknown: No risks, no controls (or edge case)
     *
     * @return string Protection status classification
     */
    public function getProtectionStatus(Asset $asset): string
    {
        $controlCount = $asset->getProtectingControls()->count();
        $riskCount = $asset->getRisks()->filter(fn($r) => $r->getStatus() === 'active')->count();

        if ($controlCount === 0 && $riskCount > 0) {
            return 'unprotected';
        } elseif ($controlCount < $riskCount) {
            return 'under_protected';
        } elseif ($controlCount >= $riskCount) {
            return 'adequately_protected';
        }

        return 'unknown';
    }

    /**
     * Count incidents detected within the last 6 months
     *
     * Recent incidents indicate current vulnerabilities and active threats
     *
     * @return int Number of recent incidents
     */
    private function countRecentIncidents(Asset $asset): int
    {
        $sixMonthsAgo = new DateTimeImmutable('-6 months');

        return $asset->getIncidents()->filter(function($incident) use ($sixMonthsAgo) {
            $detectedAt = $incident->getDetectedAt();
            return $detectedAt && $detectedAt >= $sixMonthsAgo;
        })->count();
    }
}
