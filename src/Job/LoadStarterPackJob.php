<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\Compliance\MappingSeedService;
use App\Service\ComplianceFrameworkLoaderService;
use App\Service\ModuleConfigurationService;

/**
 * Async admin job: one-click "Starter-Pack" for a fresh tenant landing on an
 * empty compliance/mapping area.
 *
 * Loads the baseline framework catalogue and seeds the cross-framework
 * mappings between them, so a junior implementer gets immediate value instead
 * of a blank screen:
 *   - ISO/IEC 27001:2022     (always)
 *   - BSI IT-Grundschutz     (always)
 *   - GDPR / DSGVO           (only when the `privacy` module is active)
 *   - then the applicable mapping seeds (BSI↔ISO always; GDPR↔ISO iff GDPR loaded)
 *
 * Idempotent end-to-end: {@see ComplianceFrameworkLoaderService::loadFramework()}
 * skips frameworks that are already loaded-with-requirements, and the mapping
 * seed commands skip existing source→target pairs. Re-running the job therefore
 * creates no duplicate frameworks, requirements or mappings — and the feature is
 * reversible-by-design because it only ever writes data.
 *
 * Request-bound services (Session/Request/TenantContext-via-request) are NOT
 * used — per the async-job contract everything needed is passed via args. The
 * frameworks + mappings are global catalogue data (not per-tenant rows), so the
 * tenant/user args are carried for audit/scope symmetry with sibling jobs (see
 * {@see ExportRisksJob}) and forward-compatibility.
 *
 * Args:
 *   tenantId (?int) — calling tenant (carried for symmetry; catalogue is global)
 *   userId   (?int) — caller (carried for symmetry / future audit)
 */
final class LoadStarterPackJob implements AsyncJobInterface
{
    /** Frameworks always part of the starter pack. */
    private const BASE_CODES = ['ISO27001', 'BSI_GRUNDSCHUTZ'];

    /** Framework loaded only when its gating module is active. */
    private const GDPR_CODE = 'GDPR';

    public function __construct(
        private readonly ComplianceFrameworkLoaderService $loaderService,
        private readonly MappingSeedService $mappingSeedService,
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $codes = $this->packComposition();
        $total = count($codes) + 1; // frameworks + 1 mapping-seed step
        $step = 0;

        $loadedCodes = [];

        foreach ($codes as $code) {
            $ctx->progress($step, $total, sprintf('Lade Framework %s …', $code));

            $result = $this->loaderService->loadFramework($code);

            // loadFramework() returns success=false both for hard errors AND
            // for the idempotent "already loaded with requirements" skip. Treat
            // an already-present framework as loaded; only a genuinely absent
            // framework after the call is a hard failure.
            if ($result['success'] || $this->loaderService->isFrameworkLoaded($code)) {
                $loadedCodes[] = $code;
            } else {
                // @intentional-assertion: a base framework must be loadable —
                // a missing loader / DB error here is unrecoverable for the pack.
                throw new \RuntimeException(sprintf(
                    'Starter-Pack: Framework "%s" konnte nicht geladen werden: %s',
                    $code,
                    $result['message'] ?? 'unbekannter Fehler',
                ));
            }

            $step++;
            $ctx->progress($step, $total, sprintf('Framework %s bereit.', $code));
        }

        $ctx->message('Verknüpfe Frameworks (Cross-Framework-Mappings) …');
        $mapResult = $this->mappingSeedService->seedAvailablePairs($loadedCodes);
        $step++;

        $ctx->progress($step, $total, sprintf(
            'Fertig. %d Framework(s) geladen, %d neue Zuordnung(en) erstellt.',
            count($loadedCodes),
            $mapResult['seeded'],
        ));
    }

    /**
     * Resolve the framework codes that make up the pack for the current tenant.
     * GDPR is module-gated; ISO 27001 + BSI are always included.
     *
     * @return list<string>
     */
    private function packComposition(): array
    {
        $codes = self::BASE_CODES;

        if ($this->moduleConfiguration->isModuleActive('privacy')) {
            $codes[] = self::GDPR_CODE;
        }

        return $codes;
    }
}
