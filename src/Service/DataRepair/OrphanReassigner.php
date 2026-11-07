<?php

declare(strict_types=1);

namespace App\Service\DataRepair;

use App\Doctrine\EntityManagerRecovery;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Re-assigns ONE orphaned entity (tenant = NULL) to a tenant — the shared
 * step of AssignOrphansJob, FixAllOrphansJob and
 * DataRepairController::fixAllOrphans.
 *
 * The change and its audit row are one unit: setTenant() → flush → the
 * `admin.data_repair.orphan_reassigned` audit row (strict) → commit, in one
 * transaction. If anything fails, both are rolled back and the entity counts
 * as skipped — the trail never claims a reassignment that was not stored
 * (ISO 27001 Cl. 7.5.3). A flush that closed the EntityManager is recovered
 * (EntityManagerRecovery). After a recovery the callers' entities belong to
 * the dead unit of work, so the entity and the tenant are re-loaded into the
 * current one first; an entity that is no longer an orphan is skipped. A
 * failed rollback (deadlock, lock-wait timeout) never leaves a transaction
 * level behind, and a reassigned entity is detached so large orphan sets do
 * not slow every later flush down.
 */
final readonly class OrphanReassigner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private ?EntityManagerRecovery $entityManagerRecovery = null,
    ) {
    }

    /**
     * @return bool true when the reassignment and its audit row were committed
     */
    public function reassign(object $entity, Tenant $tenant, ?string $actorName = null, string $descriptionSuffix = ''): bool
    {
        if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId') || !method_exists($entity, 'getTenant')) {
            return false;
        }
        $em = $this->entityManager;
        if (!$em->isOpen()) {
            $this->entityManagerRecovery?->recover();
        }

        $class = $em->getClassMetadata($entity::class)->getName();
        $id = $entity->getId();
        if (!$em->contains($entity)) {
            $entity = $id !== null ? $em->find($class, $id) : null;
            if ($entity === null || !method_exists($entity, 'setTenant') || $entity->getTenant() !== null) {
                return false; // gone, or no longer an orphan
            }
        }
        if (!$em->contains($tenant)) {
            $tenant = $tenant->getId() !== null ? $em->find(Tenant::class, $tenant->getId()) : null;
            if (!$tenant instanceof Tenant) {
                return false;
            }
        }

        $shortName = (new \ReflectionClass($class))->getShortName();
        $outerLevel = $em->getConnection()->getTransactionNestingLevel();
        $em->beginTransaction();
        try {
            $entity->setTenant($tenant);
            $em->flush();
            // Only for a change that was actually flushed; inside the same
            // transaction, so the change and its trail commit together.
            $this->auditLogger->log(
                'admin.data_repair.orphan_reassigned',
                $shortName,
                (int) $entity->getId(),
                ['tenant_id' => null],
                ['tenant_id' => $tenant->getId(), 'tenant_name' => $tenant->getName()],
                sprintf('Orphan %s#%d reassigned to tenant %s%s', $shortName, (int) $entity->getId(), $tenant->getName(), $descriptionSuffix),
                $actorName,
                strict: true,
            );
            $em->commit();
            // Done with it: a job reassigning thousands of orphans would otherwise
            // make every later flush re-check every entity it already handled.
            $em->detach($entity);

            return true;
        } catch (\Throwable) {
            $this->rollBackTo($outerLevel);
            if (!$em->isOpen()) {
                $this->entityManagerRecovery?->recover();
            } elseif ($em->contains($entity)) {
                $em->detach($entity); // drop the rolled-back change from the unit of work
            }

            return false;
        }
    }

    /**
     * Leave no transaction open behind a failed reassignment. A deadlock or
     * lock-wait timeout makes the server roll the whole transaction back; the
     * rollback call can then fail and Doctrine's nesting level stays above
     * the caller's, so every later beginTransaction() of the job would only
     * open a savepoint in a transaction that no longer exists. Roll back to
     * the level the caller had; if that fails, close the connection (resets
     * the level, DBAL reconnects on the next query).
     */
    private function rollBackTo(int $outerLevel): void
    {
        $connection = $this->entityManager->getConnection();
        try {
            while ($connection->getTransactionNestingLevel() > $outerLevel) {
                $connection->rollBack();
            }
        } catch (\Throwable) {
            $connection->close();
        }
    }
}
