<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\TenantRepository;
use App\Service\Export\CertificationBundleExporter;
use DateTimeImmutable;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Async admin job: build the ISO 27001 (or multi-framework) certification
 * bundle ZIP for a given tenant and write it to var/exports/<jobId>.zip.
 *
 * Mirrors {@see \App\Controller\CertificationBundleController::export()} —
 * same multi-framework + as-of-date + locale-switching semantics. The sync
 * version produced a BinaryFileResponse from a temp file the
 * {@see CertificationBundleExporter} created; we redirect the resulting
 * tempfile into our own canonical exports dir so the download endpoint can
 * serve and delete it.
 *
 * Args:
 *   tenantId  (int)            REQUIRED — bundle scope.
 *   frameworks (list<string>)  Framework codes; defaults to ['ISO27001'].
 *   asOfDate  (?string Y-m-d)  Optional point-in-time freeze for the SoA snapshot.
 *   locale    (?string)        PDF locale (e.g. 'de', 'en'). Defaults to 'de'.
 *
 * Phase 3 of the async admin-jobs rollout.
 */
final class ExportCertificationBundleJob implements AsyncJobInterface
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
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $rawAsOf);
            if ($parsed instanceof DateTimeImmutable) {
                $asOfDate = $parsed->setTime(23, 59, 59);
            }
        }

        $locale = is_string($ctx->arg('locale')) ? (string) $ctx->arg('locale') : 'de';

        $ctx->message(sprintf(
            'Building certification bundle for tenant "%s" (frameworks: %s)…',
            (string) $tenant->getName(),
            implode(', ', $frameworks),
        ));
        $ctx->progress(1, 3, 'Generating PDFs + CSVs…');

        $result = $this->localeSwitcher->runWithLocale(
            $locale,
            fn() => $this->exporter->export($tenant, $frameworks, $asOfDate),
        );

        $ctx->progress(2, 3, 'Assembling ZIP…');

        $tempPath = (string) $result['path'];
        $finalPath = $this->path($ctx->getJobId());
        $this->ensureExportDir(dirname($finalPath));

        if (!@rename($tempPath, $finalPath)) {
            // Cross-filesystem fallback — copy + unlink the temp file
            if (!@copy($tempPath, $finalPath)) {
                // @intentional-assertion: disk write failure
                throw new \RuntimeException(sprintf('Failed to move bundle from "%s" to "%s".', $tempPath, $finalPath));
            }
            @unlink($tempPath);
        }

        $size = (int) (@filesize($finalPath) ?: 0);
        $documentCount = (int) ($result['document_count'] ?? 0);

        $ctx->progress(3, 3, sprintf(
            'Done. Wrote %s (%d documents, %d KB).',
            $result['filename'] ?? basename($finalPath),
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
