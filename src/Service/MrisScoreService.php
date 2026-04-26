<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;

/**
 * Berechnet einen aggregierten Mythos-Resilience-Indikator (MRI) als
 * Management-Kennzahl. NICHT als Audit-Wahrheit gedacht.
 *
 * MRIS v1.5 (Peddi 2026, CC BY 4.0) definiert selbst KEIN Aggregat — diese
 * Zahl ist eine LIH-spezifische Hilfsgroesse fuer Quartals-Reviews und
 * Vorstands-Briefings, deren Dekomposition jederzeit nachvollziehbar bleibt.
 *
 * Audit-Disclaimer (Pflicht in UI + Reports):
 *   „Interner Management-Indikator. MRIS v1.5 selbst definiert keine
 *    aggregierte Bewertung. Die Einzeldimensionen (4-Kategorien-Klassifikation,
 *    MHC-Reifegrad, Manual-KPI-Pflege, AI-Agent-Doku) sind im Audit
 *    massgeblich, nicht der aggregierte Score."
 *
 * Gewichtung (5 Dimensionen, summiert auf 100):
 *   - 25 % Standfest-Anteil           (positive Klassifikation)
 *   - 30 % Durchschnittlicher Reifegrad ueber alle MHCs
 *   - 20 % Inverse Reibung-Penalty   (1 - reibung_anteil)
 *   - 15 % Manual-KPI-Fill-Rate
 *   - 10 % AI-Agent-Doku-Vollstaendigkeit (avg)
 */
final class MrisScoreService
{
    public const FRAMEWORK_CODE = 'MRIS-v1.5';

    /** @var array<string, int> */
    private const STAGE_WEIGHT = ['initial' => 33, 'defined' => 66, 'managed' => 100];

    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly AssetRepository $assetRepository,
        private readonly AiAgentInventoryService $aiAgentInventoryService,
    ) {
    }

    /**
     * @return array{
     *   score: float,
     *   breakdown: array<string, array{value: float, weight: int, contribution: float, source: string}>,
     *   disclaimer: string
     * }
     */
    public function compute(Tenant $tenant): array
    {
        $standfestPct = $this->standfestShare($tenant);
        $reibungPct = $this->reibungShare($tenant);
        $maturityPct = $this->avgMaturity();
        $manualPct = $this->manualKpiFillRate($tenant);
        $aiPct = $this->avgAiAgentCompleteness($tenant);

        $breakdown = [
            'standfest' => [
                'value' => $standfestPct,
                'weight' => 25,
                'contribution' => round($standfestPct * 0.25, 1),
                'source' => 'Anteil Standfest-Controls auf 93 Annex-A.',
            ],
            'maturity' => [
                'value' => $maturityPct,
                'weight' => 30,
                'contribution' => round($maturityPct * 0.30, 1),
                'source' => 'Durchschnittlicher MHC-Reifegrad (Ist) gemittelt ueber 13 MHCs.',
            ],
            'reibung_inverse' => [
                'value' => round(100.0 - $reibungPct, 1),
                'weight' => 20,
                'contribution' => round((100.0 - $reibungPct) * 0.20, 1),
                'source' => 'Invers zum Anteil Reine-Reibung-Controls.',
            ],
            'manual_kpis' => [
                'value' => $manualPct,
                'weight' => 15,
                'contribution' => round($manualPct * 0.15, 1),
                'source' => 'Anteil befuellter manueller KPIs (5 KPIs).',
            ],
            'ai_agent_doku' => [
                'value' => $aiPct,
                'weight' => 10,
                'contribution' => round($aiPct * 0.10, 1),
                'source' => 'Durchschnittliche AI-Agent-Doku-Vollstaendigkeit.',
            ],
        ];

        $score = (float) array_sum(array_column($breakdown, 'contribution'));

        return [
            'score' => round($score, 1),
            'breakdown' => $breakdown,
            'disclaimer' => 'Interner Management-Indikator. MRIS v1.5 (Peddi 2026, CC BY 4.0) definiert selbst keine aggregierte Bewertung. Audit-relevant sind die Einzeldimensionen, nicht der aggregierte Score.',
        ];
    }

    private function standfestShare(Tenant $tenant): float
    {
        $controls = $this->controlRepository->findByTenant($tenant);
        if (count($controls) === 0) {
            return 0.0;
        }
        $standfest = 0;
        foreach ($controls as $c) {
            if ($c->getMythosResilience() === 'standfest') {
                $standfest++;
            }
        }
        return round(($standfest / count($controls)) * 100.0, 1);
    }

    private function reibungShare(Tenant $tenant): float
    {
        $controls = $this->controlRepository->findByTenant($tenant);
        if (count($controls) === 0) {
            // Keine Population → reibung_inverse trägt nicht bei (gibt 100,
            // damit invers = 0 wird; siehe compute() Eingang).
            return 100.0;
        }
        $reibung = 0;
        foreach ($controls as $c) {
            if ($c->getMythosResilience() === 'reibung') {
                $reibung++;
            }
        }
        return round(($reibung / count($controls)) * 100.0, 1);
    }

    private function avgMaturity(): float
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => self::FRAMEWORK_CODE]);
        if (!$framework instanceof ComplianceFramework) {
            return 0.0;
        }
        $reqs = $this->requirementRepository->findBy(['complianceFramework' => $framework]);
        if (count($reqs) === 0) {
            return 0.0;
        }
        $sum = 0;
        $counted = 0;
        foreach ($reqs as $r) {
            $current = $r->getMaturityCurrent();
            if ($current !== null && isset(self::STAGE_WEIGHT[$current])) {
                $sum += self::STAGE_WEIGHT[$current];
                $counted++;
            }
        }
        return $counted > 0 ? round($sum / $counted, 1) : 0.0;
    }

    private function manualKpiFillRate(Tenant $tenant): float
    {
        $settings = $tenant->getSettings() ?? [];
        $values = $settings['mris']['manual_kpis'] ?? [];
        $total = 5;  // 5 manuelle KPIs gem. MRIS Kap. 10.6
        $filled = 0;
        foreach (['sbom_coverage', 'kev_patch_latency', 'ccm_coverage', 'crypto_inventory_coverage', 'tlpt_findings_closure'] as $id) {
            if (isset($values[$id]) && is_numeric($values[$id])) {
                $filled++;
            }
        }
        return round(($filled / $total) * 100.0, 1);
    }

    private function avgAiAgentCompleteness(Tenant $tenant): float
    {
        $stats = $this->aiAgentInventoryService->inventoryStats($tenant);
        return (float) ($stats['avg_completeness'] ?? 0.0);
    }
}
