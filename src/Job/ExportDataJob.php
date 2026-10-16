<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\DataExport\EntityDataExporter;
use App\Service\Job\JobTenantScope;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Async admin job: dump selected Doctrine-mapped entity classes to a file
 * under var/exports/<jobId>.{csv|json}.
 *
 * Extracted from {@see \App\Controller\AdminBackupController::exportExecute()}
 * — the synchronous version returned a StreamedResponse that pegged PHP-FPM
 * for the lifetime of the download. On any non-trivial tenant tree (10+
 * entity classes, 10k+ rows total) this trips the 30 s timeout AND prevents
 * the admin user from doing anything else in another tab while the export
 * runs.
 *
 * Args:
 *   entities (list<class-string>) FQCNs to export.
 *   format   ('json'|'csv')       Output format. Default 'json'.
 *   + the tenant scope captured by {@see JobTenantScope::argsForCurrentUser()}.
 *
 * What may be exported, how rows are tenant-scoped and which fields are
 * stripped is decided by {@see EntityDataExporter} — shared with the sync
 * path so both apply the same rules. A job without a usable tenant scope
 * fails instead of exporting every organisation's data.
 *
 * The generated file is served by a separate download action that
 * unlinks the file after streaming.
 */
final class ExportDataJob implements AsyncJobInterface
{
    public function __construct(
        private readonly EntityDataExporter $exporter,
        private readonly JobTenantScope $tenantScope,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        /** @var list<string> $selectedEntities */
        $selectedEntities = (array) $ctx->arg('entities', []);
        $format = (string) $ctx->arg('format', 'json');
        if (!in_array($format, ['json', 'csv'], true)) {
            // @intentional-assertion: programmer error — invalid format slug
            throw new \RuntimeException(sprintf('Unsupported export format "%s".', $format));
        }

        $total = count($selectedEntities);
        if ($total === 0) {
            // @intentional-assertion: dispatcher must enforce non-empty selection
            throw new \RuntimeException('No entities selected for export.');
        }

        $scope = $this->tenantScope->resolve($ctx);

        $ctx->message(sprintf('Preparing %s export for %d entity class(es)…', strtoupper($format), $total));

        $exportData = $this->exporter->export(
            $selectedEntities,
            $scope,
            static function (int $done, int $all, string $shortName) use ($ctx): void {
                $ctx->progress($done, $all, sprintf('Loading %s…', $shortName));
            },
        );

        $ctx->message('Writing export file to disk…');
        $jobId = $ctx->getJobId();
        $path = $this->path($jobId, $format);
        $this->ensureExportDir(dirname($path));

        if ($format === 'json') {
            $this->writeJson($path, $exportData);
        } else {
            $this->writeCsv($path, $exportData);
        }

        $size = (int) (@filesize($path) ?: 0);
        $ctx->progress($total, $total, sprintf(
            'Done. Wrote %s (%d KB) — click Download to save.',
            basename($path),
            (int) round($size / 1024),
        ));
    }

    /**
     * @param array<string, list<array<string, mixed>>> $exportData
     */
    private function writeJson(string $path, array $exportData): void
    {
        $encoded = json_encode($exportData, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            // @intentional-assertion: payload must be JSON-encodable
            throw new \RuntimeException('Failed to encode export payload as JSON.');
        }
        file_put_contents($path, $encoded);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $exportData
     */
    private function writeCsv(string $path, array $exportData): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            // @intentional-assertion: disk write failure
            throw new \RuntimeException(sprintf('Failed to open export file "%s" for writing.', $path));
        }

        try {
            foreach ($exportData as $entityName => $rows) {
                if ($rows === []) {
                    continue;
                }

                fputcsv($handle, ['# ' . $entityName], escape: '\\');
                fputcsv($handle, array_keys($rows[0]), escape: '\\');

                foreach ($rows as $row) {
                    fputcsv($handle, array_map([EntityDataExporter::class, 'csvCell'], $row), escape: '\\');
                }

                fputcsv($handle, [], escape: '\\');
            }
        } finally {
            fclose($handle);
        }
    }

    private function ensureExportDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function path(string $jobId, string $format): string
    {
        return $this->kernel->getProjectDir() . '/var/exports/' . $jobId . '.' . $format;
    }
}
