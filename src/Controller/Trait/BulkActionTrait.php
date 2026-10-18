<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\Tenant;
use App\Entity\User;
use App\Lifecycle\LifecycleService;
use App\Security\TenantOwnership;
use App\Service\AuditLogger;
use App\Service\BulkDeleteService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Shared bulk-action helpers for controllers.
 *
 * Callers pass pre-tenant-scoped entity collections and field extractors.
 * RBAC + module-gates are enforced by the individual route methods.
 * CSRF: handleBulkDelete() validates the token itself; the other helpers
 * expect the route method to call isBulkCsrfTokenValid() first.
 *
 * Audit-logging (ISO 27001 Cl. 7.5.3) is performed inside each helper
 * so no call-site can forget it.
 */
trait BulkActionTrait
{
    /**
     * CSRF token id shared by every bulk-action POST of the list views.
     * `_bulk_action_bar.html.twig` renders it; bulk_actions_controller.js
     * sends it as body `_token` (and as `X-CSRF-Token` header).
     */
    protected const string BULK_ACTION_CSRF_ID = 'bulk_action';

    /**
     * Tenant guard for every bulk export / assign / pack loop.
     *
     * The entity must belong to the acting user's tenant (compared by id,
     * fail closed). A ROLE_SUPER_ADMIN without tenant context is the only
     * all-tenant case. Same rule as BulkDeleteService.
     */
    protected function isInBulkTenantScope(object $entity, ?Tenant $tenant): bool
    {
        return TenantOwnership::isInScope($entity, $tenant, $tenant === null && $this->isGranted('ROLE_SUPER_ADMIN'));
    }

    /**
     * Validate the bulk-action CSRF token of a JSON (or form) request.
     *
     * @param array<mixed> $data Decoded JSON body.
     */
    protected function isBulkCsrfTokenValid(Request $request, array $data): bool
    {
        $token = $data['_token'] ?? $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token');

        return is_string($token) && $token !== '' && $this->isCsrfTokenValid(self::BULK_ACTION_CSRF_ID, $token);
    }

