<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\EntityTypeRegistry;
use App\Lifecycle\Exception\FourEyesRequiredException;
use App\Lifecycle\Exception\ReasonRequiredException;
use App\Lifecycle\InvalidTransitionException;
use App\Lifecycle\LifecycleService;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Security\TenantOwnership;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\Registry;

class LifecycleController extends AbstractController
{
    private const string APPROVER_INVALID = 'four_eyes_approver_invalid';
    private const string APPROVER_INVALID_MESSAGE =
        'Approver ungültig: unbekannt oder nicht aus der Organisation des Datensatzes.';

    public function __construct(
        private readonly EntityTypeRegistry $entityRegistry,
        private readonly EntityManagerInterface $em,
        private readonly LifecycleService $lifecycle,
        private readonly LifecycleConfigResolverInterface $resolver,
        private readonly Registry $workflowRegistry,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly UserRepository $userRepository,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    #[Route('/lifecycle/{entityType}/{id}/transition', name: 'app_lifecycle_transition', methods: ['POST'])]
    // fetch() callers (lifecycle_dropdown_controller.js) send the token as X-CSRF-Token header.
    #[IsCsrfTokenValid('lifecycle_transition', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function transition(string $entityType, int $id, Request $request): JsonResponse
    {
        $mapping = $this->entityRegistry->lookup($entityType);
        if ($mapping === null) {
            return $this->jsonError(404, 'unknown_entity_type', sprintf('Lifecycle für Typ "%s" nicht konfiguriert.', $entityType));
        }

        $entity = $this->em->getRepository($mapping['class'])->find($id);
        if ($entity === null) {
            return $this->jsonError(404, 'not_found', 'Entity nicht gefunden.');
        }

        $payload = json_decode($request->getContent(), true) ?: [];
        $transitionName = (string) ($payload['transition'] ?? '');
        $reason = $payload['reason'] ?? null;
        $clientVersion = $payload['lock_version'] ?? null;
        $fourEyesApproverId = $payload['four_eyes_approver_id'] ?? null;

        if (method_exists($entity, 'getLockVersion') && $clientVersion !== null
            && (int) $entity->getLockVersion() !== (int) $clientVersion) {
            return $this->jsonError(409, 'version_conflict', 'Entity wurde geändert — neu laden.', [
                'current_version' => $entity->getLockVersion(),
                'client_version' => $clientVersion,
            ]);
        }

        if (!$this->isGranted(sprintf('lifecycle.%s.%s', $mapping['workflow'], $transitionName), $entity)) {
            return $this->jsonError(403, 'forbidden', sprintf('Berechtigung fehlt für Transition "%s".', $transitionName));
        }

        $approver = null;
        if ($fourEyesApproverId !== null) {
            $approver = $this->userRepository->find((int) $fourEyesApproverId);
            // Unknown id and user of another organisation answer identically, so
            // the endpoint does not reveal which user ids exist elsewhere.
            if (!$approver instanceof User || $this->isApproverOutsideRecordTenant($approver, $entity)) {
                return $this->jsonError(422, self::APPROVER_INVALID, self::APPROVER_INVALID_MESSAGE);
            }
        }

        try {
            $this->lifecycle->transition(
                $entity,
                $mapping['workflow'],
                $transitionName,
                $this->getUser(),
                is_string($reason) ? $reason : null,
                $approver,
            );
        } catch (ReasonRequiredException $e) {
            return $this->jsonError(422, 'reason_required', $e->getMessage());
        } catch (FourEyesRequiredException $e) {
            return $this->jsonError(422, 'four_eyes_required', $e->getMessage(), [
                'reason_code' => $e->reasonCode,
            ]);
        } catch (InvalidTransitionException $e) {
            return $this->jsonError(422, 'invalid_transition', $e->getMessage(), ['allowed' => $e->allowedTransitions ?? []]);
        } catch (OptimisticLockException) {
            return $this->jsonError(409, 'version_conflict', 'Entity wurde gleichzeitig editiert — neu laden.');
        }

        $workflow = $this->workflowRegistry->get($entity, $mapping['workflow']);
        $allowedNext = array_map(static fn ($t) => $t->getName(), $workflow->getEnabledTransitions($entity));

        return new JsonResponse([
            'status' => $entity->getStatus(),
            'lock_version' => method_exists($entity, 'getLockVersion') ? $entity->getLockVersion() : null,
            'allowed_next' => $allowedNext,
        ]);
    }

    #[Route('/lifecycle/{entityType}/bulk-transition', name: 'app_lifecycle_bulk_transition', methods: ['POST'])]
    #[IsCsrfTokenValid('lifecycle_bulk_transition', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function bulkTransition(string $entityType, Request $request): JsonResponse
    {
        $mapping = $this->entityRegistry->lookup($entityType);
        if ($mapping === null) {
            return $this->jsonError(404, 'unknown_entity_type', sprintf('Lifecycle für Typ "%s" nicht konfiguriert.', $entityType));
        }

        $payload = json_decode($request->getContent(), true) ?: [];
        $transitionName = (string) ($payload['transition'] ?? '');
        $ids = $payload['ids'] ?? [];
        $reason = $payload['reason'] ?? null;
        $fourEyesApproverId = $payload['four_eyes_approver_id'] ?? null;

        if (!is_array($ids) || $ids === []) {
            return $this->jsonError(422, 'no_ids', 'Mindestens eine ID erforderlich.');
        }

        $approver = null;
        $approverInvalid = false;
        if ($fourEyesApproverId !== null) {
            $approver = $this->userRepository->find((int) $fourEyesApproverId);
            // Reported per record like an approver of another organisation, so
            // an unknown id is indistinguishable from a foreign one.
            $approverInvalid = !$approver instanceof User;
        }

        $succeeded = [];
        $failed = [];
        $perEntityData = [];
        $repo = $this->em->getRepository($mapping['class']);

        foreach ($ids as $id) {
            $entity = $repo->find((int) $id);
            // A record of another organisation answers like a missing one.
            if ($entity === null || !$this->isBulkTargetInScope($entity)) {
                $failed[(string) $id] = 'not_found';
                continue;
            }
            if (!$this->isGranted(sprintf('lifecycle.%s.%s', $mapping['workflow'], $transitionName), $entity)) {
                $failed[(string) $id] = 'forbidden';
                continue;
            }
            $foreignApprover = $approver instanceof User && $this->isApproverOutsideRecordTenant($approver, $entity);
            if ($approverInvalid || $foreignApprover) {
                $failed[(string) $id] = self::APPROVER_INVALID;
                continue;
            }
            try {
                $this->lifecycle->transition(
                    $entity,
                    $mapping['workflow'],
                    $transitionName,
                    $this->getUser(),
                    is_string($reason) ? $reason : null,
                    $approver,
                );
                $succeeded[] = (int) $id;
                $perEntityData[] = [
                    'action' => 'update',
                    'entity_id' => (int) $id,
                    'new_values' => ['status' => $entity->getStatus(), 'transition' => $transitionName],
                ];
            } catch (\Throwable $e) {
                $failed[(string) $id] = substr($e->getMessage(), 0, 200);
            }
        }

        // One batch entry + one row per changed record (ISO 27001 Cl. 7.5.3);
        // the returned id is the batch id of those rows.
        $batchId = null;
        if ($perEntityData !== []) {
            $shortName = (new \ReflectionClass($mapping['class']))->getShortName();
            $batchId = $this->auditLogger->logBulk(
                eventType: 'bulk_lifecycle_transition',
                entityType: $shortName,
                batchData: [
                    'workflow' => $mapping['workflow'],
                    'transition' => $transitionName,
                    'count' => count($perEntityData),
                    'failed' => count($failed),
                ],
                perEntityData: $perEntityData,
                description: sprintf(
                    'Bulk lifecycle transition "%s" of %d %s records',
                    $transitionName,
                    count($perEntityData),
                    $shortName,
                ),
            );
        }

        return new JsonResponse([
            'succeeded' => $succeeded,
            'failed' => $failed,
            'audit_log_batch_id' => $batchId,
        ]);
    }

    #[Route('/lifecycle/{entityType}/{id}/allowed-transitions', name: 'app_lifecycle_allowed', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function allowedTransitions(string $entityType, int $id): JsonResponse
    {
        $mapping = $this->entityRegistry->lookup($entityType);
        if ($mapping === null) {
            return $this->jsonError(404, 'unknown_entity_type', sprintf('Lifecycle für Typ "%s" nicht konfiguriert.', $entityType));
        }

        $entity = $this->em->getRepository($mapping['class'])->find($id);
        if ($entity === null) {
            return $this->jsonError(404, 'not_found', 'Entity nicht gefunden.');
        }

        $workflow = $this->workflowRegistry->get($entity, $mapping['workflow']);
        $current = (string) $entity->getStatus();
        $candidates = $workflow->getEnabledTransitions($entity);

        $allowed = [];
        foreach ($candidates as $t) {
            $attr = sprintf('lifecycle.%s.%s', $mapping['workflow'], $t->getName());
            if (!$this->isGranted($attr, $entity)) {
                continue;
            }
            $effective = $this->resolver->resolve($entity, $mapping['workflow'], $t->getName());
            $allowed[] = [
                'name' => $t->getName(),
                'to' => $t->getTos()[0] ?? null,
                'reason_required' => (bool) ($effective['reason_required'] ?? false),
                'four_eyes_required' => (bool) ($effective['four_eyes'] ?? false),
                'roles' => $effective['roles'] ?? [],
            ];
        }

        return new JsonResponse([
            'current_status' => $current,
            'lock_version' => method_exists($entity, 'getLockVersion') ? $entity->getLockVersion() : null,
            'allowed_transitions' => $allowed,
        ]);
    }

    /**
     * GET /lifecycle/{entityType}/{id}/history
     * Returns a Turbo-Frame fragment with status-change audit log rows.
     */
    #[Route('/lifecycle/{entityType}/{id}/history', name: 'app_lifecycle_history', methods: ['GET'])]
    public function history(string $entityType, int $id): Response
    {
        // Auth must precede entity lookup — otherwise unknown/missing entities
        // leak as 404 to anonymous callers instead of being refused with 403.
        // IS_AUTHENTICATED_REMEMBERED covers both fresh-login + remember-me cookie,
        // matching the parent show-page's auth tier.
        if (!$this->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            return $this->jsonError(403, 'forbidden', 'Authentifizierung erforderlich.');
        }

        $mapping = $this->entityRegistry->lookup($entityType);
        if ($mapping === null) {
            return $this->jsonError(404, 'unknown_entity_type', sprintf('Lifecycle für Typ "%s" nicht konfiguriert.', $entityType));
        }

        $entity = $this->em->getRepository($mapping['class'])->find($id);
        if ($entity === null || !$this->isHistoryInScope($entity)) {
            return $this->jsonError(404, 'not_found', 'Entity nicht gefunden.');
        }

        // Derive audit log entity type from FQCN short name (e.g. "Document", "ProcessingActivity")
        $shortName = (new \ReflectionClass($mapping['class']))->getShortName();
        $entries = $this->auditLogRepository->findByEntity($shortName, $id);

        // Filter to status-change events only
        $statusEntries = array_filter($entries, static function ($entry): bool {
            $action = $entry->getAction() ?? '';
            if (str_contains($action, 'lifecycle') || str_contains($action, 'status')) {
                return true;
            }
            $new = $entry->getNewValues() ?? '';
            return str_contains($new, '"status"') || str_contains($new, "'status'");
        });

        return $this->render('_components/_lifecycle_history_rows.html.twig', [
            'entity_type' => $entityType,
            'entity_id'   => $id,
            'entries'     => array_values($statusEntries),
        ]);
    }

    /**
     * The four-eyes approver comes from the request body (user id), and `User`
     * is not covered by the Doctrine TenantFilter: an approver of another
     * organisation than the record's must be refused.
     */
    private function isApproverOutsideRecordTenant(User $approver, object $entity): bool
    {
        $recordTenant = method_exists($entity, 'getTenant') ? $entity->getTenant() : null;
        if (!$recordTenant instanceof Tenant) {
            return false;
        }

        return $approver->getTenant()?->getId() !== $recordTenant->getId();
    }

    /**
     * Bulk writes follow the own-organisation record scope of every other bulk
     * endpoint ({@see TenantOwnership::isInContextScope()}). An organisation
     * record is checked like its administration page; global catalogue rows
     * (no tenant column) are left to the transition voter.
     */
    private function isBulkTargetInScope(object $entity): bool
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }
        if ($entity instanceof Tenant) {
            return $this->tenantContext->canAccessTenant($entity);
        }
        if (!method_exists($entity, 'getTenant')) {
            return true;
        }

        return TenantOwnership::isInContextScope($entity, $this->tenantContext, false);
    }

    private function jsonError(int $code, string $errorCode, string $message, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'error' => $errorCode,
            'message' => $message,
            'details' => $details,
        ], $code);
    }

    /**
     * The tenant filter does not cover every lifecycle type (Tenant itself is
     * exempt), so the history checks the owning organisation explicitly: own
     * organisation or below, like the record's show page. Global catalogue
     * rows (role, permission) have no owner and stay readable.
     */
    private function isHistoryInScope(object $entity): bool
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        $owner = match (true) {
            $entity instanceof Tenant => $entity,
            method_exists($entity, 'getTenant') => $entity->getTenant(),
            default => null,
        };
        if ($owner === null) {
            return !method_exists($entity, 'getTenant') && !$entity instanceof Tenant;
        }

        return $owner instanceof Tenant && $this->tenantContext->canAccessTenant($owner);
    }
}
