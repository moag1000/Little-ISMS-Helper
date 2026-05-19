<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\TenantRepository;
use App\Service\Export\CertificationBundleExporter;
use DateTimeImmutable;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Async admin job: build the holding-level (Konzern) certification bundle
 * ZIP for a holding tenant + selected subsidiaries, written to
 * var/exports/<jobId>.zip.
 *
 * Mirrors {@see \App\Controller\CertificationBundleController::konzernExport()}
 * — same multi-framework + as-of-date + per-subsidiary-whitelist semantics.
 *
 * Args:
 *   tenantId        (int)            REQUIRED — holding-tenant scope.
 *   frameworks      (list<string>)   Framework codes; defaults to ['ISO27001'].
 *   asOfDate        (?string Y-m-d)  Optional point-in-time freeze.
 *   subsidiaryIds   (?list<int>)     Optional whitelist of subsidiary IDs;
 *                                    null = all subsidiaries.
 *   locale          (?string)        PDF locale (e.g. 'de', 'en'). Default 'de'.
 *
 * Phase 3 of the async admin-jobs rollout.
 */
final class ExportKonzernCertificationBundleJob implements AsyncJobInterface
{
    public function __construct(
        private readonly CertificationBundleExporter $exporter,
        private readonly TenantRepository $tenantRepository,
        private readonly LocaleSwitcher $localeSwitcher,
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

        if ($tenant->getSubsidiaries()->count() === 0) {
            // @intentional-assertion: mirrors sync controller's not_a_holding guard
            throw new \RuntimeException('Tenant is not a holding (no subsidiaries).');
        }

        $frameworks = (array) $ctx->arg('frameworks', ['ISO27001']);
        $frameworks = array_values(array_filter(
            $frameworks,
            static fn(mixed $v): bool => is_string($v) && $v !== '',
        ));
        if ($frameworks === []) {
            $frameworks = ['ISO27001'];
        }

        $asOfDate = null;
        $rawAsOf = $ctx->arg('asOfDate');
        if (is_string($rawAsOf) && $rawAsOf !== '') {
            try {
                $asOfDate = new DateTimeImmutable($rawAsOf);
            } catch (\Throwable) {
                $asOfDate = null;
            }
        }

        $includeOnly = null;
        $rawSubsidiaryIds = $ctx->arg('subsidiaryIds');
        if (is_array($rawSubsidiaryIds) && $rawSubsidiaryIds !== []) {
            $includeOnly = [];
            foreach ($rawSubsidiaryIds as $id) {
                $intId = (int) $id;
                if ($intId > 0) {
                    $includeOnly[] = $intId;
                }
            }
            if ($includeOnly === []) {
                $includeOnly = null;
            }
        }

        $locale = is_string($ctx->arg('locale')) ? (string) $ctx->arg('locale') : 'de';

        $ctx->message(sprintf(
            'Building Konzern certification bundle for holding "%s" (frameworks: %s)…',
            (string) $tenant->getName(),
            implode(', ', $frameworks),
        ));
        $ctx->progress(1, 3, 'Aggregating per-subsidiary bundles…');

        $result = $this->localeSwitcher->runWithLocale(
            $locale,
            fn() => $this->exporter->exportKonzern($tenant, $frameworks, $asOfDate, $includeOnly),
        );

        $ctx->progress(2, 3, 'Assembling Konzern ZIP…');

        $tempPath = (string) $result['path'];
        $finalPath = $this->path($ctx->getJobId());
        $this->ensureExportDir(dirname($finalPath));

        if (!@rename($tempPath, $finalPath)) {
            if (!@copy($tempPath, $finalPath)) {
                // @intentional-assertion: disk write failure
                throw new \RuntimeException(sprintf('Failed to move bundle from "%s" to "%s".', $tempPath, $finalPath));
            }
            @unlink($tempPath);
        }

        $size = (int) (@filesize($finalPath) ?: 0);
        $documentCount = (int) ($result['document_count'] ?? 0);
        $subsidiaryCount = (int) ($result['subsidiary_count'] ?? 0);

        $ctx->progress(3, 3, sprintf(
            'Done. Wrote %s (%d subsidiaries, %d documents, %d KB).',
            $result['filename'] ?? basename($finalPath),
            $subsidiaryCount,
            $documentCount,
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
