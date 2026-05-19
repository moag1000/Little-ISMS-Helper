<?php

declare(strict_types=1);

namespace App\Job;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\Export\ExportOptions;
use App\Service\PolicyWizard\Export\PolicyZipExporter;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Async admin job: build the Policy-Wizard tenant ZIP audit-pack and write
 * it to var/exports/<jobId>.zip.
 *
 * Mirrors {@see \App\Controller\PolicyExportController::exportTenantZip()}
 * — same ExportOptions (standards / includeArchived / includeEvidence) and
 * same audit-log entry (`AuditLogger::logExport`) so supervisory traceability
 * is preserved across sync↔async.
 *
 * Args:
 *   tenantId          (int)            REQUIRED — pack scope.
 *   standards         (list<string>)   Standards filter; defaults to
 *                                      ExportOptions::DEFAULT_STANDARDS.
 *   includeArchived   (bool)           Default false.
 *   includeEvidence   (bool)           Default true.
 *   exportedBy        (?string)        User identifier (email) for audit-log
 *                                      narrative — captured in dispatcher
 *                                      since worker has no Security scope.
 *
 * Phase 3 of the async admin-jobs rollout.
 */
final class ExportPolicyTenantZipJob implements AsyncJobInterface
{
    public function __construct(
        private readonly PolicyZipExporter $zipExporter,
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
        if (!$tenant instanceof Tenant) {
            // @intentional-assertion: dispatcher passed a valid tenantId
            throw new \RuntimeException(sprintf('Tenant %d not found.', $tenantId));
        }

        $standards = (array) $ctx->arg('standards', ExportOptions::DEFAULT_STANDARDS);
        $standards = array_values(array_filter(
            $standards,
            static fn(mixed $v): bool => is_string($v) && $v !== '',
        ));
        if ($standards === []) {
            $standards = ExportOptions::DEFAULT_STANDARDS;
        }

        $options = new ExportOptions(
            includeArchived: (bool) $ctx->arg('includeArchived', false),
            includeStandards: $standards,
            includeEvidence: (bool) $ctx->arg('includeEvidence', true),
        );

        $ctx->message(sprintf(
            'Building Policy-Wizard ZIP audit-pack for tenant "%s"…',
            (string) ($tenant->getName() ?? $tenant->getCode() ?? $tenant->getId() ?? ''),
        ));
        $ctx->progress(1, 2, 'Collecting documents + evidence…');

        $zip = $this->zipExporter->exportTenantPolicySet($tenant, $options);

        $path = $this->path($ctx->getJobId());
        $this->ensureExportDir(dirname($path));
        if (file_put_contents($path, $zip) === false) {
            // @intentional-assertion: disk write failure
            throw new \RuntimeException(sprintf('Failed to write policy ZIP to "%s".', $path));
        }

        // Preserve the sync route's audit-log entry — supervisory bodies
        // expect every audit-pack download to be traceable.
        $exportedBy = is_string($ctx->arg('exportedBy'))
            ? (string) $ctx->arg('exportedBy')
            : 'system';
        $this->auditLogger->logExport(
            'Tenant',
            $tenant->getId(),
            sprintf(
                'Policy-Wizard ZIP audit-pack export for tenant "%s" (standards: %s, archived: %s, evidence: %s) by %s (async)',
                (string) ($tenant->getName() ?? $tenant->getCode() ?? $tenant->getId() ?? ''),
                implode(',', $options->includeStandards),
                $options->includeArchived ? 'yes' : 'no',
                $options->includeEvidence ? 'yes' : 'no',
                $exportedBy,
            ),
        );

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
        return $this->kernel->getProjectDir() . '/var/exports/' . $jobId . '.zip';
    }
}
