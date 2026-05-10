<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

/**
 * Policy-Wizard — DTO for {@see CrossCoverageCalculator} results.
 *
 * Aggregates cross-framework coverage of a finished {@see \App\Entity\WizardRun}
 * so the result page can render "these N docs cover X% ISO 27001 + Y% DORA +
 * Z% GDPR + W% BSI" plus per-document framework attribution.
 *
 * The DTO is intentionally framework-code agnostic: the
 * {@see $coverageByFramework} map is keyed by upper-case framework code
 * (`ISO27001`, `DORA`, `BSI_GRUNDSCHUTZ`, `GDPR`, `ISO27701`) so a Twig
 * template can iterate with the same lookup helpers the SoA already uses.
 */
final readonly class CrossCoverageReport
{
    /**
     * @param array<string, array{
     *   code: string,
     *   label: string,
     *   total_requirements: int,
     *   covered_requirements: int,
     *   coverage_percent: float,
     *   covered_refs: list<string>,
     * }> $coverageByFramework Keyed by upper-case framework code.
     * @param array<int, list<array{code: string, label: string, refs: list<string>}>> $documentToFrameworks
     *     Keyed by Document ID; each entry is the list of frameworks the
     *     document contributes to + the concrete refs (e.g. ["A.5.15"]).
     * @param array<string, list<string>> $gapsByFramework Keyed by framework
     *     code → list of well-known requirement refs that the wizard
     *     output did NOT cover (best-effort, only populated for frameworks
     *     where the calculator can derive a reasonable universe).
     */
    public function __construct(
        public array $coverageByFramework,
        public array $documentToFrameworks,
        public array $gapsByFramework,
    ) {
    }

    /**
     * Convenience accessor — true when the wizard run touched at least
     * one framework (drives the "render or hide" decision in Twig).
     */
    public function hasAnyCoverage(): bool
    {
        foreach ($this->coverageByFramework as $row) {
            if (($row['covered_requirements'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }
}
