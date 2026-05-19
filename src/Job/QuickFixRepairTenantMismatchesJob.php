<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async QuickFix repair: aligns child entity tenants with their parent
 * (Asset) for every cross-entity mismatch reported by DataIntegrityService.
 *
 * Idempotent — each fix is audit-logged. No reason is required in the
 * QuickFix flow (the operator is invoked from a broken-app emergency UI;
 * a reason gate would block recovery).
 */
final class QuickFixRepairTenantMismatchesJob implements AsyncJobInterface
{
    public function __construct(
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $ctx->message('Scanning broken references…');

        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        $fixed = 0;

        try {
            $broken = $this->dataIntegrityService->findBrokenReferences();
            $total = count($broken);

            if ($total === 0) {
                $ctx->progress(0, 0, 'No tenant mismatches found.');
                return;
            }

            $processed = 0;
            foreach ($broken as $ref) {
                $processed++;

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
                    ['tenant_id' => $parentTenant->getId(), 'tenant_name' => $parentTenant->getName()],
                    sprintf(
                        'QuickFix(async): %s#%d tenant aligned to parent (tenant %d → %d)',
                        (new \ReflectionClass($entity))->getShortName(),
                        (int) $entityId,
                        (int) ($previousTenant?->getId() ?? 0),
                        (int) $parentTenant->getId(),
                    ),
                );
                $fixed++;

                $ctx->progress($processed, $total, sprintf('Fixed %d so far…', $fixed));
            }

            if ($this->entityManager->isOpen()) {
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
            $fixed,
            $fixed,
            sprintf('Done. %d tenant mismatch(es) resolved.', $fixed),
        );
    }
}
