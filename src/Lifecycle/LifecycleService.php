<?php

declare(strict_types=1);

namespace App\Lifecycle;

use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestrator for entity status transitions (audit-s3 foundation
 * pattern P-4).
 *
 * Validates the requested transition against {@see LifecycleRegistry},
 * applies the new status via the entity's `setStatus()` setter, flushes,
 * and emits an `AuditLogger::logCustom()` entry of action
 * `status_change` so the audit-trail satisfies ISO 27001 Cl. 7.5.3.
 *
 * Entity contract:
 *  - Must implement `getStatus(): ?string` and `setStatus(string): void`
 *    (or compatible signatures). Reflection-fallback callers should use
 *    {@see InvalidTransitionException} to surface mismatches.
 *  - The entity must already exist in Doctrine's UnitOfWork
 *    (i.e. `flush()` here applies the status change in-place).
 */
final class LifecycleService
{
    public function __construct(
        private readonly LifecycleRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Transition an entity's status with validation + audit logging.
     *
     * @param object      $entity        Entity instance with getStatus/setStatus
     * @param string      $targetStatus  Desired new status key
     * @param User|null   $user          Acting user (optional — for log enrichment)
     * @param string|null $reason        Optional human-readable rationale
     *
     * @throws InvalidTransitionException when the transition is not allowed
     * @throws \LogicException            when the entity lacks getStatus/setStatus
     */
    public function transition(
        object $entity,
        string $targetStatus,
        ?User $user = null,
        ?string $reason = null,
    ): void {
        if (!method_exists($entity, 'getStatus') || !method_exists($entity, 'setStatus')) {
            throw new \LogicException(sprintf(
                'Entity %s lacks getStatus()/setStatus() and cannot be lifecycle-managed.',
                $entity::class,
            ));
        }

        $current = (string) $entity->getStatus();
        $entityClass = $entity::class;

        if (!$this->registry->isValidTransition($entityClass, $current, $targetStatus)) {
            $allowed = $this->registry->getAllowedTransitions($entityClass, $current);
            throw new InvalidTransitionException(
                message: sprintf(
                    'Invalid lifecycle transition for %s: %s → %s. Allowed: %s.',
                    $entityClass,
                    $current,
                    $targetStatus,
                    $allowed === [] ? '<none>' : implode(', ', $allowed),
                ),
                entityClass: $entityClass,
                fromStatus: $current,
                toStatus: $targetStatus,
                allowedTransitions: $allowed,
            );
        }

        $entity->setStatus($targetStatus);
        $this->entityManager->flush();

        $entityId = null;
        if (method_exists($entity, 'getId')) {
            $rawId = $entity->getId();
            if (is_int($rawId)) {
                $entityId = $rawId;
            } elseif (is_string($rawId) && ctype_digit($rawId)) {
                $entityId = (int) $rawId;
            }
        }

        $this->auditLogger->logCustom(
            action: 'status_change',
            entityType: $this->auditLogger->getEntityTypeName($entity),
            entityId: $entityId,
            oldValues: ['status' => $current],
            newValues: [
                'status' => $targetStatus,
                'reason' => $reason,
            ],
            description: sprintf(
                'Lifecycle transition: %s → %s%s',
                $current,
                $targetStatus,
                $reason !== null && $reason !== '' ? ' (' . $reason . ')' : '',
            ),
            userName: $user?->getEmail(),
        );
    }
}
