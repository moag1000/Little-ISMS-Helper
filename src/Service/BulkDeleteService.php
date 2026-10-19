<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\TenantOwnership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Single implementation behind every list-view bulk-delete endpoint.
 *
 * Callers (via BulkActionTrait::handleBulkDelete) have already validated the
 * CSRF token and the route-level role. This service enforces, per entity:
 *   - existence (finder returns null → rejected),
 *   - tenant isolation (entity must belong to the tenant the caller acts in
 *     per TenantContext, or a descendant; no tenant context = nothing unless
 *     ROLE_SUPER_ADMIN — see TenantOwnership::isInContextScope()),
 *   - an optional voter attribute (entities with a dedicated voter),
 *   - an optional domain guard (e.g. inherited records are read-only),
 * then removes the accepted entities atomically (one explicit transaction —
 * domain-service deleters that flush internally join it; any failure rolls
 * the whole batch back) and writes one AuditLogger::logBulk() batch (ISO 27001 Cl. 7.5.3): 1 batch entry +
 * N per-entity entries correlated by batch_id.
 */
class BulkDeleteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @param string                          $entityType     Short entity name for audit + error messages (e.g. "Risk").
     * @param list<int>                       $ids            Requested entity ids (already sanitised).
     * @param callable(int): ?object          $finder         Loads one entity by id.
     * @param (callable(object): void)|null   $deleter        Domain delete (e.g. a service that cascades/flushes);
     *                                                        null → EntityManager::remove().
     * @param (callable(object): ?string)|null $guard         Returns a rejection reason, or null when deletable.
     * @param string|null                     $voterAttribute Voter attribute checked per entity (null = skip).
     * @param (callable(object): void)|null   $afterFlush     Side effect after the removal is persisted
     *                                                        (e.g. unlink a stored file).
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
    ): array {
        $errors = [];
        $removed = [];
        $perEntityData = [];

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
                if ($deleter !== null) {
                    // May flush internally; it joins this transaction.
                    $deleter($entity);
                } else {
                    $this->entityManager->remove($entity);
                }
                $removed[] = $entity;
                $perEntityData[] = [
                    'action' => 'delete',
                    'entity_id' => $id,
                    'old_values' => $label !== null ? ['label' => $label] : null,
                    'new_values' => ['deleted' => true],
                ];
            }
            if ($removed !== []) {
                $this->entityManager->flush();
            }
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            // Atomic: undo everything, including flushes done by domain deleters.
            $this->rollbackQuietly();

            return $this->result(0, [sprintf('Error deleting %s records, nothing was deleted: %s', $entityType, $e->getMessage())], null);
        }

        $batchId = null;
        if ($removed !== []) {
            if ($afterFlush !== null) {
                foreach ($removed as $entity) {
                    try {
                        $afterFlush($entity);
                    } catch (\Throwable $e) {
                        $errors[] = sprintf('%s post-delete cleanup failed: %s', $entityType, $e->getMessage());
                    }
                }
            }

            // The deletions are committed — always leave the trail (ISO 27001 Cl. 7.5.3).
            try {
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
                );
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s audit log failed after deletion: %s', $entityType, $e->getMessage());
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
