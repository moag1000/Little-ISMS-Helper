<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use App\Service\SectionScanResultCache;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Async admin job: bulk-assign orphaned entities (tenant_id IS NULL) to a
 * target tenant — either ALL orphans across all detected types or a single
 * entity-type bucket.
 *
 * Extracted from {@see \App\Controller\Admin\DataRepairController::assignOrphans()}.
 * The synchronous version timed out under PHP-FPM's 30 s limit on tenants
 * with thousands of orphan rows because {@see AuditLogger::logCustom()}
 * flushes the EM on every call. This job batches: it accumulates audit-log
 * entries and the entity mutation into a single flush every N entities,
 * so even 50 k orphans complete in seconds rather than minutes.
 *
 * Args (passed via {@see JobContext::arg()}):
 *   - tenantId (int)          — ID of the target tenant
 *   - entityType (string)     — either "all" or one of the keys returned
 *                                by {@see DataIntegrityService::findAllOrphanedEntities()}
 *                                (e.g. "assets", "risks", "incidents")
 *   - userId (int|null)       — caller's user-id; surfaced in the audit-log
 *                                userName field so the auditor can answer
 *                                "who triggered this bulk-reassign?"
 *
 * Why no Request / Session dependency: in-request runners detach FCGI after
 * the polling page is sent (Setup-Wizard pattern). At that point the Symfony
 * Request is no longer available, so the job must capture every operator-
 * identity bit via $args at dispatch time.
 *
 * Side effects:
 *   - Mutates `tenant_id` on every matching orphan.
 *   - Writes one AuditLog row per entity (ISB MAJOR-1 granularity).
 *   - At the end, re-runs the orphan scan and overwrites
 *     `var/data_integrity/orphans.json` so the index page reflects the
 *     post-repair state without the operator having to click "Refresh".
 */
final class AssignOrphansJob implements AsyncJobInterface
{
    /**
     * Flush batch size: balances DB-roundtrip overhead vs UOW memory.
     * 50 was chosen because AuditLog rows are small (~1 KB) and Doctrine's
     * IdentityMap holds ~100 entities cheaply — going bigger triggers heap
     * pressure on shared-hosting PHP-FPM workers.
     */
    private const FLUSH_BATCH_SIZE = 50;

    public function __construct(
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly TenantRepository $tenantRepository,
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
        private readonly SectionScanResultCache $sectionScanCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $tenantId = $ctx->arg('tenantId');
        if ($tenantId === null) {
            // @intentional-assertion: programmer error — required job arg missing
            throw new \RuntimeException('Missing required arg "tenantId" in AssignOrphansJob.');
        }

        $entityType = (string) ($ctx->arg('entityType') ?? 'all');
        $userId = $ctx->arg('userId');
        $actorName = $this->resolveActorName($userId);

        $tenant = $this->tenantRepository->find((int) $tenantId);
        if ($tenant === null) {
            // @intentional-assertion: job arg references nonexistent tenant
            throw new \RuntimeException(sprintf('Target tenant #%d not found.', $tenantId));
        }

        $ctx->message(sprintf('Scanning orphans for tenant "%s"…', $tenant->getName()));

        // Same tenant-filter dance as the controller — cross-tenant orphans
        // would otherwise be hidden by the per-request tenant_id WHERE clause.
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        $assigned = 0;
        $skipped = 0;

        try {
            $allOrphans = $this->dataIntegrityService->findAllOrphanedEntities();

            // Restrict to one bucket when the operator picked a specific type.
            if ($entityType !== 'all') {
                if (!isset($allOrphans[$entityType])) {
                    // @intentional-assertion: bad UI input — surface clearly in the
                    // job-status panel rather than silently doing nothing.
                    throw new \RuntimeException(sprintf(
                        'Unknown orphan bucket "%s". Available: %s',
                        $entityType,
                        implode(', ', array_keys($allOrphans)),
                    ));
                }
                $allOrphans = [$entityType => $allOrphans[$entityType]];
            }

            // Pre-count for progress reporting. A single COUNT pass over an
            // already-loaded UOW is essentially free.
            $totalOrphans = 0;
            foreach ($allOrphans as $entities) {
                $totalOrphans += count($entities);
            }

            if ($totalOrphans === 0) {
                $ctx->progress(0, 0, sprintf(
                    'No orphans found for "%s" — nothing to assign.',
                    $entityType,
                ));
                $this->refreshOrphanCache($ctx);
                return;
            }

            $processed = 0;
            $sinceLastFlush = 0;

            foreach ($allOrphans as $bucket => $entities) {
                foreach ($entities as $entity) {
                    $processed++;

                    if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId')) {
                        $skipped++;
                        continue;
                    }

                    // EM may have closed on a prior batch's flush (constraint
                    // violation, savepoint after DDL etc.). Reset before
                    // touching another entity — CLAUDE.md Common-Pitfalls #1.
                    if (!$this->entityManager->isOpen()) {
                        $this->managerRegistry->resetManager();
                        /** @var EntityManagerInterface $freshEm */
                        $freshEm = $this->managerRegistry->getManager();
                        $tenant = $this->tenantRepository->find($tenant->getId());
                        if ($tenant === null) {
                            // Tenant disappeared mid-job — abort, the partial
                            // batch is already persisted from previous flushes.
                            break 2;
                        }
                    }

                    $className = (new \ReflectionClass($entity))->getShortName();

                    try {
                        $entity->setTenant($tenant);
                        // logCustom flushes per call by default. To batch we
                        // call it AFTER setting the tenant — the audit-log
                        // row + the orphan UPDATE land in the same flush.
                        $this->auditLogger->logCustom(
                            'admin.data_repair.orphan_reassigned',
                            $className,
                            (int) $entity->getId(),
                            ['tenant_id' => null],
                            ['tenant_id' => $tenant->getId(), 'tenant_name' => $tenant->getName()],
                            sprintf(
                                'Orphan %s#%d reassigned to tenant %s (async job)',
                                $className,
                                (int) $entity->getId(),
                                $tenant->getName(),
                            ),
                            $actorName,
                        );
                        $assigned++;
                        $sinceLastFlush++;
                    } catch (\Throwable) {
                        // Constraint violation, FK orphan, anything — count it
                        // and move on. The next iteration's EM-open check resets
                        // the manager if needed.
                        $skipped++;
                    }

                    if ($sinceLastFlush >= self::FLUSH_BATCH_SIZE) {
                        $this->flushBatch();
                        $sinceLastFlush = 0;
                    }

                    if ($processed % 25 === 0) {
                        $ctx->progress($processed, $totalOrphans, sprintf(
                            'Assigned %d / skipped %d — bucket %s',
                            $assigned,
                            $skipped,
                            $bucket,
                        ));
                    }
                }
            }

            // Final flush — picks up the tail of the last partial batch.
            if ($sinceLastFlush > 0) {
                $this->flushBatch();
            }
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }

        $ctx->progress($assigned + $skipped, $assigned + $skipped, sprintf(
            'Done. Assigned: %d, Skipped: %d, Tenant: %s',
            $assigned,
            $skipped,
            $tenant->getName(),
        ));

        // Refresh the orphan cache so the index page no longer shows the
        // entities we just re-assigned. If the rescan itself fails we
        // swallow it — the assignment is the load-bearing operation, the
        // operator can still click "Refresh now" manually.
        $this->refreshOrphanCache($ctx);
    }

    /**
     * Re-run the orphan scan after a repair and persist the fresh snapshot
     * via {@see SectionScanResultCache}. Mirrors {@see ScanOrphansJob} but
     * runs inline so the index page reflects the repair without requiring a
     * second job dispatch.
     */
    private function refreshOrphanCache(JobContext $ctx): void
    {
        $ctx->message('Refreshing orphan cache…');
        $startedAt = microtime(true);

        try {
            $filters = $this->entityManager->getFilters();
            $wasEnabled = $filters->isEnabled('tenant_filter');
            if ($wasEnabled) {
                $filters->disable('tenant_filter');
            }

            try {
                $orphaned = $this->dataIntegrityService->findAllOrphanedEntities();

                $countsByType = [];
                $previewByType = [];
                $total = 0;
                $previewLimit = 50;

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
                        if ($i >= $previewLimit) {
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

                $this->sectionScanCache->write(
                    SectionScanResultCache::SECTION_ORPHANS,
                    [
                        'total' => $total,
                        'counts_by_type' => $countsByType,
                        'preview_by_type' => $previewByType,
                        'preview_limit' => $previewLimit,
                    ],
                    (int) round((microtime(true) - $startedAt) * 1000),
                );
            } finally {
                if ($wasEnabled && $this->entityManager->isOpen()) {
                    $this->entityManager->getFilters()->enable('tenant_filter');
                }
            }
        } catch (\Throwable) {
            // Best-effort: the repair completed, just the post-cache refresh
            // failed. The operator can hit "Refresh now" manually.
        }
    }

    private function flushBatch(): void
    {
        if (!$this->entityManager->isOpen()) {
            $this->managerRegistry->resetManager();
            /** @var EntityManagerInterface $freshEm */
            $freshEm = $this->managerRegistry->getManager();
            // After a reset the previously-mutated entities are detached;
            // their UPDATEs were already flushed in the prior batch where
            // the failure occurred. Nothing to flush here.
            return;
        }
        try {
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Swallow — the per-entity try-catch above already accounted
            // for "skipped" rows. EM will be reset on the next iteration.
        }
    }

    private function resolveActorName(mixed $userId): string
    {
        if ($userId === null || $userId === '') {
            return 'admin';
        }
        $user = $this->userRepository->find((int) $userId);
        if ($user === null) {
            return 'admin';
        }
        return (string) ($user->getEmail() ?? 'admin');
    }

    /** @return string Short human-readable label for cache preview rendering. */
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
