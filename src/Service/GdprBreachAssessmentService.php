<?php

namespace App\Service;

/**
 * GDPR Breach Assessment Service
 *
 * Implements Art. 33 GDPR breach assessment logic for automated wizard.
 * Helps non-technical users determine if a data breach is reportable
 * under GDPR Article 33 within 72 hours.
 *
 * Risk scoring based on:
 * - Data type sensitivity (Art. 9/10 special categories = higher risk)
 * - Scale of affected individuals
 * - Combined risk assessment for reporting threshold
 */
class GdprBreachAssessmentService
{
    /**
     * Data type risk scores (1-4 scale)
     */
    private const array DATA_TYPE_SCORES = [
        'names_contact' => 1,
        'financial' => 2,
        'health_biometric' => 3,
        'special_category_art9' => 4,
        'criminal_records_art10' => 4,
    ];

    /**
     * Scale scores based on number of affected individuals (1-4 scale)
     */
    private const array SCALE_SCORES = [
        'under_100' => 1,
        '100_to_1000' => 2,
        '1001_to_10000' => 3,
        'over_10000' => 4,
    ];

    /**
     * GDPR Art. 33 reporting threshold
     * Score >= 5 is considered reportable
     */
    private const int REPORTABLE_THRESHOLD = 5;

    /**
     * Assess breach risk based on data types and affected count
     *
     * @param array $dataTypes Array of selected data type keys
     * @param string $scaleKey Scale key (under_100, 100_to_1000, etc.)
     * @return array Assessment result with risk_level, is_reportable, score
     */
    public function assessBreachRisk(array $dataTypes, string $scaleKey): array
    {
        // Calculate data sensitivity score (sum of all selected data types)
        $dataSensitivityScore = 0;
        foreach ($dataTypes as $dataType) {
            if (isset(self::DATA_TYPE_SCORES[$dataType])) {
                $dataSensitivityScore += self::DATA_TYPE_SCORES[$dataType];
            }
        }

        // Get scale score
        $scaleScore = self::SCALE_SCORES[$scaleKey] ?? 1;

        // Combined risk score (weighted: 60% data sensitivity, 40% scale)
        $totalScore = ($dataSensitivityScore * 0.6) + ($scaleScore * 0.4);

        // Determine risk level
        $riskLevel = $this->calculateRiskLevel($totalScore);

        // Determine if reportable under GDPR Art. 33
        $isReportable = $this->isReportableUnderGdpr($totalScore);

        return [
            'risk_level' => $riskLevel,
            'is_reportable' => $isReportable,
            'score' => round($totalScore, 2),
            'data_sensitivity_score' => $dataSensitivityScore,
            'scale_score' => $scaleScore,
            'recommendation' => $this->generateRecommendation($riskLevel, $isReportable),
        ];
    }

    /**
     * Determine if breach is reportable under GDPR Art. 33
     *
     * @param float $riskScore Combined risk score
     * @return bool True if reportable to supervisory authority
     */
    public function isReportableUnderGdpr(float $riskScore): bool
    {
        return $riskScore >= self::REPORTABLE_THRESHOLD;
    }

    /**
     * Calculate risk level from score
     *
     * @param float $score Risk score
     * @return string low, medium, high, or very_high
     */
    private function calculateRiskLevel(float $score): string
    {
        if ($score < 3) {
            return 'low';
        }
        if ($score < 5) {
            return 'medium';
        }
        if ($score < 7) {
            return 'high';
        }
        else {
            return 'very_high';
        }
    }

    /**
     * Generate human-readable recommendation based on assessment
     *
     * @param string $riskLevel Risk level (low, medium, high, very_high)
     * @param bool $isReportable Whether breach is reportable
     * @return string Recommendation key for translation
     */
    public function generateRecommendation(string $riskLevel, bool $isReportable): string
    {
        if (!$isReportable) {
            return 'not_reportable';
        }

        return match ($riskLevel) {
            'very_high' => 'reportable_very_high',
            'high' => 'reportable_high',
            'medium' => 'reportable_medium',
            default => 'reportable_low',
        };
    }

    /**
     * Get scale key from affected count number
     *
     * @param int $affectedCount Number of affected individuals
     * @return string Scale key
     */
    public function getScaleKeyFromCount(int $affectedCount): string
    {
        if ($affectedCount < 100) {
            return 'under_100';
        }
        if ($affectedCount <= 1000) {
            return '100_to_1000';
        }
        if ($affectedCount <= 10000) {
            return '1001_to_10000';
        }
        else {
            return 'over_10000';
        }
    }

    /**
     * Get affected count from scale key (returns midpoint of range)
     *
     * @param string $scaleKey Scale key
     * @return int Estimated affected count
     */
    public function getCountFromScaleKey(string $scaleKey): int
    {
        return match ($scaleKey) {
            'under_100' => 50,
            '100_to_1000' => 550,
            '1001_to_10000' => 5500,
            'over_10000' => 15000,
            default => 0,
        };
    }
}
