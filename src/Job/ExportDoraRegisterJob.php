<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\TenantRepository;
use App\Service\AuditLogger;
use App\Service\Export\DoraRegisterOfInformationExporter;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Async admin job: build the EBA/EIOPA/ESMA Final-Draft-ITS Register of
 * Information (DORA Art. 28) CSV for the given tenant and write it to
 * var/exports/<jobId>.csv.
 *
 * Mirrors {@see \App\Controller\DoraRegisterExportController::export()} —
 * same conformant CSV layout, same audit-log entry (`AuditLogger::logExport`)
 * so supervisory traceability is preserved across sync↔async.
 *
 * Args:
 *   tenantId (int) REQUIRED — register scope.
 *
 * Phase 3 of the async admin-jobs rollout.
 */
final class ExportDoraRegisterJob implements AsyncJobInterface
{
    public function __construct(
        private readonly DoraRegisterOfInformationExporter $exporter,
        private readonly TenantRepository $tenantRepository,
        private readonly AuditLogger $auditLogger,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $tenantId = (int) $ctx->arg('tenantId', 0);
        if ($tenantId <= 0) {
            // @intentional-assertion: dispatcher must inject tenantId
            throw new \RuntimeException('Missing tenantId argument.');
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            // @intentional-assertion: dispatcher passed a valid tenantId
            throw new \RuntimeException(sprintf('Tenant %d not found.', $tenantId));
        }

        $ctx->message(sprintf(
            'Building DORA Register of Information CSV for tenant "%s"…',
            (string) $tenant->getName(),
        ));
        $ctx->progress(1, 2, 'Aggregating ICT third-party / outsourcing arrangements…');

        $csv = $this->exporter->export($tenant);

        // Preserve the sync route's audit-log entry — supervisory bodies
        // expect every ROI download to be traceable.
        $this->auditLogger->logExport(
            'DoraRegisterOfInformation',
            null,
            'DORA Register of Information CSV export (async)',
        );

        $path = $this->path($ctx->getJobId());
        $this->ensureExportDir(dirname($path));
        if (file_put_contents($path, $csv) === false) {
            // @intentional-assertion: disk write failure
            throw new \RuntimeException(sprintf('Failed to write DORA register CSV to "%s".', $path));
        }

        $size = (int) (@filesize($path) ?: 0);
        $ctx->progress(2, 2, sprintf(
            'Done. Wrote %s (%d KB).',
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
