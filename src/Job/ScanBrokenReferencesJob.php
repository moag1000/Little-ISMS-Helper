<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\DataIntegrityService;
use App\Service\SectionScanResultCache;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async admin job: scoped scan for broken foreign-key references
 * (and the related cascade-orphan + orphaned-upload sweeps that live
 * under the same `broken_references` umbrella in the UI).
 *
 * Persists a JSON-safe snapshot to {@see SectionScanResultCache} so the
 * `/admin/data-repair/broken-references` sub-page can render counts and
 * a small preview without re-running the scan on every visit.
 *
 * Args: none.
 *
 * Writes:
 *   var/data_integrity/broken_references.json via SectionScanResultCache
 */
final class ScanBrokenReferencesJob implements AsyncJobInterface
{
    private const PREVIEW_LIMIT_PER_BUCKET = 30;

    public function __construct(
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly SectionScanResultCache $cache,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $startedAt = microtime(true);

        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        try {
            $ctx->message('Scanning broken references…');
            $broken = $this->dataIntegrityService->findBrokenReferences();
            $ctx->progress(3, 10, 'Scanning cascade orphans…');
            $cascade = $this->dataIntegrityService->findCascadeOrphans();
            $ctx->progress(6, 10, 'Scanning orphaned uploads…');
            $uploads = $this->dataIntegrityService->findOrphanedUploads();

            $brokenCount = 0;
            $brokenByBucket = [];
            $brokenPreview = [];
            foreach ($broken as $bucket => $rows) {
                if (!is_array($rows)) {
                    continue;
                }
                $count = count($rows);
                if ($count === 0) {
                    continue;
                }
                $brokenCount += $count;
                $brokenByBucket[$bucket] = $count;
                $brokenPreview[$bucket] = array_slice($rows, 0, self::PREVIEW_LIMIT_PER_BUCKET);
            }

            $cascadeCount = 0;
            $cascadeByBucket = [];
            $cascadePreview = [];
            foreach ($cascade as $bucket => $rows) {
                if (!is_array($rows)) {
                    continue;
                }
                $count = count($rows);
                if ($count === 0) {
                    continue;
                }
                $cascadeCount += $count;
                $cascadeByBucket[$bucket] = $count;
                $cascadePreview[$bucket] = array_slice($rows, 0, self::PREVIEW_LIMIT_PER_BUCKET);
            }

            $uploadsFiles = is_array($uploads['files'] ?? null) ? $uploads['files'] : [];
            $uploadsCount = count($uploadsFiles);

            $ctx->progress(9, 10, 'Writing summary to disk…');
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->cache->write(
                SectionScanResultCache::SECTION_BROKEN_REFERENCES,
                [
                    'broken_total' => $brokenCount,
                    'broken_by_bucket' => $brokenByBucket,
                    'broken_preview' => $brokenPreview,
                    'cascade_total' => $cascadeCount,
                    'cascade_by_bucket' => $cascadeByBucket,
                    'cascade_preview' => $cascadePreview,
                    'uploads_total' => $uploadsCount,
                    'uploads_preview' => array_slice($uploadsFiles, 0, self::PREVIEW_LIMIT_PER_BUCKET),
                    'uploads_meta' => [
                        'scanned' => (int) ($uploads['scanned'] ?? 0),
                        'referenced' => (int) ($uploads['referenced'] ?? 0),
                        'uploads_dir' => is_string($uploads['uploads_dir'] ?? null) ? $uploads['uploads_dir'] : null,
                    ],
                    'preview_limit' => self::PREVIEW_LIMIT_PER_BUCKET,
                ],
                $durationMs,
            );

            $ctx->progress(10, 10, sprintf(
                'Done. %d broken ref(s), %d cascade orphan(s), %d orphaned upload(s).',
                $brokenCount,
                $cascadeCount,
                $uploadsCount,
            ));
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }
    }
}
