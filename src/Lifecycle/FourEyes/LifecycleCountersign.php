<?php

declare(strict_types=1);

namespace App\Lifecycle\FourEyes;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\Tenant;
use App\Entity\User;
use App\Exception\BusinessRule\BusinessRuleException;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\EntityTypeRegistry;
use App\Lifecycle\EventListener\FourEyesValidator;
use App\Lifecycle\Exception\ReasonRequiredException;
use App\Lifecycle\InvalidTransitionException;
use App\Lifecycle\LifecycleService;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Service\FourEyesApprovalService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Workflow\Registry;

/**
 * S27: a four-eyes lifecycle transition is confirmed by the second person in
 * their own session — never by naming them.
 *
 * The person who starts the transition files a counter-sign request
 * ({@see FourEyesApprovalRequest::ACTION_LIFECYCLE_TRANSITION}, optionally for a
 * designated colleague). It waits in the four-eyes inbox and on My Day; when
 * the second person confirms there, {@see confirm()} applies the transition
 * with the requester as initiator and the confirming person as approver, so
 * TransitionAccess checks that person's roles and the evidence stamper names
 * them. In an organisation where nobody else could confirm, the transition is
 * applied at once with the documented reason (single-approver escape of
 * {@see FourEyesValidator}).
 */
final class LifecycleCountersign
{
    public function __construct(
        private readonly EntityTypeRegistry $entityTypes,
        private readonly EntityManagerInterface $entityManager,
        private readonly LifecycleService $lifecycle,
        private readonly LifecycleConfigResolverInterface $resolver,
        private readonly FourEyesValidator $fourEyesValidator,
        private readonly FourEyesApprovalService $fourEyesApprovals,
        private readonly FourEyesApprovalRequestRepository $requests,
        private readonly Registry $workflowRegistry,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * True when the transition is four-eyes and somebody else could confirm
     * it: the caller files a request instead of applying the transition.
     */
    public function needsCountersign(object $entity, string $workflowName, string $transitionName, User $actor): bool
    {
        $effective = $this->resolver->resolve($entity, $workflowName, $transitionName);

        return ($effective['four_eyes'] ?? false) === true
            && $this->fourEyesValidator->hasOtherApprover($entity, $workflowName, $transitionName, $actor);
    }

    /**
     * Files (or returns the already pending) counter-sign request. The
     * transition must be possible now and carry its reason when one is needed.
     *
     * @throws InvalidTransitionException
     * @throws ReasonRequiredException
     */
    public function request(
        object $entity,
        string $entitySlug,
        string $workflowName,
        string $transitionName,
        User $requester,
        ?string $reason,
        ?User $designatedApprover = null,
    ): FourEyesApprovalRequest {
        $workflow = $this->workflowRegistry->get($entity, $workflowName);
        $current = method_exists($entity, 'getStatus') ? (string) $entity->getStatus() : '';
        if (!$workflow->can($entity, $transitionName)) {
            throw new InvalidTransitionException(
                message: sprintf('Transition "%s" is not possible in state "%s".', $transitionName, $current),
                entityClass: $entity::class,
                fromStatus: $current,
                toStatus: '<unknown>',
                allowedTransitions: array_map(static fn ($t) => $t->getName(), $workflow->getEnabledTransitions($entity)),
            );
        }
        $effective = $this->resolver->resolve($entity, $workflowName, $transitionName);
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        if (($effective['reason_required'] ?? false) === true && $reason === null) {
            throw new ReasonRequiredException($workflowName, $transitionName);
        }

        $entityId = method_exists($entity, 'getId') ? (int) $entity->getId() : 0;
        $pending = $this->pendingFor($entitySlug, $entityId, $transitionName);
        if ($pending instanceof FourEyesApprovalRequest) {
            return $pending;
        }

        return $this->fourEyesApprovals->requestApproval(
            FourEyesApprovalRequest::ACTION_LIFECYCLE_TRANSITION,
            [
                'entity_type' => $entitySlug,
                'entity_id' => $entityId,
                'record' => self::label($entity),
                'workflow' => $workflowName,
                'transition' => $transitionName,
                'from_status' => $current,
                'reason' => $reason,
            ],
            $requester,
            $designatedApprover,
        );
    }

    /**
     * The second person confirms: applies the transition (requester =
     * initiator, $approver = the four-eyes approver of this session) and
     * closes the request. Nothing is changed when the transition is refused.
     */
    public function confirm(FourEyesApprovalRequest $request, User $approver): void
    {
        if ($request->getActionType() !== FourEyesApprovalRequest::ACTION_LIFECYCLE_TRANSITION) {
            // @intentional-assertion: FourEyesController routes only lifecycle requests here
            throw new \LogicException('Not a lifecycle counter-sign request.');
        }
        if (!$request->isPending()) {
            throw new BusinessRuleException('Approval request is not pending.', 'not_pending');
        }
        $requester = $request->getRequestedBy();
        if (!$requester instanceof User || $requester->getId() === $approver->getId()) {
            throw new BusinessRuleException('Approver must differ from requester (segregation of duties).', 'self_approval');
        }
        $designated = $request->getRequestedApprover();
        if ($designated instanceof User && $designated->getId() !== $approver->getId()) {
            throw new BusinessRuleException('Only the designated approver can approve this request.', 'wrong_approver');
        }

        $payload = $request->getPayload();
        $mapping = $this->entityTypes->lookup((string) ($payload['entity_type'] ?? ''));
        $entity = $mapping !== null ? $this->entityManager->find($mapping['class'], (int) ($payload['entity_id'] ?? 0)) : null;
        if ($mapping === null || $entity === null || $mapping['workflow'] !== ($payload['workflow'] ?? null)) {
            throw new BusinessRuleException('The record of this request no longer exists.', 'record_missing');
        }
        if (method_exists($entity, 'getTenant') && $entity->getTenant()?->getId() !== $request->getTenant()?->getId()) {
            throw new BusinessRuleException('The record belongs to another organisation.', 'record_other_tenant');
        }

        $reason = $payload['reason'] ?? null;
        $this->lifecycle->transition(
            $entity,
            $mapping['workflow'],
            (string) ($payload['transition'] ?? ''),
            $requester,
            is_string($reason) ? $reason : null,
            $approver,
        );

        $this->fourEyesApprovals->approve($request, $approver);
    }

    private function pendingFor(string $entitySlug, int $entityId, string $transitionName): ?FourEyesApprovalRequest
    {
        $tenant = $this->tenantOfRequests();
        if ($tenant === null) {
            return null;
        }
        foreach ($this->requests->findByActionType($tenant, FourEyesApprovalRequest::ACTION_LIFECYCLE_TRANSITION) as $request) {
            $payload = $request->getPayload();
            if ($request->isPending()
                && ($payload['entity_type'] ?? null) === $entitySlug
                && (int) ($payload['entity_id'] ?? 0) === $entityId
                && ($payload['transition'] ?? null) === $transitionName) {
                return $request;
            }
        }

        return null;
    }

    private function tenantOfRequests(): ?Tenant
    {
        return $this->tenantContext->getCurrentTenant();
    }

    private static function label(object $entity): string
    {
        foreach (['getTitle', 'getName', 'getReferenceNumber'] as $getter) {
            if (method_exists($entity, $getter)) {
                $value = $entity->$getter();
                if (is_string($value) && $value !== '') {
                    return mb_substr($value, 0, 200);
                }
            }
        }

        return sprintf('%s #%d', (new \ReflectionClass($entity))->getShortName(), method_exists($entity, 'getId') ? (int) $entity->getId() : 0);
    }
}
