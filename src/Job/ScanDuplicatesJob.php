<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\DataIntegrityService;
use App\Service\SectionScanResultCache;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async admin job: scoped scan that detects duplicate entities (per
 * entity type and same-tenant constraint) and persists a JSON-safe
 * snapshot for the `/admin/data-repair/duplicates` sub-page.
 *
 * Detection is delegated to
 * {@see DataIntegrityService::findDuplicateEntities()} which already
 * knows the per-entity matching rules (e.g. duplicate audits by
 * audit-number + tenant, duplicate documents by original-filename +
 * tenant, etc.). This job flattens the entity-graph output into
 * scalar previews so the cache file stays compact.
 *
 * Args: none.
 *
 * Writes:
 *   var/data_integrity/duplicates.json via SectionScanResultCache
 */
final class ScanDuplicatesJob implements AsyncJobInterface
{
    /** Cap duplicate groups previewed per type to keep cache file small. */
    private const PREVIEW_GROUPS_PER_TYPE = 25;

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
            $ctx->message('Scanning duplicate entities…');
            $duplicates = $this->dataIntegrityService->findDuplicateEntities();

            $groupsByType = [];
            $itemsByType = [];
            $previewByType = [];
            $totalGroups = 0;

            foreach ($duplicates as $type => $groups) {
                $groupCount = count($groups);
                if ($groupCount === 0) {
                    continue;
                }
                $groupsByType[$type] = $groupCount;
                $totalGroups += $groupCount;

                $itemCount = 0;
                $preview = [];
                $previewedGroups = 0;
                foreach ($groups as $grp) {
                    $entities = is_array($grp['entities'] ?? null) ? $grp['entities'] : (is_array($grp) ? $grp : []);
                    $itemCount += count($entities);

                    if ($previewedGroups < self::PREVIEW_GROUPS_PER_TYPE) {
                        $first = $entities[0] ?? null;
                        $label = is_object($first) ? $this->bestLabel($first) : (string) ($grp['value'] ?? '');
                        $preview[] = [
                            'label' => $label,
                            'count' => count($entities),
                        ];
                        $previewedGroups++;
                    }
                }
                $itemsByType[$type] = $itemCount;
                $previewByType[$type] = $preview;
            }

            $ctx->progress(8, 10, 'Writing summary to disk…');
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->cache->write(
                SectionScanResultCache::SECTION_DUPLICATES,
                [
                    'total_groups' => $totalGroups,
                    'groups_by_type' => $groupsByType,
                    'items_by_type' => $itemsByType,
                    'preview_by_type' => $previewByType,
                    'preview_limit' => self::PREVIEW_GROUPS_PER_TYPE,
                ],
                $durationMs,
            );

            $ctx->progress(10, 10, sprintf(
                'Done. %d duplicate group(s) across %d type(s).',
                $totalGroups,
                count($groupsByType),
            ));
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }
    }

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
