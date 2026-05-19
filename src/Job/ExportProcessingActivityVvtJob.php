<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\ProcessingActivityService;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Async admin job: dump the GDPR Verzeichnis von Verarbeitungstätigkeiten
 * (VVT / Records of Processing Activities, GDPR Art. 30) to a CSV file
 * under var/exports/<jobId>.csv.
 *
 * Mirrors {@see \App\Controller\ProcessingActivityController::exportCsv()}
 * — same column layout (17 columns), same CSV-injection sanitization.
 *
 * Module-gate (privacy) is enforced by the dispatching controller, not
 * the job — once a job is queued, the worker has no HTTP request scope
 * to re-check.
 *
 * Phase 3 of the async admin-jobs rollout.
 */
final class ExportProcessingActivityVvtJob implements AsyncJobInterface
{
    public function __construct(
        private readonly ProcessingActivityService $processingActivityService,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $ctx->message('Loading VVT dataset…');
        $exportData = $this->processingActivityService->generateVVTExport();
        /** @var list<array<string, mixed>> $activities */
        $activities = $exportData['processing_activities'] ?? [];
        $total = count($activities);

        $ctx->progress(0, max($total, 1), sprintf('Building VVT CSV for %d activity record(s)…', $total));

        $path = $this->path($ctx->getJobId());
        $this->ensureExportDir(dirname($path));

        $handle = fopen($path, 'w');
        if ($handle === false) {
            // @intentional-assertion: disk write failure
            throw new \RuntimeException(sprintf('Failed to open export file "%s" for writing.', $path));
        }

        try {
            $header = [
                'ID',
                'Name',
                'Description',
                'Purposes',
                'Data Subjects',
                'Personal Data Categories',
                'Special Categories',
                'Recipients',
                'Third Country Transfer',
                'Retention Period',
                'Legal Basis',
                'TOMs',
                'Status',
                'Risk Level',
                'DPIA Required',
                'DPIA Completed',
                'Completeness %',
            ];
            fputcsv($handle, $header, escape: '\\');

            foreach ($activities as $i => $pa) {
                $row = [
                    $pa['id'] ?? '',
                    $pa['name'] ?? '',
                    $pa['description'] ?? '',
                    implode(', ', (array) ($pa['purposes'] ?? [])),
                    implode(', ', (array) ($pa['data_subject_categories'] ?? [])),
                    implode(', ', (array) ($pa['personal_data_categories'] ?? [])),
                    !empty($pa['processes_special_categories']) ? 'Yes' : 'No',
                    implode(', ', (array) ($pa['recipient_categories'] ?? [])),
                    !empty($pa['has_third_country_transfer']) ? 'Yes' : 'No',
                    $pa['retention_period'] ?? '',
                    $pa['legal_basis'] ?? '',
                    substr((string) ($pa['technical_organizational_measures'] ?? ''), 0, 100),
                    $pa['status'] ?? '',
                    $pa['risk_level'] ?? '',
                    !empty($pa['requires_dpia']) ? 'Yes' : 'No',
                    !empty($pa['dpia_completed']) ? 'Yes' : 'No',
                    ($pa['completeness_percentage'] ?? '') . '%',
                ];
                fputcsv($handle, array_map([$this, 'sanitizeCsvValue'], $row), escape: '\\');

                if (($i + 1) % 50 === 0 || $i + 1 === $total) {
                    $ctx->progress($i + 1, max($total, 1), sprintf('Wrote %d / %d activity row(s)…', $i + 1, $total));
                }
            }
        } finally {
            fclose($handle);
        }

        $size = (int) (@filesize($path) ?: 0);
        $ctx->progress($total, max($total, 1), sprintf(
            'Done. Wrote %d processing activity record(s) → %s (%d KB).',
            $total,
            basename($path),
            (int) round($size / 1024),
        ));
    }

    /**
     * Sanitize a CSV cell value to prevent formula injection (OWASP - Injection).
     * Mirrors ProcessingActivityController::sanitizeCsvValue().
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
