<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Rollup;

use App\Entity\Tenant;

/**
 * CISO-Executive Reporting (Task #130) — read-only DTO carrying the
 * quarterly trend snapshot assembled by {@see KonzernTrendCalculator}.
 *
 * The trend feeds two render surfaces:
 *  1. Inline sparklines on the Konzern-Rollup Compliance tab (per
 *     Subsidiary, last N quarters of policy-count + ack-count + score).
 *  2. The board-ready One-Pager PDF (4-quarter mini chart on the
 *     summary card).
 *
 * Shape:
 *
 *  quarters: list<string>                 // ["2024-Q3", ..., "2026-Q2"]
 *
 *  perSubsidiary: list<array{
 *      tenant_id: int,
 *      tenant_code: string,
 *      tenant_name: string,
 *      document_counts: list<int>,        // one entry per quarter
 *      approval_counts: list<int>,
 *      compliance_scores: list<float>,    // 0..100, weighted mean
 *      latest_score: float,
 *      previous_score: float,
 *      delta_percentage_points: float,    // latest - previous
 *      direction: string,                 // up|down|stable
 *  }>
 *
 *  konzernAverage: array{
 *      document_counts: list<int>,
 *      approval_counts: list<int>,
 *      compliance_scores: list<float>,
 *      latest_score: float,
 *      previous_score: float,
 *      delta_percentage_points: float,
 *      direction: string,
 *  }
 *
 *  estimatedAleEur: ?float                // SKELETON — null until risk-manager couples
 */
final readonly class KonzernTrendReport
{
    /**
     * @param list<string> $quarters
     * @param list<array<string, mixed>> $perSubsidiary
     * @param array<string, mixed> $konzernAverage
     */
    public function __construct(
        public Tenant $konzernRoot,
        public array $quarters,
        public array $perSubsidiary,
        public array $konzernAverage,
        public ?float $estimatedAleEur = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->quarters === [] || $this->perSubsidiary === [];
    }
}
