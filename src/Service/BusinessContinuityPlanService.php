<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BusinessContinuityPlan;
use App\Repository\BusinessContinuityPlanRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * BusinessContinuityPlan Service — persistence + business logic for BC-Plan entities.
 *
 * The controller keeps routing, form-handling, flash messages, redirects, and
 * module-gating. All EntityManager writes live here (audit gate: em_writes_in_controller).
 */
final class BusinessContinuityPlanService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BusinessContinuityPlanRepository $repository,
        private readonly ?AuditLogger $auditLogger = null,
    ) {}

    /**
     * Persist a new BC-Plan and flush.
     */
    public function create(BusinessContinuityPlan $plan): void
    {
        $this->entityManager->persist($plan);
        $this->entityManager->flush();
    }

    /**
     * Stamp updatedAt and flush changes to an existing BC-Plan.
     */
    public function update(BusinessContinuityPlan $plan): void
    {
        $plan->setUpdatedAt(new DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * Flush a cloned BC-Plan (already persisted by the Cloner) and write audit-log.
     * The $original is used only for audit-log context.
     */
    public function persistClone(BusinessContinuityPlan $clone, BusinessContinuityPlan $original): void
    {
        $this->entityManager->flush();

        $this->auditLogger?->logCreate(
            entityType: 'BusinessContinuityPlan',
            entityId: $clone->getId(),
            newValues: ['cloned_from_id' => $original->getId(), 'name' => $clone->getName()],
            description: 'Cloned from BC-Plan #' . $original->getId(),
        );
    }

    /**
     * Remove a single BC-Plan and flush.
     */
    public function delete(BusinessContinuityPlan $plan): void
    {
        $this->entityManager->remove($plan);
        $this->entityManager->flush();
    }

    /**
     * Bulk-delete BC-Plans belonging to the current tenant.
     *
     * @param int[]    $ids
     * @param int|null $currentTenantId  Pass null to skip tenant check (ADMIN bypass).
     * @return array{deleted: int, errors: string[]}
     */
    public function bulkDelete(array $ids, ?int $currentTenantId): array
    {
        $deleted = 0;
        $errors  = [];

        foreach ($ids as $id) {
            try {
                $plan = $this->repository->find($id);
                if ($plan === null) {
                    $errors[] = "BC Plan ID $id not found";
                    continue;
                }
                if ($currentTenantId !== null && $plan->getTenant()?->getId() !== $currentTenantId) {
                    $errors[] = "BC Plan ID $id does not belong to your organization";
                    continue;
                }
                $this->entityManager->remove($plan);
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = "Error deleting BC Plan ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }
}
