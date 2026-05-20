<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\User;
use App\Lifecycle\LifecycleService;
use App\Service\AuditLogger;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared bulk-action helpers for controllers.
 *
 * Callers pass pre-tenant-scoped entity collections and field extractors.
 * CSRF + RBAC + module-gates are enforced by the individual route methods
 * before calling these helpers (ISO 27001 Cl. 7.5.3 note below).
 *
 * Audit-logging (ISO 27001 Cl. 7.5.3) is performed inside each helper
 * so no call-site can forget it.
 */
trait BulkActionTrait
{
    /**
     * Stream a CSV export response for the given entities.
     *
     * @param iterable<object>   $entities    Pre-tenant-scoped entities (callers
     *                                         must apply the tenant guard before
     *                                         passing to this method).
     * @param string[]           $headers     Column header labels (same order as $extractor return value).
     * @param callable           $extractor   fn(object $entity): array<string> — one value per header column.
     * @param string             $filename    Basename of the downloaded file (no .csv extension needed).
     * @param string             $entityType  Short entity class name for audit-log (e.g. 'Risk').
     * @param AuditLogger|null   $auditLogger Optional — injected per-controller.
     */
    protected function streamCsvExport(
        iterable $entities,
        array $headers,
        callable $extractor,
        string $filename,
        string $entityType,
        ?AuditLogger $auditLogger = null,
    ): StreamedResponse {
        $rows = [];
        foreach ($entities as $entity) {
            $rows[] = $entity;
        }

        // Audit-log the bulk export (ISO 27001 Cl. 7.5.3).
        if ($auditLogger !== null) {
            $ids = [];
            foreach ($rows as $entity) {
                if (method_exists($entity, 'getId')) {
                    $ids[] = $entity->getId();
                }
            }
            $auditLogger->logBulk(
                eventType: 'bulk_export',
                entityType: $entityType,
                batchData: ['format' => 'csv', 'columns' => $headers, 'count' => count($ids)],
                perEntityData: array_map(
                    static fn($id): array => ['action' => 'export', 'entity_id' => $id, 'new_values' => ['exported' => true]],
                    $ids,
                ),
                description: sprintf('CSV bulk export of %d %s records', count($ids), $entityType),
            );
        }

        $safeName = preg_replace('/[^\w\-]/', '_', $filename) . '_' . date('Y-m-d') . '.csv';

        return new StreamedResponse(static function () use ($headers, $rows, $extractor): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            // UTF-8 BOM for Excel compatibility.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '\\');
            foreach ($rows as $entity) {
                fputcsv($out, array_map('strval', ($extractor)($entity)), ',', '"', '\\');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
        ]);
    }

    /**
     * Apply a per-entity owner-assignment callback in a transaction and
     * return a result array suitable for a JSON response.
     *
     * @param iterable<object>  $entities   Pre-tenant-scoped entities.
     * @param callable          $assigner   fn(object $entity, User $assignee): void — mutates the entity.
     * @param User              $assignee   The User being assigned.
     * @param string            $entityType Short entity class name for audit-log.
     * @param AuditLogger|null  $auditLogger Optional.
     * @return array{ok: bool, changed: int, rejected: list<array{id: mixed, reason: string}>}
     */
    protected function applyBulkAssign(
        iterable $entities,
        callable $assigner,
        User $assignee,
        string $entityType,
        ?AuditLogger $auditLogger = null,
    ): array {
        $changed = 0;
        $rejected = [];
        $perEntityData = [];

        foreach ($entities as $entity) {
            try {
                ($assigner)($entity, $assignee);
                $id = method_exists($entity, 'getId') ? $entity->getId() : null;
                $changed++;
                $perEntityData[] = [
                    'action' => 'update',
                    'entity_id' => $id,
                    'new_values' => ['assigned_to_user_id' => $assignee->getId()],
                ];
            } catch (\Throwable $e) {
                $id = method_exists($entity, 'getId') ? $entity->getId() : null;
                $rejected[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        // Audit-log (ISO 27001 Cl. 7.5.3).
        if ($auditLogger !== null && $changed > 0) {
            $auditLogger->logBulk(
                eventType: 'bulk_assign',
                entityType: $entityType,
                batchData: [
                    'assignee_user_id' => $assignee->getId(),
                    'count' => $changed,
                ],
                perEntityData: $perEntityData,
                description: sprintf(
                    'Bulk assign %d %s records to user #%d',
                    $changed,
                    $entityType,
                    (int) $assignee->getId(),
                ),
            );
        }

        return ['ok' => true, 'changed' => $changed, 'rejected' => $rejected];
    }

    /**
     * Apply bulk status-change via LifecycleService::transition() and return
     * a result array suitable for a JSON response.
     *
     * @param iterable<object>      $entities           Pre-tenant-scoped entities.
     * @param string                $workflowName       Symfony Workflow name.
     * @param array<string, string> $statusToTransition Map of newStatus → transitionName.
     * @param string                $newStatus          Target status posted by the UI.
     * @param string|null           $reason             Optional reason from the UI.
     * @param User|null             $user               Authenticated user.
     * @param string                $entityType         Short entity class name for audit-log.
     * @param LifecycleService|null $lifecycleService
     * @param AuditLogger|null      $auditLogger
     * @return array{ok: bool, changed?: int, rejected?: list<array{id: mixed, reason: string}>, error?: string}
     */
    protected function applyBulkStatusChange(
        iterable $entities,
        string $workflowName,
        array $statusToTransition,
        string $newStatus,
        ?string $reason,
        ?User $user,
        string $entityType,
        ?LifecycleService $lifecycleService = null,
        ?AuditLogger $auditLogger = null,
    ): array {
        if ($lifecycleService === null) {
            return ['ok' => false, 'error' => 'lifecycle_service_unavailable'];
        }

        $changed = 0;
        $rejected = [];
        $perEntityData = [];

        foreach ($entities as $entity) {
            $id = method_exists($entity, 'getId') ? $entity->getId() : null;
            $transitionName = $statusToTransition[$newStatus] ?? $newStatus;

            try {
                $lifecycleService->transition(
                    $entity,
                    $workflowName,
                    $transitionName,
                    $user,
                    $reason !== '' ? $reason : null,
                );
                $changed++;
                $perEntityData[] = [
                    'action' => 'update',
                    'entity_id' => $id,
                    'new_values' => ['status' => $newStatus, 'transition' => $transitionName],
                ];
            } catch (\Throwable $e) {
                $rejected[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        // Audit-log (ISO 27001 Cl. 7.5.3).
        if ($auditLogger !== null && $changed > 0) {
            $auditLogger->logBulk(
                eventType: 'bulk_status_change',
                entityType: $entityType,
                batchData: ['new_status' => $newStatus, 'count' => $changed],
                perEntityData: $perEntityData,
                description: sprintf(
                    'Bulk status change of %d %s records to \'%s\'',
                    $changed,
                    $entityType,
                    $newStatus,
                ),
            );
        }

        return ['ok' => true, 'changed' => $changed, 'rejected' => $rejected];
    }
}