    /**
     * Canonical bulk-delete endpoint body (CSRF + id sanitising + tenant /
     * voter / guard checks + one flush + AuditLogger::logBulk()).
     *
     * Route methods keep their own #[IsGranted] role and module gate, then
     * delegate here. See BulkDeleteService for the per-entity rules.
     *
     * @param callable(int): ?object           $finder
     * @param (callable(object): void)|null    $deleter
     * @param (callable(object): ?string)|null $guard
     * @param (callable(object): void)|null    $afterFlush
     */
    protected function handleBulkDelete(
        Request $request,
        BulkDeleteService $bulkDeleteService,
        string $entityType,
        ?UserInterface $user,
        callable $finder,
        ?callable $deleter = null,
        ?callable $guard = null,
        ?string $voterAttribute = null,
        ?callable $afterFlush = null,
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        $data = is_array($data) ? $data : [];

        if (!$this->isBulkCsrfTokenValid($request, $data)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $ids = [];
        foreach ((array) ($data['ids'] ?? []) as $rawId) {
            if (is_int($rawId) || (is_string($rawId) && ctype_digit($rawId))) {
                $id = (int) $rawId;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        if ($ids === []) {
            return new JsonResponse(['success' => false, 'error' => 'No items selected'], Response::HTTP_BAD_REQUEST);
        }

        $tenant = $user instanceof User ? $user->getTenant() : null;

        try {
            $result = $bulkDeleteService->delete(
                $entityType,
                array_values($ids),
                $tenant,
                $finder,
                $deleter,
                $guard,
                $voterAttribute,
                $afterFlush,
            );
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['success' => false, 'deleted' => 0, 'errors' => [$e->getMessage()]],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $status = $result['deleted'] === 0 && $result['errors'] !== []
            ? Response::HTTP_BAD_REQUEST
            : Response::HTTP_OK;

        return new JsonResponse($result, $status);
    }

    /**
     * Generic dependency-check helper for bulk-delete-check endpoints.
     *
     * Iterates the given (pre-tenant-scoped) entities, runs each checker
     * closure, and returns a JSON response in the format expected by the
     * bulk-delete-confirmation Stimulus controller:
     *   { "dependencies": [{"message": "...", "icon": "..."}, ...], "checked_count": N }
     *
     * Each checker closure should return either null (no warning) or an array
     * with at least a non-empty "message" key and an optional "icon" key.
     * The "entity" and "count" metadata from the task spec are encoded into
     * the human-readable message string here.
     *
     * @param iterable<object>  $entities      Tenant-scoped entities to check (already filtered by caller).
     * @param string            $labelGetter   Method on entity returning the display label (e.g. "getName").
     * @param array<callable>   $dependencyMap Array of closures: fn(object $entity): array|null — each returns
     *                                         null or ['message' => '...', 'icon' => '...'].
     *
     * @return JsonResponse {"dependencies": [...], "checked_count": int}
     */
    protected function checkBulkDependencies(
        iterable $entities,
        string $labelGetter,
        array $dependencyMap = [],
    ): JsonResponse {
        $deps = [];
        $count = 0;

        foreach ($entities as $entity) {
            $count++;
            if ($dependencyMap === []) {
                continue;
            }
            $label = method_exists($entity, $labelGetter)
                ? (string) $entity->{$labelGetter}()
                : (string) ($entity->getId() ?? '?');
            foreach ($dependencyMap as $checker) {
                $check = ($checker)($entity);
                if ($check !== null && isset($check['message']) && $check['message'] !== '') {
                    $deps[] = [
                        'message' => $label . ': ' . $check['message'],
                        'icon' => $check['icon'] ?? 'ui-link',
                    ];
                }
            }
        }

        return new JsonResponse(['dependencies' => $deps, 'checked_count' => $count]);
    }

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
     * Entities the current user may not edit (voter attribute `edit`) are
     * rejected with reason `access_denied`; an inactive assignee rejects the
     * whole batch (`assignee_inactive`), as nobody could act on the records.
     *
     * @return array{ok: bool, changed: int, rejected: list<array{id: mixed, reason: string}>, error?: string}
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

        if (!$assignee->isActive()) {
            return ['ok' => false, 'changed' => 0, 'rejected' => [], 'error' => 'assignee_inactive'];
        }

        foreach ($entities as $entity) {
            if (!$this->isGranted('edit', $entity)) {
                $rejected[] = [
                    'id' => method_exists($entity, 'getId') ? $entity->getId() : null,
                    'reason' => 'access_denied',
                ];
                continue;
            }
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
     * Only moves listed in $statusMatrix (currentStatus → [targetStatus →
     * transitionName], the CLAUDE.md 5-transition matrix) are applied. The
     * transition depends on where the entity is now: `published` is `publish`
     * from approved but `restore` from archived. A target that appears in no
     * row of the matrix returns ['ok' => false, 'error' => 'invalid_status']
     * and never reaches the workflow (the posted value is not a transition
     * name). Per entity, a move outside the matrix is rejected with
     * `transition_not_allowed` and the lifecycle voter must grant the
     * transition (`forbidden` otherwise).
     *
     * @param iterable<object>                       $entities     Pre-tenant-scoped entities.
     * @param string                                 $workflowName Symfony Workflow name.
     * @param array<string, array<string, string>>   $statusMatrix Allowed moves as
     *                                                             currentStatus → [targetStatus → transitionName].
     * @param string                                 $newStatus    Target status posted by the UI.
     * @param string|null                            $reason       Optional reason from the UI.
     * @param User|null                              $user         Authenticated user.
     * @param string                                 $entityType   Short entity class name for audit-log.
     * @param LifecycleService|null                  $lifecycleService
     * @param AuditLogger|null                       $auditLogger
     * @param list<array{id: mixed, reason: string}> $preRejected  Ids the caller already rejected
     *                                                             (not found / other tenant).
     * @return array{ok: bool, changed?: int, rejected?: list<array{id: mixed, reason: string}>, error?: string, batch_id?: ?string}
     */
    protected function applyBulkStatusChange(
        iterable $entities,
        string $workflowName,
        array $statusMatrix,
        string $newStatus,
        ?string $reason,
        ?User $user,
        string $entityType,
        ?LifecycleService $lifecycleService = null,
        ?AuditLogger $auditLogger = null,
        array $preRejected = [],
    ): array {
        if (!in_array($newStatus, self::bulkStatusTargets($statusMatrix), true)) {
            return ['ok' => false, 'error' => 'invalid_status'];
        }
        if ($lifecycleService === null) {
            return ['ok' => false, 'error' => 'lifecycle_service_unavailable'];
        }

        $changed = 0;
        $rejected = $preRejected;
        $perEntityData = [];

        foreach ($entities as $entity) {
            $id = method_exists($entity, 'getId') ? $entity->getId() : null;
            $currentStatus = method_exists($entity, 'getStatus') ? (string) $entity->getStatus() : '';
            // The transition depends on where the entity is now: `published`
            // means `publish` from approved but `restore` from archived.
            $transitionName = $statusMatrix[$currentStatus][$newStatus] ?? null;
            if ($transitionName === null) {
                $rejected[] = ['id' => $id, 'reason' => 'transition_not_allowed'];
                continue;
            }
            // Per-transition roles (YAML + tenant overrides) still apply.
            if (!$this->isGranted(sprintf('lifecycle.%s.%s', $workflowName, $transitionName), $entity)) {
                $rejected[] = ['id' => $id, 'reason' => 'forbidden'];
                continue;
            }

            try {
                $lifecycleService->transition(
                    $entity,
                    $workflowName,
                    $transitionName,
                    $user,
                    $reason !== null && $reason !== '' ? $reason : null,
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
        $batchId = null;
        if ($auditLogger !== null && $changed > 0) {
            $batchId = $auditLogger->logBulk(
                eventType: 'bulk_status_change',
                entityType: $entityType,
                batchData: ['new_status' => $newStatus, 'count' => $changed, 'rejected' => count($rejected)],
                perEntityData: $perEntityData,
                description: sprintf(
                    'Bulk status change of %d %s records to \'%s\'',
                    $changed,
                    $entityType,
                    $newStatus,
                ),
            );
        }

        return ['ok' => true, 'changed' => $changed, 'rejected' => $rejected, 'batch_id' => $batchId];
    }

    /**
     * Every target status reachable through the bulk matrix.
     *
     * @param array<string, array<string, string>> $statusMatrix
     * @return list<string>
     */
    protected static function bulkStatusTargets(array $statusMatrix): array
    {
        return array_values(array_unique(array_merge(...array_values(array_map('array_keys', $statusMatrix)))));
    }
}
