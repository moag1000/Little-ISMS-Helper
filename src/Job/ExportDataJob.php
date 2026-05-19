<?php

declare(strict_types=1);

namespace App\Job;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
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
 *   entities (list<class-string>) FQCNs to export. The dispatching controller
 *                                 must already enforce the App\Entity namespace
 *                                 prefix — the job re-checks defensively but
 *                                 does not surface "skipped" entries to the UI.
 *   format   ('json'|'csv')       Output format. Default 'json'.
 *
 * The generated file is served by a separate download action that
 * unlinks the file after streaming.
 */
final class ExportDataJob implements AsyncJobInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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

        $ctx->message(sprintf('Preparing %s export for %d entity class(es)…', strtoupper($format), $total));

        $exportData = [];
        $processed = 0;

        foreach ($selectedEntities as $fqcn) {
            $processed++;

            $fqcn = (string) $fqcn;
            if (!str_starts_with($fqcn, 'App\\Entity\\')) {
                $ctx->progress($processed, $total, sprintf('Skipped %s (not an App\\Entity class).', $fqcn));
                continue;
            }

            try {
                $repository = $this->entityManager->getRepository($fqcn);
            } catch (\Throwable) {
                $ctx->progress($processed, $total, sprintf('Skipped %s (no repository).', $fqcn));
                continue;
            }

            $shortName = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
            $ctx->progress($processed, $total, sprintf('Loading %s…', $shortName));

            try {
                $rows = $repository->findAll();
            } catch (\Throwable $e) {
                $ctx->progress($processed, $total, sprintf('Skipped %s (%s).', $shortName, $e->getMessage()));
                continue;
            }

            $exportData[$shortName] = [];
            foreach ($rows as $entity) {
                $exportData[$shortName][] = $this->entityToArray($entity);
            }
        }

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
     * Convert an entity to a flat assoc array. Mirrors the original
     * AdminBackupController::entityToArray() behaviour: scalar fields only,
     * DateTime → 'Y-m-d H:i:s' string. Associations are skipped.
     *
     * @return array<string, mixed>
     */
    private function entityToArray(object $entity): array
    {
        $meta = $this->entityManager->getClassMetadata($entity::class);
        $row = [];

        foreach ($meta->getFieldNames() as $field) {
            try {
                $value = $meta->getFieldValue($entity, $field);
                if ($value instanceof DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s');
                }
                $row[$field] = $value;
            } catch (\Throwable) {
                // Skip inaccessible fields silently — matches the sync behaviour.
            }
        }

        return $row;
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
                    fputcsv($handle, array_map([$this, 'sanitizeCsvValue'], $row), escape: '\\');
                }

                fputcsv($handle, [], escape: '\\');
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Sanitize a CSV cell value to prevent formula injection (OWASP - Injection).
     * Mirrors AdminBackupController::sanitizeCsvValue().
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

    private function path(string $jobId, string $format): string
    {
        return $this->kernel->getProjectDir() . '/var/exports/' . $jobId . '.' . $format;
    }
}
