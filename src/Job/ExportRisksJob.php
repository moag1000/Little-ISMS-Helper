<?php

declare(strict_types=1);

namespace App\Job;

use App\Entity\Risk;
use App\Entity\User;
use App\Repository\RiskRepository;
use App\Risk\RiskMatrixThresholds;
use App\Service\Job\JobTenantScope;
use App\Service\RiskService;
use Symfony\Component\HttpKernel\KernelInterface;
use App\Util\CsvSanitizer;

/**
 * Async admin job: write the filtered risk register to a UTF-8 CSV file
 * under var/exports/<jobId>.csv.
 *
 * Mirrors {@see \App\Controller\RiskController::export()} — same filter
 * semantics (level / status / treatment / owner), same column layout
 * (German headers, semicolon delimiter, BOM for Excel UTF-8 support).
 *
 * Args:
 *   tenantId / allTenants  Tenant scope captured by
 *                         {@see JobTenantScope::argsForCurrentUser()}. A job
 *                         without scope (or with an unknown tenant) fails —
 *                         it never falls back to exporting every tenant's
 *                         risks. allTenants is only set for a SUPER_ADMIN
 *                         without an organisation (mirrors the sync export).
 *   level       (?string) RiskMatrixThresholds classification filter.
 *   status      (?string) Status enum value filter.
 *   treatment   (?string) TreatmentStrategy enum value filter.
 *   owner       (?string) Substring match against owner full-name.
 *
 * The file is served by RiskController::exportDownload() which streams it
 * and removes it from disk afterwards.
 */
