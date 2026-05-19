<?php

declare(strict_types=1);

namespace App\Job;

use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async QuickFix repair: assigns all orphaned entities (tenant IS NULL) to
 * the first available tenant. Idempotent — 0 orphans is a no-op.
 *
 * Extracted from QuickFixController::repairOrphans() so an emergency-recovery
 * UI doesn't have to fight PHP-FPM's 30 s timeout while a fresh tenant tree
 * is rebuilt.
 *
 * No args — the operator never picks the target tenant in QuickFix; the first
 * one available wins (single-tenant deployments) or the operator re-assigns
 * via the full admin/data-repair UI after the app is reachable again.
 *
 * Global catalogue entities (tenant_id=NULL by design) are skipped to avoid
 * UniqueConstraintViolationException on shared seed rows.
 */
final class QuickFixRepairOrphansJob implements AsyncJobInterface
{
    public function __construct(
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $ctx->message('Looking up default tenant…');

        $tenantRepo = $this->entityManager->getRepository(Tenant::class);
        /** @var Tenant|null $targetTenant */
        $targetTenant = $tenantRepo->findOneBy([]);

        if ($targetTenant === null) {
            $ctx->progress(0, 0, 'No tenant available — orphans cannot be assigned.');
            return;
        }

        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        $fixed = 0;
        $skippedGlobal = 0;

        try {
            $globalClasses = $this->dataIntegrityService->getGlobalCatalogueEntityClasses();
            $orphaned = $this->dataIntegrityService->findAllOrphanedEntities();

            // Count for progress
            $totalOrphans = 0;
            foreach ($orphaned as $entities) {
                $totalOrphans += count($entities);
            }

            if ($totalOrphans === 0) {
                $ctx->progress(0, 0, 'No orphaned entities found.');
                return;
            }

            $processed = 0;
            foreach ($orphaned as $type => $entities) {
                foreach ($entities as $entity) {
                    $processed++;

                    if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId')) {
                        continue;
                    }

                    // Skip global catalogue entities — assigning a tenant breaks
                    // unique constraints on shared seed rows.
                    if (in_array($entity::class, $globalClasses, true)) {
                        $skippedGlobal++;
                        continue;
                    }

                    try {
                        $entity->setTenant($targetTenant);
                        $this->auditLogger->logCustom(
                            'quick_fix.repair.orphan_assigned',
                            (new \ReflectionClass($entity))->getShortName(),
                            (int) $entity->getId(),
                            ['tenant_id' => null],
                            ['tenant_id' => $targetTenant->getId(), 'tenant_name' => $targetTenant->getName()],
                            sprintf(
                                'QuickFix(async): orphan %s#%d assigned to tenant %s',
                                (new \ReflectionClass($entity))->getShortName(),
                                (int) $entity->getId(),
                                $targetTenant->getName(),
                            ),
                        );
                        $fixed++;
                    } catch (\Throwable) {
                        if ($this->entityManager->isOpen()) {
                            try {
                                $this->entityManager->refresh($entity);
                            } catch (\Throwable) {
                                // ignore — entity may be detached
                            }
                        }
                    }

                    $ctx->progress(
                        $processed,
                        $totalOrphans,
                        sprintf('Fixed %d, skipped %d global rows so far…', $fixed, $skippedGlobal),
                    );
                }
            }

            if ($fixed > 0 && $this->entityManager->isOpen()) {
                try {
                    $this->entityManager->flush();
                } catch (\Throwable $e) {
                    $ctx->message('Flush partial: ' . $e->getMessage());
                }
            }
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }

        $ctx->progress(
            $fixed + $skippedGlobal,
            $fixed + $skippedGlobal,
            sprintf(
                'Done. %d orphan(s) assigned to "%s"%s.',
                $fixed,
                $targetTenant->getName(),
                $skippedGlobal > 0 ? sprintf(', %d global catalogue rows skipped', $skippedGlobal) : '',
            ),
        );
    }
}
