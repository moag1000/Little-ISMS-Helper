<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\DataIntegrityService;
use App\Service\SectionScanResultCache;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async admin job: scope-scoped scan that lists orphaned entities
 * (tenant_id IS NULL) per detected entity type and persists the result
 * to {@see SectionScanResultCache} so the
 * `/admin/data-repair/orphans` sub-page can render from cache.
 *
 * Why not call the full {@see DataIntegrityService::runFullIntegrityCheck()}:
 *  - Each sub-page only needs its own slice → cheaper refresh.
 *  - A user clicking "Refresh now" on orphans must NOT force a full
 *    duplicate/broken-ref/health scan that they may not be looking at.
 *
 * The job MUST disable the Doctrine `tenant_filter` so cross-tenant
 * orphans surface — the index page intentionally bypasses the filter
 * for the same reason (see DataRepairController::withoutTenantFilter()).
 *
 * Args: none.
 *
 * Writes:
 *   var/data_integrity/orphans.json via SectionScanResultCache
 */
final class ScanOrphansJob implements AsyncJobInterface
{
    /** Cap preview rows so the JSON file stays small even on huge tenants. */
    private const PREVIEW_LIMIT_PER_TYPE = 50;

    public function __construct(
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly SectionScanResultCache $cache,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $startedAt = microtime(true);

        // Same tenant-filter dance as the controller — cross-tenant orphans
        // would otherwise be hidden by the per-request tenant_id WHERE clause.
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        try {
            $ctx->message('Scanning orphaned entities…');
            $orphaned = $this->dataIntegrityService->findAllOrphanedEntities();

            $countsByType = [];
            $previewByType = [];
            $total = 0;

            foreach ($orphaned as $type => $entities) {
                $count = count($entities);
                if ($count === 0) {
                    continue;
                }
                $countsByType[$type] = $count;
                $total += $count;

                $preview = [];
                $i = 0;
                foreach ($entities as $entity) {
                    if ($i >= self::PREVIEW_LIMIT_PER_TYPE) {
                        break;
                    }
                    $i++;
                    $preview[] = [
                        'id' => method_exists($entity, 'getId') ? (int) $entity->getId() : null,
                        'label' => $this->bestLabel($entity),
                    ];
                }
                $previewByType[$type] = $preview;
            }

            $ctx->progress(8, 10, 'Writing summary to disk…');
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->cache->write(
                SectionScanResultCache::SECTION_ORPHANS,
                [
                    'total' => $total,
                    'counts_by_type' => $countsByType,
                    'preview_by_type' => $previewByType,
                    'preview_limit' => self::PREVIEW_LIMIT_PER_TYPE,
                ],
                $durationMs,
            );

            $ctx->progress(10, 10, sprintf(
                'Done. %d orphaned entity(s) across %d type(s).',
                $total,
                count($countsByType),
            ));
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }
    }

    /** @return string Short human-readable label for preview rendering. */
    private function bestLabel(object $entity): string
    {
        if (method_exists($entity, 'getTitle')) {
            $v = (string) $entity->getTitle();
            if ($v !== '') {
                return $v;
            }
        }
        if (method_exists($entity, 'getName')) {
            $v = (string) $entity->getName();
            if ($v !== '') {
                return $v;
            }
        }
        if (method_exists($entity, 'getId')) {
            return '#' . (string) $entity->getId();
        }
        return (new \ReflectionClass($entity))->getShortName();
    }
}
