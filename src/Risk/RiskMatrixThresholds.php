<?php

declare(strict_types=1);

namespace App\Risk;

/**
 * Single source of truth for risk-matrix score thresholds.
 *
 * Per ISO 27001 Cl. 6.1.2 b — risk-acceptance criteria must be consistent
 * across UI, filter, matrix, and backend computation. Prior to this class
 * four independent threshold tables (3 YAML blocks + RiskMatrixService)
 * defined critical/high/medium/low bands inconsistently, so filter "high"
 * could surface risks the matrix view labelled "medium" for identical scores.
 *
 * Score range: 1-25 (probability 1-5 x impact 1-5).
 */
final class RiskMatrixThresholds
{
    public const int CRITICAL_MIN = 20;
    public const int HIGH_MIN = 12;
    public const int MEDIUM_MIN = 6;
    public const int LOW_MIN = 1;

    public const int SCORE_MAX = 25;

    /**
     * Classify a numeric risk score into a band identifier.
     */
    public static function classify(int $score): string
    {
        return match (true) {
            $score >= self::CRITICAL_MIN => 'critical',
            $score >= self::HIGH_MIN => 'high',
            $score >= self::MEDIUM_MIN => 'medium',
            default => 'low',
        };
    }

    /**
     * Returns the configured score bands as [level => [min, max]] tuples.
     *
     * @return array<string, array{min: int, max: int}>
     */
    public static function getBands(): array
    {
        return [
            'critical' => ['min' => self::CRITICAL_MIN, 'max' => self::SCORE_MAX],
            'high'     => ['min' => self::HIGH_MIN, 'max' => self::CRITICAL_MIN - 1],
            'medium'   => ['min' => self::MEDIUM_MIN, 'max' => self::HIGH_MIN - 1],
            'low'      => ['min' => self::LOW_MIN, 'max' => self::MEDIUM_MIN - 1],
        ];
    }
}
