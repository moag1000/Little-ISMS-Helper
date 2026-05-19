<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\DataIntegrityResultCache;
use App\Service\DataIntegrityService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async admin job: run the full data-integrity scan and cache a scalar
 * summary to disk for the GET /admin/data-repair/ page to surface.
 *
 * Extracted from DataRepairController::index() to keep the index page
 * snappy on large tenant trees. The scan loads every Doctrine-mapped
 * entity that owns a tenant_id column plus all duplicate / broken-ref /
 * missing-relationship / inconsistent-data / file-orphan / cascade-orphan
 * / JSON-schema-violation / audit-log-integrity / status-enum-drift
 * checks — easily >30 s on multi-tenant deployments with 100k+ rows.
 *
 * Args: none.
 *
 * Writes:
 *   var/data_integrity/last.json (via {@see DataIntegrityResultCache})
 */
final class RunFullIntegrityCheckJob implements AsyncJobInterface
{
    public function __construct(
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly DataIntegrityResultCache $resultCache,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $startedAt = microtime(true);

        // The repair page intentionally bypasses the tenant_filter so that
        // cross-tenant orphans and mismatches surface. The async runner must
        // mirror that — otherwise the cached counts would diverge from what
        // the index page shows on a fallback inline scan.
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        try {
            $ctx->message('Running full data-integrity scan…');
            $ctx->progress(1, 10, 'Scanning orphaned entities…');
            $integrityCheck = $this->dataIntegrityService->runFullIntegrityCheck();

            $ctx->progress(7, 10, 'Computing summary statistics…');
            $summary = $this->dataIntegrityService->getSummaryStatistics();

            $ctx->progress(8, 10, 'Aggregating counts for cache…');
            $counts = $this->buildCounts($integrityCheck, $summary);

            $ctx->progress(9, 10, 'Writing summary to disk…');
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->resultCache->write($counts, $durationMs);

            $ctx->progress(10, 10, sprintf(
                'Done. %d orphan group(s), %d duplicate group(s), %d broken ref(s) — saved.',
                (int) ($counts['orphans_total'] ?? 0),
                (int) ($counts['duplicates_groups'] ?? 0),
                (int) ($counts['broken_references'] ?? 0),
            ));
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }
    }

    /**
     * Flatten the entity-laden integrity-check result into a scalar count map
     * the index page can render as KPI tiles without re-running the scan.
     *
     * @param array<string, mixed> $integrityCheck Output of runFullIntegrityCheck()
     * @param array<string, mixed> $summary        Output of getSummaryStatistics()
     *
     * @return array<string, int|string>
     */
    private function buildCounts(array $integrityCheck, array $summary): array
    {
        $orphansTotal = 0;
        $orphanedEntities = is_array($integrityCheck['orphaned_entities'] ?? null) ? $integrityCheck['orphaned_entities'] : [];
        foreach ($orphanedEntities as $bucket) {
            if (is_array($bucket) || $bucket instanceof \Countable) {
                $orphansTotal += count($bucket);
            }
        }

        $duplicatesGroups = 0;
        $duplicates = is_array($integrityCheck['duplicates'] ?? null) ? $integrityCheck['duplicates'] : [];
        foreach ($duplicates as $bucket) {
            if (is_array($bucket) || $bucket instanceof \Countable) {
                $duplicatesGroups += count($bucket);
            }
        }

        $brokenRefs = 0;
        $broken = is_array($integrityCheck['broken_references'] ?? null) ? $integrityCheck['broken_references'] : [];
        foreach ($broken as $bucket) {
            if (is_array($bucket) || $bucket instanceof \Countable) {
                $brokenRefs += count($bucket);
            }
        }

        $missingRels = 0;
        $missing = is_array($integrityCheck['missing_relationships'] ?? null) ? $integrityCheck['missing_relationships'] : [];
        foreach ($missing as $bucket) {
            if (is_array($bucket) || $bucket instanceof \Countable) {
                $missingRels += count($bucket);
            }
        }

        $inconsistent = 0;
        $inconsistentRoot = is_array($integrityCheck['inconsistent_data'] ?? null) ? $integrityCheck['inconsistent_data'] : [];
        foreach ($inconsistentRoot as $bucket) {
            if (is_array($bucket) || $bucket instanceof \Countable) {
                $inconsistent += count($bucket);
            }
        }

        $orphanedUploads = 0;
        $uploads = $integrityCheck['orphaned_uploads'] ?? null;
        if (is_array($uploads) && isset($uploads['files']) && (is_array($uploads['files']) || $uploads['files'] instanceof \Countable)) {
            $orphanedUploads = count($uploads['files']);
        }

        $cascadeOrphans = 0;
        $cascade = is_array($integrityCheck['cascade_orphans'] ?? null) ? $integrityCheck['cascade_orphans'] : [];
        foreach ($cascade as $bucket) {
            if (is_array($bucket) || $bucket instanceof \Countable) {
                $cascadeOrphans += count($bucket);
            }
        }

        $jsonSchemaViolations = 0;
        $jsv = is_array($integrityCheck['json_schema_violations'] ?? null) ? $integrityCheck['json_schema_violations'] : [];
        foreach ($jsv as $bucket) {
            if (is_array($bucket) || $bucket instanceof \Countable) {
                $jsonSchemaViolations += count($bucket);
            }
        }

        $statusEnumDrift = 0;
        $sed = is_array($integrityCheck['status_enum_drift'] ?? null) ? $integrityCheck['status_enum_drift'] : [];
        foreach ($sed as $bucket) {
            if (is_array($bucket) || $bucket instanceof \Countable) {
                $statusEnumDrift += count($bucket);
            }
        }

        return [
            'orphans_total' => $orphansTotal,
            'duplicates_groups' => $duplicatesGroups,
            'broken_references' => $brokenRefs,
            'missing_relationships' => $missingRels,
            'inconsistent_data' => $inconsistent,
            'orphaned_uploads' => $orphanedUploads,
            'cascade_orphans' => $cascadeOrphans,
            'json_schema_violations' => $jsonSchemaViolations,
            'status_enum_drift' => $statusEnumDrift,
            'health_score' => (int) ($summary['health_score'] ?? 0),
            'total_issues' => (int) ($summary['total_issues'] ?? 0),
        ];
    }
}
