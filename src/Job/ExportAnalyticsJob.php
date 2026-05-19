<?php

declare(strict_types=1);

namespace App\Job;

use App\Entity\Risk;
use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\RiskRepository;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Async admin job: dump analytics dataset slices (risks / assets /
 * compliance controls) to a CSV file under var/exports/<jobId>.csv.
 *
 * Mirrors {@see \App\Controller\AnalyticsController::exportData()} — same
 * column layout, same CSV-injection sanitization. The original sync route
 * built the entire CSV in PHP memory and returned a Response (not even
 * streamed), which trips PHP-FPM on large tenant trees (10k+ risks).
 *
 * Args:
 *   type ('risks'|'assets'|'compliance') Dataset slice. Validated by both
 *                                        dispatcher and job. Default 'risks'.
 *
 * Phase 3 of the async admin-jobs rollout.
 */
final class ExportAnalyticsJob implements AsyncJobInterface
{
    private const ALLOWED_TYPES = ['risks', 'assets', 'compliance'];

    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly AssetRepository $assetRepository,
        private readonly ControlRepository $controlRepository,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $type = (string) $ctx->arg('type', 'risks');
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            // @intentional-assertion: dispatcher must whitelist type
            throw new \RuntimeException(sprintf('Unsupported analytics export type "%s".', $type));
        }

        $ctx->message(sprintf('Loading %s dataset…', $type));
        $rows = match ($type) {
            'risks' => $this->buildRiskRows(),
            'assets' => $this->buildAssetRows(),
            'compliance' => $this->buildComplianceRows(),
        };

        $total = max(count($rows) - 1, 0); // exclude header for progress display
        $ctx->progress(0, max($total, 1), sprintf('Writing %d row(s) to CSV…', $total));

        $path = $this->path($ctx->getJobId());
        $this->ensureExportDir(dirname($path));

        $handle = fopen($path, 'w');
        if ($handle === false) {
            // @intentional-assertion: disk write failure
            throw new \RuntimeException(sprintf('Failed to open export file "%s" for writing.', $path));
        }

        try {
            foreach ($rows as $i => $row) {
                fputcsv($handle, array_map([$this, 'sanitizeCsvValue'], $row), escape: '\\');
                if ($i > 0 && ($i % 100 === 0 || $i === count($rows) - 1)) {
                    $ctx->progress($i, max($total, 1), sprintf('Wrote %d / %d row(s)…', $i, $total));
                }
            }
        } finally {
            fclose($handle);
        }

        $size = (int) (@filesize($path) ?: 0);
        $ctx->progress($total, max($total, 1), sprintf(
            'Done. Wrote %d %s row(s) → %s (%d KB).',
            $total,
            $type,
            basename($path),
            (int) round($size / 1024),
        ));
    }

    /**
     * @return list<list<mixed>>
     */
    private function buildRiskRows(): array
    {
        $risks = $this->riskRepository->findAll();
        $data = [
            ['ID', 'Title', 'Probability', 'Impact', 'Risk Level', 'Status', 'Created At'],
        ];
        foreach ($risks as $risk) {
            assert($risk instanceof Risk);
            $data[] = [
                $risk->getId(),
                $risk->getTitle(),
                $risk->getProbability(),
                $risk->getImpact(),
                $risk->getInherentRiskLevel(),
                $risk->getStatus()?->value,
                $risk->getCreatedAt()?->format('Y-m-d'),
            ];
        }
        return $data;
    }

    /**
     * @return list<list<mixed>>
     */
    private function buildAssetRows(): array
    {
        $assets = $this->assetRepository->findAll();
        $data = [
            ['ID', 'Name', 'Type', 'Criticality', 'Owner', 'Created At'],
        ];
        foreach ($assets as $asset) {
            $data[] = [
                $asset->getId(),
                $asset->getName(),
                $asset->getAssetType(),
                $asset->getConfidentialityValue() . '/' . $asset->getIntegrityValue() . '/' . $asset->getAvailabilityValue(),
                $asset->getOwner() ?? 'N/A',
                $asset->getCreatedAt()?->format('Y-m-d'),
            ];
        }
        return $data;
    }

    /**
     * @return list<list<mixed>>
     */
    private function buildComplianceRows(): array
    {
        $controls = $this->controlRepository->findAll();
        $data = [
            ['Control ID', 'Name', 'Status', 'Implementation Date'],
        ];
        foreach ($controls as $control) {
            $data[] = [
                $control->getControlId(),
                $control->getName(),
                $control->getImplementationStatus(),
                $control->getLastReviewDate() ? $control->getLastReviewDate()->format('Y-m-d') : 'N/A',
            ];
        }
        return $data;
    }

    /**
     * Sanitize a CSV cell value to prevent formula injection (OWASP - Injection).
     * Mirrors AnalyticsController::sanitizeCsvValue().
     */
    private function sanitizeCsvValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
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
