<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Repository\ComplianceMappingRepository;

/**
 * Transitive Coverage Service (Sprint 2 / B1).
 *
 * Answers the single question every cross-framework auditor asks:
 * *„Welche Controls sind die Herkunft dieser Abdeckung?"*
 *
 * Takes a ComplianceRequirement (e.g. NIS2 21.2.h) and walks:
 *
 *  1. Direct:     Controls mapped via `requirement.mappedControls`.
 *  2. Transitive: For every inbound ComplianceMapping (other
 *                 Requirement → this Requirement), collect the
 *                 source-Requirement's mapped Controls.
 *
 * The resulting coverage is a weighted % that explicitly surfaces the
 * control chain so the CM can click through to any contributing
 * Control. Without this visibility, Cross-Framework-Reuse stays as a
 * back-end computation that auditors cannot verify.
 *
 * Algorithm:
 *  - Direct contribution:  per Control, implementationPercentage
 *    (fallback 100 for `implemented`, 50 for `in_progress`, 0 else).
 *  - Transitive contribution: per inbound mapping M (source → target),
 *    source.calculateFulfillmentFromControls() × (M.mappingPercentage
 *    / 100). mappingPercentage > 100 is clamped to 100 so a single
 *    framework never claims >100 % coverage from another.
 *  - Final percentage: weighted average over all unique contributors,
 *    clamped to [0, 100].
 *
 * Output shape is intentionally flat so Twig can render it without
 * nested conditionals — see `_transitive_coverage_badge.html.twig`.
 */
final class TransitiveCoverageService
{
    public function __construct(
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {
    }

    /**
     * @return array{
     *     percentage: int,
     *     status: string,
     *     direct: list<array{control: Control, contribution: int}>,
     *     transitive: list<array{
     *         source_requirement: ComplianceRequirement,
     *         mapping_percentage: int,
     *         source_fulfillment: int,
     *         effective_contribution: int,
     *         controls: list<Control>
     *     }>,
     *     counts: array{
     *         direct_controls: int,
     *         transitive_sources: int,
     *         transitive_controls: int,
     *         unique_controls: int
     *     }
     * }
     */
    public function computeForRequirement(ComplianceRequirement $target): array
    {
        $direct = [];
        $uniqueControls = [];

        foreach ($target->getMappedControls() as $control) {
            $contribution = $this->controlContribution($control);
            $direct[] = ['control' => $control, 'contribution' => $contribution];
            $uniqueControls[(int) $control->getId()] = $control;
        }

        $transitive = [];
        $transitiveControlsCount = 0;
        foreach ($this->mappingRepository->findMappingsToRequirement($target) as $mapping) {
            $bucket = $this->transitiveBucket($mapping);
            if ($bucket === null) {
                continue;
            }
            $transitive[] = $bucket;
            $transitiveControlsCount += count($bucket['controls']);
            foreach ($bucket['controls'] as $control) {
                $uniqueControls[(int) $control->getId()] = $control;
            }
        }

        $percentage = $this->aggregatePercentage($direct, $transitive);

        return [
            'percentage' => $percentage,
            'status' => $this->statusFor($percentage),
            'direct' => $direct,
            'transitive' => $transitive,
            'counts' => [
                'direct_controls' => count($direct),
                'transitive_sources' => count($transitive),
                'transitive_controls' => $transitiveControlsCount,
                'unique_controls' => count($uniqueControls),
            ],
        ];
    }

    /**
     * Individual control contribution — mirrors the heuristic used in
     * ComplianceRequirement::calculateFulfillmentFromControls but
     * returns the per-control number so the UI can explain the chain.
     */
    private function controlContribution(Control $control): int
    {
        $status = (string) $control->getImplementationStatus();
        $pct = (int) ($control->getImplementationPercentage() ?? match ($status) {
            'implemented' => 100,
            'in_progress' => 50,
            default => 0,
        });
        return max(0, min(100, $pct));
    }

    /**
     * @return array{
     *     source_requirement: ComplianceRequirement,
     *     mapping_percentage: int,
     *     source_fulfillment: int,
     *     effective_contribution: int,
     *     controls: list<Control>
     * }|null
     */
    private function transitiveBucket(ComplianceMapping $mapping): ?array
    {
        $source = $mapping->getSourceRequirement();
        if (!$source instanceof ComplianceRequirement) {
            return null;
        }
        $sourceFulfillment = (int) $source->calculateFulfillmentFromControls();
        if ($sourceFulfillment <= 0) {
            return null;
        }
        $mappingPct = min(100, max(0, $mapping->getMappingPercentage()));
        if ($mappingPct === 0) {
            return null;
        }
        $controls = [];
        foreach ($source->getMappedControls() as $control) {
            $controls[] = $control;
        }

        return [
            'source_requirement' => $source,
            'mapping_percentage' => $mappingPct,
            'source_fulfillment' => $sourceFulfillment,
            'effective_contribution' => (int) round(($sourceFulfillment * $mappingPct) / 100),
            'controls' => $controls,
        ];
    }

    /**
     * @param list<array{control: Control, contribution: int}> $direct
     * @param list<array{
     *     source_requirement: ComplianceRequirement,
     *     mapping_percentage: int,
     *     source_fulfillment: int,
     *     effective_contribution: int,
     *     controls: list<Control>
     * }> $transitive
     */
    private function aggregatePercentage(array $direct, array $transitive): int
    {
        $values = [];
        foreach ($direct as $row) {
            $values[] = $row['contribution'];
        }
        foreach ($transitive as $row) {
            $values[] = $row['effective_contribution'];
        }
        if ($values === []) {
            return 0;
        }
        $avg = array_sum($values) / count($values);
        return (int) round(max(0, min(100, $avg)));
    }

    private function statusFor(int $pct): string
    {
        if ($pct >= 80) {
            return 'good';
        }
        if ($pct >= 50) {
            return 'warning';
        }
        if ($pct > 0) {
            return 'danger';
        }
        return 'na';
    }
}
