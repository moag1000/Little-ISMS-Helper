<?php

declare(strict_types=1);

namespace App\Service\Bsi;

use App\Entity\ComplianceFramework;

/**
 * Contract for applying a single panel-verdict fixture to ComplianceMapping rows.
 *
 * Extracted so the setup-time orchestrator {@see PanelVerdictAutoApplier} can be
 * unit-tested against a doubled applier (the concrete {@see PanelVerdictApplier}
 * is `final`). The default service alias binds this interface to
 * {@see PanelVerdictApplier} (see config/services.yaml).
 */
interface PanelVerdictApplierInterface
{
    /**
     * Apply all panel verdicts in $fixturePath to the matching ComplianceMappings.
     *
     * @param string $fixturePath Relative path from project root to the verdict fixture.
     * @param ComplianceFramework|null $source Source framework (null → resolve default).
     * @param ComplianceFramework|null $target Target framework (null → resolve default).
     * @param bool $dryRun When true: compute counts but write nothing.
     *
     * @return array{
     *     ki_validiert: int,
     *     rejected: int,
     *     needs_review: int,
     *     panel_discovered: int,
     *     panel_discovered_skipped: int,
     *     not_matched: int,
     *     already_applied: int,
     *     total: int,
     * }
     */
    public function apply(
        string $fixturePath = PanelVerdictApplier::FIXTURE_PATH,
        ?ComplianceFramework $source = null,
        ?ComplianceFramework $target = null,
        bool $dryRun = false,
    ): array;
}