final class ExportRisksJob implements AsyncJobInterface
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly RiskService $riskService,
        private readonly JobTenantScope $tenantScope,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $tenant = $this->tenantScope->resolve($ctx);
        $level = $ctx->arg('level');
        $statusFilter = $ctx->arg('status');
        $treatment = $ctx->arg('treatment');
        $owner = $ctx->arg('owner');

        $ctx->message($tenant !== null
            ? sprintf('Loading risks for tenant "%s"…', $tenant->getName())
            : 'Loading risks of all tenants (instance-wide SUPER_ADMIN export)…');

        // null tenant = explicit instance-wide export (SUPER_ADMIN without tenant only)
        $risks = $tenant !== null
            ? $this->riskService->getRisksForTenant($tenant)
            : $this->riskRepository->findBy([]);

        if (is_string($level) && $level !== '') {
            $risks = array_filter(
                $risks,
                static fn(Risk $risk): bool => RiskMatrixThresholds::classify($risk->getRiskScore()) === $level,
            );
        }
        if (is_string($statusFilter) && $statusFilter !== '') {
            $risks = array_filter(
                $risks,
                static fn(Risk $risk): bool => $risk->getStatus()?->value === $statusFilter,
            );
        }
        if (is_string($treatment) && $treatment !== '') {
            $risks = array_filter(
                $risks,
                static fn(Risk $risk): bool => $risk->getTreatmentStrategy()?->value === $treatment,
            );
        }
        if (is_string($owner) && $owner !== '') {
            $risks = array_filter(
                $risks,
                static fn(Risk $risk): bool => $risk->getRiskOwner() instanceof User
                    && stripos($risk->getRiskOwner()->getFullName(), $owner) !== false,
            );
        }
        $risks = array_values($risks);

        $total = count($risks);
        $ctx->progress(0, max($total, 1), sprintf('Building CSV for %d risk(s)…', $total));

        $treatmentMap = [
            'accept' => 'Akzeptieren',
            'mitigate' => 'Mindern',
            'transfer' => 'Übertragen',
            'avoid' => 'Vermeiden',
        ];
        $statusMap = [
            'identified' => 'Identifiziert',
            'assessed' => 'Bewertet',
            'in_treatment' => 'In Behandlung',
            'treated' => 'Behandelt',
            'mitigated' => 'Mitigiert',
            'monitored' => 'Überwacht',
            'closed' => 'Geschlossen',
            'accepted' => 'Akzeptiert',
            'open' => 'Offen',
        ];

        $path = $this->path($ctx->getJobId());
        $this->ensureExportDir(dirname($path));

        $handle = fopen($path, 'w');
        if ($handle === false) {
            // @intentional-assertion: disk write failure
            throw new \RuntimeException(sprintf('Failed to open export file "%s" for writing.', $path));
        }

        try {
            // BOM for Excel UTF-8 support — preserves the original
            // RiskController::export() behaviour.
            fwrite($handle, "\xEF\xBB\xBF");

            // Header row — German headers, identical to the sync version
            $header = [
                'ID',
                'Titel',
                'Beschreibung',
                'Bedrohung',
                'Schwachstelle',
                'Asset',
                'Wahrscheinlichkeit',
                'Auswirkung',
                'Risiko-Score',
                'Risikolevel',
                'Rest-Wahrscheinlichkeit',
                'Rest-Auswirkung',
                'Rest-Risiko-Score',
                'Rest-Risikolevel',
                'Behandlungsstrategie',
                'Status',
                'Risikoinhaber',
                'Erstellt am',
                'Überprüfungsdatum',
            ];
            fputcsv($handle, array_map([CsvSanitizer::class, 'sanitize'], $header), ';', escape: '\\');

            foreach ($risks as $i => $risk) {
                $riskScore = $risk->getRiskScore();
                $residualScore = $risk->getResidualRiskLevel();

                $riskLevel = match (true) {
                    $riskScore >= 15 => 'Kritisch',
                    $riskScore >= 8 => 'Hoch',
                    $riskScore >= 4 => 'Mittel',
                    default => 'Niedrig',
                };
                $residualRiskLevel = match (true) {
                    $residualScore >= 15 => 'Kritisch',
                    $residualScore >= 8 => 'Hoch',
                    $residualScore >= 4 => 'Mittel',
                    default => 'Niedrig',
                };

                $treatmentValue = $risk->getTreatmentStrategy()?->value;
                $statusValue = $risk->getStatus()?->value;

                $row = [
                    $risk->getId(),
                    $risk->getTitle(),
                    $risk->getDescription(),
                    $risk->getThreat() ?? '-',
                    $risk->getVulnerability() ?? '-',
                    $risk->getAsset() ? $risk->getAsset()->getName() : '-',
                    $risk->getProbability(),
                    $risk->getImpact(),
                    $riskScore,
                    $riskLevel,
                    $risk->getResidualProbability(),
                    $risk->getResidualImpact(),
                    $residualScore,
                    $residualRiskLevel,
                    $treatmentValue !== null ? ($treatmentMap[$treatmentValue] ?? $treatmentValue) : '',
                    $statusValue !== null ? ($statusMap[$statusValue] ?? $statusValue) : '',
                    $risk->getRiskOwner() ? $risk->getRiskOwner()->getFullName() : '-',
                    $risk->getCreatedAt() ? $risk->getCreatedAt()->format('Y-m-d H:i') : '-',
                    $risk->getReviewDate() ? $risk->getReviewDate()->format('Y-m-d') : '-',
                ];

                fputcsv($handle, array_map([CsvSanitizer::class, 'sanitize'], $row), ';', escape: '\\');

                if (($i + 1) % 50 === 0 || $i + 1 === $total) {
                    $ctx->progress($i + 1, max($total, 1), sprintf('Wrote %d / %d row(s)…', $i + 1, $total));
                }
            }
        } finally {
            fclose($handle);
        }

        $size = (int) (@filesize($path) ?: 0);
        $ctx->progress($total, max($total, 1), sprintf(
            'Done. Wrote %d risk(s) → %s (%d KB).',
            $total,
            basename($path),
            (int) round($size / 1024),
        ));
    }

    private function ensureExportDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function path(string $jobId): string
    {
        return $this->kernel->getProjectDir() . '/var/exports/' . $jobId . '.csv';
    }
}
