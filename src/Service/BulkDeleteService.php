<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\TenantOwnership;
use Doctrine\ORM\EntityManagerInterface;
use App\Doctrine\EntityManagerRecovery;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Single implementation behind every list-view bulk-delete endpoint.
 *
 * Callers (via BulkActionTrait::handleBulkDelete) have already validated the
 * CSRF token and the route-level role. This service enforces, per entity:
 *   - existence (finder returns null → rejected),
 *   - tenant isolation (record scope: ROLE_SUPER_ADMIN everywhere, everyone
 *     else only the tenant it acts in per TenantContext — never subsidiaries;
 *     see TenantOwnership::isInContextScope()),
 *   - an optional voter attribute (entities with a dedicated voter),
 *   - an optional domain guard (e.g. inherited records are read-only),
 * then removes the accepted entities atomically and writes one
 * AuditLogger::logBulk() batch (ISO 27001 Cl. 7.5.3): 1 batch entry + N
 * per-entity entries correlated by batch_id.
 *
 * Atomicity: one explicit transaction. Domain deleters use their service's
 * no-flush path (`delete($e, flush: false)` — schedule the removal only), so
 * the removals are flushed together after every entity was accepted. A
 * lifecycle-based deleter (Document soft delete) applies its transition —
 * flush included — mid-batch; that flush also writes the removals scheduled
 * so far, still inside the transaction. The audit batch (AuditLogger::logBulk,
 * strict) is written after the batch flush and BEFORE the commit, so the
 * deletions and their trail commit together. Any failure — a delete, the
 * flush or an audit row — rolls the whole batch back, audit rows included,
 * clears the unit of work and resets a closed EntityManager.
 */
class BulkDeleteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly ?EntityManagerRecovery $entityManagerRecovery = null,
    ) {
    }

    /**
     * @param string                          $entityType     Short entity name for audit + error messages (e.g. "Risk").
     * @param list<int>                       $ids            Requested entity ids (already sanitised).
     * @param callable(int): ?object          $finder         Loads one entity by id.
     * @param (callable(object): void)|null   $deleter        Domain delete that does NOT flush (the service's
     *                                                        no-flush path); null → EntityManager::remove().
     * @param (callable(object): ?string)|null $guard         Returns a rejection reason, or null when deletable.
     * @param string|null                     $voterAttribute Voter attribute checked per entity (null = skip).
     * @param (callable(object): void)|null   $afterFlush     Side effect after the commit (e.g. unlink a
     *                                                        stored file).
     * @param (callable(object): array<string, mixed>)|null $auditValues Domain snapshot of the entity for its
     *                                                        per-entity audit row (e.g. reference number),
     *                                                        taken before the delete — the service's
     *                                                        deletionAuditValues().
     *
     * @return array{success: bool, deleted: int, errors: list<string>, message: string, batch_id: ?string}
     */
    public function delete(
        string $entityType,
        array $ids,
        callable $finder,
        ?callable $deleter = null,
        ?callable $guard = null,
        ?string $voterAttribute = null,
        ?callable $afterFlush = null,
        ?callable $auditValues = null,
    ): array {
        $errors = [];
        $removed = [];
        $perEntityData = [];
        $batchId = null;

        $this->entityManager->beginTransaction();
        try {
            foreach ($ids as $id) {
                $entity = $finder($id);
                if ($entity === null) {
                    $errors[] = sprintf('%s ID %d not found', $entityType, $id);
                    continue;
                }
                if (!$this->belongsToTenant($entity)) {
                    $errors[] = sprintf('%s ID %d does not belong to your organisation', $entityType, $id);
                    continue;
                }
                if ($voterAttribute !== null && !$this->authorizationChecker->isGranted($voterAttribute, $entity)) {
                    $errors[] = sprintf('%s ID %d: access denied', $entityType, $id);
                    continue;
                }
                $reason = $guard !== null ? $guard($entity) : null;
                if ($reason !== null) {
                    $errors[] = sprintf('%s ID %d: %s', $entityType, $id, $reason);
                    continue;
                }

                $label = $this->labelOf($entity);
                $oldValues = ($label !== null ? ['label' => $label] : [])
                    + ($auditValues !== null ? $auditValues($entity) : []);
                if ($deleter !== null) {
                    // Schedules the removal (no-flush path); a lifecycle
                    // transition flushes inside this transaction.
                    $deleter($entity);
                } else {
                    $this->entityManager->remove($entity);
                }
                $removed[] = $entity;
                $perEntityData[] = [
                    'action' => 'delete',
                    'entity_id' => $id,
                    'old_values' => $oldValues !== [] ? $oldValues : null,
                    'new_values' => ['deleted' => true],
                ];
            }
            if ($removed !== []) {
                $this->entityManager->flush();
                // Inside the transaction: the trail commits with the deletions,
                // and a row that cannot be written aborts the batch (strict).
                $batchId = $this->auditLogger->logBulk(
                    eventType: 'bulk_delete',
                    entityType: $entityType,
                    batchData: [
                        'requested' => count($ids),
                        'deleted' => count($removed),
                        'rejected' => count($errors),
                    ],
                    perEntityData: $perEntityData,
                    description: sprintf('Bulk delete of %d %s records', count($removed), $entityType),
                    strict: true,
                );
            }
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            // Atomic: undo everything — removals, mid-batch lifecycle flushes, audit rows.
            $this->rollbackQuietly();

            return $this->result(0, [sprintf('Error deleting %s records, nothing was deleted: %s', $entityType, $e->getMessage())], null);
        }

        if ($afterFlush !== null) {
            foreach ($removed as $entity) {
                try {
                    $afterFlush($entity);
                } catch (\Throwable $e) {
                    $errors[] = sprintf('%s post-delete cleanup failed: %s', $entityType, $e->getMessage());
                }
            }
        }

        return $this->result(count($removed), $errors, $batchId);
    }

    /**
     * @param list<string> $errors
     * @return array{success: bool, deleted: int, errors: list<string>, message: string, batch_id: ?string}
     */
    private function result(int $deleted, array $errors, ?string $batchId): array
    {
        return [
            'success' => $deleted > 0 || $errors === [],
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => $deleted > 0
                ? $this->translator->trans('bulk_delete.success', ['%count%' => $deleted], 'bulk_delete')
                : $this->translator->trans('bulk_delete.error', [], 'bulk_delete'),
            'batch_id' => $batchId,
        ];
    }

    private function rollbackQuietly(): void
    {
        try {
            $this->entityManager->rollback();
        } catch (\Throwable) {
            // connection already gone; nothing more to undo
        }
        try {
            // Drop scheduled removals of the rolled-back batch so a later flush cannot replay them.
            $this->entityManager->clear();
        } catch (\Throwable) {
            // best-effort: the caller reports the original failure; an unusable manager is recovered below
        }
        // A failed flush closes the EntityManager. The operation ends with an
        // error, but nothing later in the request may run on a closed — or
        // on a reset but unscoped — manager: recover tenant scope + context.
        if (!$this->entityManager->isOpen()) {
            $this->entityManagerRecovery?->recover();
        }
    }

    private function belongsToTenant(object $entity): bool
    {
        return TenantOwnership::isInContextScope(
            $entity,
            $this->tenantContext,
            fn (): bool => $this->authorizationChecker->isGranted('ROLE_SUPER_ADMIN'),
        );
    }

    private function labelOf(object $entity): ?string
    {
        foreach (['getName', 'getTitle', 'getReference'] as $getter) {
            if (method_exists($entity, $getter)) {
                $value = $entity->{$getter}();
                if (is_string($value) && $value !== '') {
                    return mb_substr($value, 0, 255);
                }
            }
        }

        return null;
    }
}
