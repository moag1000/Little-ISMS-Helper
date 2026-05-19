<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\TenantRepository;
use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Async admin job: assign all orphaned entities to a target tenant.
 *
 * Extracted from DataRepairController::fixAllOrphans() to run in a
 * Symfony Messenger worker process, avoiding PHP-FPM 30s timeout.
 *
 * Args (passed via ExecuteJobMessage::$args, accessible via JobContext::arg()):
 *   tenantId (int) — ID of the target tenant
 *
 * Logic:
 *   - Blocks in multi-tenant deployments (count > 1) — same guard as controller
 *   - Disables the tenant_filter Doctrine filter to find tenant=NULL rows
 *   - Iterates all orphan categories from DataIntegrityService
 *   - Per-entity: setTenant() + AuditLogger::logCustom() + flush()
 *   - EM-reset on constraint violation (same resilience as the controller)
 *   - Reports progress via JobContext::progress()
 */
final class FixAllOrphansJob implements AsyncJobInterface
{
    public function __construct(
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly TenantRepository $tenantRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $ctx->message('Checking tenant count…');

        // Guard: block bulk reassign in multi-tenant deployments
        $tenantCount = (int) $this->tenantRepository->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($tenantCount > 1) {
            // @intentional-assertion: multi-tenant safety guard (job invariant)
            throw new \RuntimeException(sprintf(
                'Bulk orphan reassign is blocked: %d tenants exist. Use per-entity routes for multi-tenant deployments.',
                $tenantCount,
            ));
        }

        $tenantId = $ctx->arg('tenantId');
        if ($tenantId === null) {
            // @intentional-assertion: programmer error — required job arg missing
            throw new \RuntimeException('Missing required arg "tenantId" in FixAllOrphansJob.');
        }

        $tenant = $this->tenantRepository->find((int) $tenantId);
        if ($tenant === null) {
            // @intentional-assertion: job arg references nonexistent tenant
            throw new \RuntimeException(sprintf('Target tenant #%d not found.', $tenantId));
        }

        $ctx->message(sprintf('Scanning orphaned entities for tenant "%s"…', $tenant->getName()));

        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        $totalFixed = 0;
        $totalSkipped = 0;

        try {
            $orphaned = $this->dataIntegrityService->findAllOrphanedEntities();

            // Count total for progress reporting
            $totalOrphans = 0;
            foreach ($orphaned as $entities) {
                $totalOrphans += count($entities);
            }

            if ($totalOrphans === 0) {
                $ctx->progress(0, 0, 'No orphaned entities found.');
                return;
            }

            $processed = 0;
            foreach ($orphaned as $className => $entities) {
                foreach ($entities as $entity) {
                    if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId')) {
                        $processed++;
                        continue;
                    }

                    // CLAUDE.md Common-Pitfalls #1: EM-reset on constraint violation
                    if (!$this->entityManager->isOpen()) {
                        $this->managerRegistry->resetManager();
                        /** @var EntityManagerInterface $freshEm */
                        $freshEm = $this->managerRegistry->getManager();
                        // Re-fetch tenant via the (possibly reset) EM
                        $tenant = $this->tenantRepository->find($tenant->getId());
                        if ($tenant === null) {
                            break 2;
                        }
                    }

                    try {
                        $entity->setTenant($tenant);
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
                        );
                        $this->entityManager->flush();
                        $totalFixed++;
                    } catch (\Throwable) {
                        $totalSkipped++;
                    }

                    $processed++;
                    $ctx->progress($processed, $totalOrphans, sprintf(
                        'Fixed %d / skipped %d — processing %s…',
                        $totalFixed,
                        $totalSkipped,
                        $className,
                    ));
                }
            }
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }

        $ctx->progress(
            $totalFixed + $totalSkipped,
            $totalFixed + $totalSkipped,
            sprintf('Done. Fixed: %d, Skipped: %d, Tenant: %s', $totalFixed, $totalSkipped, $tenant->getName()),
        );
    }
}
