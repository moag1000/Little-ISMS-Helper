<?php

declare(strict_types=1);

namespace App\Job;

use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async QuickFix repair: convenience chain of orphan-assign +
 * tenant-mismatch-fix + duplicate-merge for all supported types.
 *
 * Extracted from QuickFixController::repairAll() — the biggest QuickFix
 * operation, easily exceeding 30 s on a fresh deployment with thousands
 * of orphaned rows from a failed restore.
 *
 * Each step is audit-logged individually. Idempotent.
 */
final class QuickFixRepairAllJob implements AsyncJobInterface
{
    private const DUPLICATE_TYPES = ['audits', 'assets', 'risks', 'incidents', 'documents'];

    public function __construct(
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        $totalOrphans = 0;
        $totalMismatches = 0;
        $totalDuplicates = 0;
        $totalSkippedGlobal = 0;

        try {
            // Step 1 — Orphans
            $ctx->message('Step 1/3 — assigning orphans…');
            $tenantRepo = $this->entityManager->getRepository(Tenant::class);
            /** @var Tenant|null $targetTenant */
            $targetTenant = $tenantRepo->findOneBy([]);
            if ($targetTenant !== null) {
                $globalClasses = $this->dataIntegrityService->getGlobalCatalogueEntityClasses();
                $orphaned = $this->dataIntegrityService->findAllOrphanedEntities();

                foreach ($orphaned as $entities) {
                    foreach ($entities as $entity) {
                        if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId')) {
                            continue;
                        }
                        if (in_array($entity::class, $globalClasses, true)) {
                            $totalSkippedGlobal++;
                            continue;
                        }
                        try {
                            $entity->setTenant($targetTenant);
                            $this->auditLogger->logCustom(
                                'quick_fix.repair.orphan_assigned',
                                (new \ReflectionClass($entity))->getShortName(),
                                (int) $entity->getId(),
                                ['tenant_id' => null],
                                ['tenant_id' => $targetTenant->getId()],
                                sprintf(
                                    'QuickFix/all(async): orphan %s#%d → tenant %d',
                                    (new \ReflectionClass($entity))->getShortName(),
                                    (int) $entity->getId(),
                                    (int) $targetTenant->getId(),
                                ),
                            );
                            $totalOrphans++;
                        } catch (\Throwable) {
                            if ($this->entityManager->isOpen()) {
                                try {
                                    $this->entityManager->refresh($entity);
                                } catch (\Throwable) {
                                    // ignore — detached
                                }
                            }
                        }
                    }
                }
                if ($totalOrphans > 0 && $this->entityManager->isOpen()) {
                    try {
                        $this->entityManager->flush();
                    } catch (\Throwable $e) {
                        $ctx->message('Step 1 flush partial: ' . $e->getMessage());
                    }
                }
            }
            $ctx->progress(1, 3, sprintf('Step 1/3 done — %d orphan(s) assigned.', $totalOrphans));

            // Step 2 — Tenant mismatches
            $ctx->message('Step 2/3 — fixing tenant mismatches…');
            $broken = $this->dataIntegrityService->findBrokenReferences();
            foreach ($broken as $ref) {
                $entityClass = $ref['entity_class'] ?? null;
                $entityId = $ref['entity_id'] ?? null;
                $parentTenant = $ref['expected_tenant'] ?? null;
                if ($entityClass === null || $entityId === null || $parentTenant === null) {
                    continue;
                }
                $entity = $this->entityManager->find($entityClass, $entityId);
                if ($entity === null || !method_exists($entity, 'setTenant') || !method_exists($entity, 'getTenant')) {
                    continue;
                }
                $previousTenant = $entity->getTenant();
                $entity->setTenant($parentTenant);
                $this->auditLogger->logCustom(
                    'quick_fix.repair.tenant_mismatch_fixed',
                    (new \ReflectionClass($entity))->getShortName(),
                    (int) $entityId,
                    ['tenant_id' => $previousTenant?->getId()],
                    ['tenant_id' => $parentTenant->getId()],
                    sprintf(
                        'QuickFix/all(async): mismatch fix %s#%d',
                        (new \ReflectionClass($entity))->getShortName(),
                        (int) $entityId,
                    ),
                );
                $totalMismatches++;
            }
            $this->entityManager->flush();
            $ctx->progress(2, 3, sprintf('Step 2/3 done — %d mismatch(es) fixed.', $totalMismatches));

            // Step 3 — Duplicates
            $ctx->message('Step 3/3 — merging duplicates…');
            foreach (self::DUPLICATE_TYPES as $type) {
                $deleted = $this->dataIntegrityService->mergeDuplicates($type);
                $totalDuplicates += $deleted;
                if ($deleted > 0) {
                    $this->auditLogger->logCustom(
                        'quick_fix.repair.duplicates_merged',
                        $type,
                        0,
                        [],
                        ['deleted_count' => $deleted],
                        sprintf('QuickFix/all(async): merged %d duplicate(s) for %s', $deleted, $type),
                    );
                }
            }
            $ctx->progress(3, 3, sprintf(
                'Done. %d orphan(s), %d mismatch(es), %d duplicate(s)%s.',
                $totalOrphans,
                $totalMismatches,
                $totalDuplicates,
                $totalSkippedGlobal > 0 ? sprintf(', %d global rows skipped', $totalSkippedGlobal) : '',
            ));
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }
    }
}
