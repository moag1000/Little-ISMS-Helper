<?php

declare(strict_types=1);

namespace App\Risk;

use App\Entity\Tenant;

/**
 * Single source of truth for risk-matrix score thresholds.
 *
 * Per ISO 27001 Cl. 6.1.2 b — risk-acceptance criteria must be consistent
 * across UI, filter, matrix, and backend computation. Prior to this class
 * four independent threshold tables (3 YAML blocks + RiskMatrixService)
 * defined critical/high/medium/low bands inconsistently, so filter "high"
 * could surface risks the matrix view labelled "medium" for identical scores.
 *
 * Since P8 the band edges belong to the organisation (ISO 27001 6.1.2 a: the
 * organisation defines its criteria): they are maintained under
 * Risikokriterien (RiskApprovalConfig) and approved by top management with
 * the decision type „Risikokriterien“. {@see RiskScaleProvider} hands the
 * edges of the current organisation to this class; without one (unit tests,
 * no configuration yet) the defaults below apply.
 *
 * Read the edges via criticalMin()/highMin()/mediumMin() or classify() —
 * never the DEFAULT_* constants and never literal 20/12/6 — and always with
 * the tenant of the data (the risk's organisation; Gate 87). Without it the
 * tenant in scope applies: defaults in commands/jobs, the viewer's scale in
 * holding views.
 *
 * Score range: 1-25 (probability 1-5 x impact 1-5). The 5×5 matrix itself is
 * fixed so that existing ratings stay comparable.
 */
final class RiskMatrixThresholds
{
    public const int DEFAULT_CRITICAL_MIN = 20;
    public const int DEFAULT_HIGH_MIN = 12;
    public const int DEFAULT_MEDIUM_MIN = 6;
    public const int LOW_MIN = 1;

    public const int SCORE_MAX = 25;

    /** Levels per axis (probability and impact). */
    public const int LEVELS = 5;

    /** @var (\Closure(?Tenant): ?array{critical: int, high: int, medium: int})|null */
    private static ?\Closure $provider = null;

    /**
     * Registers the source of an organisation's band edges (Kernel boot). The
     * closure gets the record's organisation (null = the one in scope) and
     * returns null when no organisation scale applies.
     *
     * @param (\Closure(?Tenant): ?array{critical: int, high: int, medium: int})|null $provider
     */
    public static function useProvider(?\Closure $provider): void
    {
        self::$provider = $provider;
    }

    public static function criticalMin(?Tenant $tenant = null): int
    {
        return self::edges($tenant)['critical'];
    }

    public static function highMin(?Tenant $tenant = null): int
    {
        return self::edges($tenant)['high'];
    }

    public static function mediumMin(?Tenant $tenant = null): int
    {
        return self::edges($tenant)['medium'];
    }

    /**
     * Classify a numeric risk score into a band identifier. Pass the record's
     * organisation where it is known: the scale is the record's, not the
     * viewer's (holding views, commands and workers without a tenant context).
     */
    public static function classify(int $score, ?Tenant $tenant = null): string
    {
        $edges = self::edges($tenant);

        return match (true) {
            $score >= $edges['critical'] => 'critical',
            $score >= $edges['high'] => 'high',
            $score >= $edges['medium'] => 'medium',
            default => 'low',
        };
    }

    /**
     * Returns the configured score bands as [level => [min, max]] tuples.
     *
     * @return array<string, array{min: int, max: int}>
     */
    public static function getBands(?Tenant $tenant = null): array
    {
        $edges = self::edges($tenant);

        return [
            'critical' => ['min' => $edges['critical'], 'max' => self::SCORE_MAX],
            'high'     => ['min' => $edges['high'], 'max' => $edges['critical'] - 1],
            'medium'   => ['min' => $edges['medium'], 'max' => $edges['high'] - 1],
            'low'      => ['min' => self::LOW_MIN, 'max' => $edges['medium'] - 1],
        ];
    }

    /** Edges must leave every band at least one score: 2 ≤ medium < high < critical ≤ 25. */
    public static function isValid(int $critical, int $high, int $medium): bool
    {
        return $medium >= self::LOW_MIN + 1 && $medium < $high && $high < $critical && $critical <= self::SCORE_MAX;
    }

    /** @return array{critical: int, high: int, medium: int} */
    public static function defaults(): array
    {
        return [
            'critical' => self::DEFAULT_CRITICAL_MIN,
            'high' => self::DEFAULT_HIGH_MIN,
            'medium' => self::DEFAULT_MEDIUM_MIN,
        ];
    }

    /** @return array{critical: int, high: int, medium: int} */
    private static function edges(?Tenant $tenant = null): array
    {
        if (self::$provider !== null) {
            try {
                $edges = (self::$provider)($tenant);
            } catch (\Throwable) {
                // No database yet (setup, before the migration): defaults.
                $edges = null;
            }
            if (is_array($edges) && self::isValid($edges['critical'], $edges['high'], $edges['medium'])) {
                return $edges;
            }
        }

        return self::defaults();
    }
}
