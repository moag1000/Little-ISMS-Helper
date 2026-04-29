<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\BCExerciseRepository;
use App\Repository\IncidentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Berechnet die acht Mythos-relevanten Kennzahlen aus MRIS v1.5 Kap. 10.6.
 *
 * Drei davon sind aus Bestandsdaten berechenbar (MTTC, Phishing-MFA-Share,
 * Restore-Test-Quote). Die fünf restlichen erfordern manuelle Pflege je
 * Mandant — werden in dieser Iteration als computable=false markiert.
 *
 * Quelle: Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5,
 *         Kap. 10.6 — Mythos-relevante Kennzahlen.
 * Lizenz: CC BY 4.0.
 */
final class MrisKpiService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IncidentRepository $incidentRepository,
        private readonly BCExerciseRepository $bcExerciseRepository,
    ) {
    }

    /**
     * Liefert alle acht KPIs als Array.
     *
     * @return array<int, array{id: string, name: string, value: float|int|null, unit: string, source: string, computable: bool, mhc: string}>
     */
    public function computeAll(Tenant $tenant): array
    {
        return [
            $this->mttc($tenant),
            $this->phishingResistantMfaShare($tenant),
            $this->sbomCoverage($tenant),
            $this->kevPatchLatency($tenant),
            $this->restoreTestSuccessRate($tenant),
            $this->continuousMonitoringCoverage($tenant),
            $this->cryptoInventoryCoverage($tenant),
            $this->tlptFindingsClosure($tenant),
        ];
    }

    /**
     * MTTC (Mean Time to Contain) — Durchschnitt detectedAt → resolvedAt
     * für resolved Incidents der letzten 90 Tage. Einheit: Stunden.
     */
    private function mttc(Tenant $tenant): array
    {
        $sql = <<<'SQL'
            SELECT AVG(TIMESTAMPDIFF(MINUTE, detected_at, resolved_at))
            FROM incident
            WHERE tenant_id = :tenant
              AND status = 'resolved'
              AND detected_at IS NOT NULL
              AND resolved_at IS NOT NULL
              AND detected_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        SQL;
        $minutes = $this->entityManager->getConnection()
            ->executeQuery($sql, ['tenant' => $tenant->getId()])
            ->fetchOne();

        $hours = $minutes !== null && $minutes !== false ? round((float) $minutes / 60.0, 1) : null;

        return [
            'id' => 'mttc',
            'name' => 'Mean Time to Contain (MTTC)',
            'value' => $hours,
            'unit' => 'Stunden',
            'source' => 'Incident (90 Tage, status=resolved)',
            'computable' => true,
            'mhc' => 'MHC-11',
        ];
    }

    /**
     * Phishing-resistente MFA-Quote = aktive WebAuthn-/FIDO2-Tokens
     * geteilt durch alle aktiven User mit MFA. Einheit: %.
     */
    private function phishingResistantMfaShare(Tenant $tenant): array
    {
        $totalSql = 'SELECT COUNT(DISTINCT mt.user_id) FROM mfa_token mt JOIN user u ON u.id = mt.user_id WHERE u.tenant_id = :tenant AND mt.is_active = 1';
        $resistantSql = 'SELECT COUNT(DISTINCT mt.user_id) FROM mfa_token mt JOIN user u ON u.id = mt.user_id WHERE u.tenant_id = :tenant AND mt.is_active = 1 AND mt.token_type = :type';

        $conn = $this->entityManager->getConnection();
        $total = (int) $conn->executeQuery($totalSql, ['tenant' => $tenant->getId()])->fetchOne();
        $resistant = (int) $conn->executeQuery($resistantSql, ['tenant' => $tenant->getId(), 'type' => 'webauthn'])->fetchOne();

        $share = $total > 0 ? round(($resistant / $total) * 100, 1) : null;

        return [
            'id' => 'phishing_resistant_mfa_share',
            'name' => 'Phishing-resistente MFA',
            'value' => $share,
            'unit' => '%',
            'source' => sprintf('MfaToken (webauthn/FIDO2: %d/%d)', $resistant, $total),
            'computable' => true,
            'mhc' => 'MHC-03',
        ];
    }

    /**
     * Erfolgsquote der Wiederanlauf-Tests (BCExercises mit status=completed
     * und successRating >= 4). Einheit: %.
     */
    private function restoreTestSuccessRate(Tenant $tenant): array
    {
        $sql = <<<'SQL'
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN success_rating >= 4 THEN 1 ELSE 0 END) AS passing
            FROM bc_exercise
            WHERE tenant_id = :tenant
              AND status = 'completed'
              AND exercise_date >= DATE_SUB(NOW(), INTERVAL 365 DAY)
        SQL;
        $row = $this->entityManager->getConnection()
            ->executeQuery($sql, ['tenant' => $tenant->getId()])
            ->fetchAssociative();

        $total = (int) ($row['total'] ?? 0);
        $passing = (int) ($row['passing'] ?? 0);
        $rate = $total > 0 ? round(($passing / $total) * 100, 1) : null;

        return [
            'id' => 'restore_test_success_rate',
            'name' => 'Restore-Test-Erfolgsquote',
            'value' => $rate,
            'unit' => '%',
            'source' => sprintf('BCExercise (12 Monate, completed, rating>=4: %d/%d)', $passing, $total),
            'computable' => true,
            'mhc' => 'MHC-08',
        ];
    }

    private function sbomCoverage(Tenant $tenant): array
    {
        return $this->manualKpi($tenant, 'sbom_coverage', 'SBOM-Coverage', '%', 'manuell (CRA-Pflicht)', 'MHC-02');
    }

    private function kevPatchLatency(Tenant $tenant): array
    {
        return $this->manualKpi($tenant, 'kev_patch_latency', 'KEV-Patch-Latency', 'Tage', 'manuell (CISA-KEV-Liste)', 'MHC-09');
    }

    private function continuousMonitoringCoverage(Tenant $tenant): array
    {
        return $this->manualKpi($tenant, 'ccm_coverage', 'Continuous-Monitoring-Coverage', '%', 'manuell (Policy-as-Code-Bestand)', 'MHC-10');
    }

    private function cryptoInventoryCoverage(Tenant $tenant): array
    {
        return $this->manualKpi($tenant, 'crypto_inventory_coverage', 'Krypto-Inventar-Abdeckung', '%', 'manuell (PQC-Strategie)', 'MHC-01');
    }

    private function tlptFindingsClosure(Tenant $tenant): array
    {
        return $this->manualKpi($tenant, 'tlpt_findings_closure', 'TLPT-Findings-Closure', '%', 'manuell (DORA Art. 26/27)', 'MHC-12');
    }

    /**
     * Liefert einen manuell gepflegten KPI mit Wert aus tenant.settings.mris.manual_kpis[id].
     *
     * @return array{id: string, name: string, value: float|null, unit: string, source: string, computable: bool, mhc: string}
     */
    private function manualKpi(Tenant $tenant, string $id, string $name, string $unit, string $source, string $mhc): array
    {
        $settings = $tenant->getSettings() ?? [];
        $value = $settings['mris']['manual_kpis'][$id] ?? null;
        return [
            'id' => $id,
            'name' => $name,
            'value' => is_numeric($value) ? (float) $value : null,
            'unit' => $unit,
            'source' => $source,
            'computable' => false,
            'mhc' => $mhc,
        ];
    }

    /**
     * Persistiert manuelle KPI-Werte unter tenant.settings.mris.manual_kpis.
     *
     * @param array<string, float|int|string|null> $values keyed by KPI-ID
     */
    public function setManualKpis(Tenant $tenant, array $values): void
    {
        $settings = $tenant->getSettings() ?? [];
        $settings['mris'] ??= [];
        $existing = $settings['mris']['manual_kpis'] ?? [];

        foreach ($values as $id => $val) {
            if ($val === '' || $val === null) {
                unset($existing[$id]);
            } elseif (is_numeric($val)) {
                $existing[$id] = (float) $val;
            }
        }
        $settings['mris']['manual_kpis'] = $existing;
        $tenant->setSettings($settings);
        $this->entityManager->flush();
    }
}
