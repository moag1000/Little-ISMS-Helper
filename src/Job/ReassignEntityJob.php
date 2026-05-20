<?php

declare(strict_types=1);

namespace App\Job;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use App\Service\SectionScanResultCache;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async admin job: reassign a single entity to a different tenant.
 *
 * Extracted from {@see \App\Controller\Admin\DataRepairController::reassignEntity()}.
 * The synchronous path is cheap per-row (one UPDATE + one audit-log flush)
 * but on slow shared-hosting MySQL hosts an audit-log flush can sit on
 * disk-fsync for several seconds, and the controller redirect-with-flash
 * pattern means the operator still sees a 30 s spinner until PHP-FPM
 * comes back. Lifting the work into a job keeps the UI responsive and
 * unifies the post-repair cache-refresh logic with {@see AssignOrphansJob}.
 *
 * Args:
 *   - entityType (string) — URL slug like "asset" / "risk" / "control"
 *   - entityId (int)      — primary key
 *   - tenantId (int)      — new tenant ID
 *   - userId (int|null)   — caller's user-id for audit-log identity
 */
final class ReassignEntityJob implements AsyncJobInterface
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly UserRepository $userRepository,
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly AuditLogger $auditLogger,
        private readonly SectionScanResultCache $sectionScanCache,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $entityType = (string) ($ctx->arg('entityType') ?? '');
        $entityId = (int) ($ctx->arg('entityId') ?? 0);
        $tenantId = (int) ($ctx->arg('tenantId') ?? 0);
        $userId = $ctx->arg('userId');
        $actorName = $this->resolveActorName($userId);

        if ($entityType === '' || $entityId <= 0 || $tenantId <= 0) {
            // @intentional-assertion: programmer error — required job args missing
            throw new \RuntimeException(sprintf(
                'ReassignEntityJob requires entityType, entityId, tenantId; got "%s" / %d / %d.',
                $entityType,
                $entityId,
                $tenantId,
            ));
        }

        $ctx->message('Resolving target tenant…');
        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            // @intentional-assertion: job arg references nonexistent tenant
            throw new \RuntimeException(sprintf('Target tenant #%d not found.', $tenantId));
        }

        $fqcn = $this->resolveEntityClassForType($entityType);
        if ($fqcn === null) {
            // @intentional-assertion: programmer / bad-input — unknown entity-type slug
            throw new \RuntimeException(sprintf(
                'No Doctrine-mapped entity matches the URL slug "%s".',
                $entityType,
            ));
        }

        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        try {
            $ctx->message(sprintf('Loading %s#%d…', $entityType, $entityId));
            $entity = $this->entityManager->find($fqcn, $entityId);
            if ($entity === null) {
                // @intentional-assertion: entity missing — likely deleted between submit and dispatch
                throw new \RuntimeException(sprintf(
                    '%s#%d not found (it may have been deleted between page load and dispatch).',
                    $entityType,
                    $entityId,
                ));
            }

            if (!method_exists($entity, 'setTenant')) {
                // @intentional-assertion: programmer error — entity not multi-tenant aware
                throw new \RuntimeException(sprintf(
                    'Entity %s does not implement setTenant() — cannot reassign.',
                    $fqcn,
                ));
            }

            $previousTenant = method_exists($entity, 'getTenant') ? $entity->getTenant() : null;
            $previousTenantId = $previousTenant instanceof Tenant ? $previousTenant->getId() : null;

            $entityName = $this->bestLabel($entity);
            $className = (new \ReflectionClass($entity))->getShortName();

            $entity->setTenant($tenant);
            $this->auditLogger->logCustom(
                'admin.data_repair.entity_reassigned',
                $className,
                $entityId,
                ['tenant_id' => $previousTenantId],
                ['tenant_id' => $tenant->getId(), 'tenant_name' => $tenant->getName()],
                sprintf(
                    '%s#%d "%s" reassigned to tenant %s (async job)',
                    $className,
                    $entityId,
                    $entityName,
                    $tenant->getName(),
                ),
                $actorName,
            );
            // logCustom flushes — but we still call flush() to ensure the
            // setTenant() change persists alongside the audit row in the same
            // commit.
            if ($this->entityManager->isOpen()) {
                $this->entityManager->flush();
            }

            $ctx->progress(1, 1, sprintf(
                '%s "%s" assigned to tenant "%s".',
                $className,
                $entityName,
                $tenant->getName(),
            ));
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }

        // Refresh the orphan cache: the reassigned entity may have been an
        // orphan, so the index-page count should drop by one.
        $this->refreshOrphanCache();
    }

    /**
     * Re-run the orphan scan and persist a fresh snapshot. Best-effort — a
     * failure here doesn't undo the reassignment, the operator can still
     * click "Refresh now" on the orphan sub-page.
     */
    private function refreshOrphanCache(): void
    {
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
            // Best-effort: ignore cache-refresh failure.
        }
    }

    private function resolveEntityClassForType(string $type): ?string
    {
        $slug = strtolower(str_replace('_', '', $type));
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }
            $short = strtolower((new \ReflectionClass($metadata->getName()))->getShortName());
            if ($short === $slug || $short . 's' === $slug || $short === rtrim($slug, 's')) {
                return $metadata->getName();
            }
        }
        return null;
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
