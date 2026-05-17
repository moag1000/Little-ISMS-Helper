<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\EntityTypeRegistry;
use App\Lifecycle\Exception\ReasonRequiredException;
use App\Lifecycle\InvalidTransitionException;
use App\Lifecycle\LifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Workflow\Registry;

class LifecycleController extends AbstractController
{
    public function __construct(
        private readonly EntityTypeRegistry $entityRegistry,
        private readonly EntityManagerInterface $em,
        private readonly LifecycleService $lifecycle,
        private readonly LifecycleConfigResolverInterface $resolver,
        private readonly Registry $workflowRegistry,
    ) {}

    #[Route('/lifecycle/{entityType}/{id}/transition', name: 'app_lifecycle_transition', methods: ['POST'])]
    #[IsCsrfTokenValid('lifecycle_transition')]
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

        try {
            $this->lifecycle->transition($entity, $mapping['workflow'], $transitionName, $this->getUser(), is_string($reason) ? $reason : null);
        } catch (ReasonRequiredException $e) {
            return $this->jsonError(422, 'reason_required', $e->getMessage());
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
    #[IsCsrfTokenValid('lifecycle_bulk_transition')]
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

        if (!is_array($ids) || $ids === []) {
            return $this->jsonError(422, 'no_ids', 'Mindestens eine ID erforderlich.');
        }

        $succeeded = [];
        $failed = [];
        $repo = $this->em->getRepository($mapping['class']);

        foreach ($ids as $id) {
            $entity = $repo->find((int) $id);
            if ($entity === null) {
                $failed[(string) $id] = 'not_found';
                continue;
            }
            if (!$this->isGranted(sprintf('lifecycle.%s.%s', $mapping['workflow'], $transitionName), $entity)) {
                $failed[(string) $id] = 'forbidden';
                continue;
            }
            try {
                $this->lifecycle->transition($entity, $mapping['workflow'], $transitionName, $this->getUser(), is_string($reason) ? $reason : null);
                $succeeded[] = (int) $id;
            } catch (\Throwable $e) {
                $failed[(string) $id] = substr($e->getMessage(), 0, 200);
            }
        }

        return new JsonResponse([
            'succeeded' => $succeeded,
            'failed' => $failed,
            'audit_log_batch_id' => bin2hex(random_bytes(8)),
        ]);
    }

    #[Route('/lifecycle/{entityType}/{id}/allowed-transitions', name: 'app_lifecycle_allowed', methods: ['GET'])]
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
            ];
        }

        return new JsonResponse([
            'current_status' => $current,
            'lock_version' => method_exists($entity, 'getLockVersion') ? $entity->getLockVersion() : null,
            'allowed_transitions' => $allowed,
        ]);
    }

    private function jsonError(int $code, string $errorCode, string $message, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'error' => $errorCode,
            'message' => $message,
            'details' => $details,
        ], $code);
    }
}
