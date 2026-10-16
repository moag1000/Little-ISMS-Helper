<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BusinessContinuityPlan;
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
}
