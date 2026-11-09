<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Exception\BusinessRule\BusinessRuleException;
use App\Lifecycle\FourEyes\LifecycleCountersign;
use App\Lifecycle\Exception\FourEyesRequiredException;
use App\Lifecycle\Exception\ReasonRequiredException;
use App\Lifecycle\InvalidTransitionException;
use App\Lifecycle\LifecycleService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Organisation status toggle (active ⇄ suspended, draft → active) through
 * tenant_lifecycle, so roles (SUPER_ADMIN), reason, four-eyes and the
 * status_change audit row all apply. There is deliberately no other write
 * path for the status: the tenant form has no isActive switch.
 *
 * Four eyes (S27): the chosen second instance operator is asked to
 * counter-sign; the status changes when that person confirms in their own
 * session (four-eyes inbox). Naming somebody never counts as their approval.
 */
final class TenantStatusService
{
    public const string WORKFLOW = 'tenant_lifecycle';
    /** EntityTypeRegistry slug of the organisation record. */
    private const string ENTITY_SLUG = 'tenant';

    /** Status → the transition the toggle applies. */
    private const array STATUS_TRANSITIONS = [
        Tenant::STATUS_DRAFT => 'activate',
        Tenant::STATUS_ACTIVE => 'suspend',
        Tenant::STATUS_SUSPENDED => 'reactivate',
    ];

    public function __construct(
        private readonly LifecycleService $lifecycleService,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LifecycleCountersign $countersign,
    ) {}

    /** The transition a toggle would apply, or null in a terminal status. */
    public function nextTransition(Tenant $tenant): ?string
    {
        return self::STATUS_TRANSITIONS[$tenant->getStatus()] ?? null;
    }

    /**
     * Apply the toggle transition. Returns the translation key of the
     * outcome (tenant domain) and whether it succeeded; on failure the
     * tenant is refreshed so no half-applied state stays in memory.
     *
     * @return array{ok: bool, message: string}
     */
    public function toggle(Tenant $tenant, ?User $actor, ?string $reason, ?int $approverId): array
    {
        $transition = $this->nextTransition($tenant);
        if ($transition === null) {
            return ['ok' => false, 'message' => 'tenant.flash.transition_not_allowed'];
        }

        $reason = trim((string) $reason);
        $designated = $approverId !== null && $approverId > 0 ? $this->userRepository->find($approverId) : null;
        if ($designated instanceof User && !in_array($designated, $this->fourEyesApprovers($actor), true)) {
            return ['ok' => false, 'message' => 'tenant.flash.four_eyes_required'];
        }

        try {
            if ($actor instanceof User && $this->countersign->needsCountersign($tenant, self::WORKFLOW, $transition, $actor)) {
                $this->countersign->request(
                    $tenant,
                    self::ENTITY_SLUG,
                    self::WORKFLOW,
                    $transition,
                    $actor,
                    $reason !== '' ? $reason : null,
                    $designated instanceof User ? $designated : null,
                );

                return ['ok' => true, 'message' => 'tenant.flash.countersign_requested'];
            }

            $this->lifecycleService->transition(
                $tenant,
                self::WORKFLOW,
                $transition,
                $actor,
                $reason !== '' ? $reason : null,
            );
        } catch (ReasonRequiredException | FourEyesRequiredException | InvalidTransitionException | BusinessRuleException $e) {
            $this->entityManager->refresh($tenant);

            return ['ok' => false, 'message' => match (true) {
                $e instanceof ReasonRequiredException => 'tenant.flash.reason_required',
                $e instanceof FourEyesRequiredException, $e instanceof BusinessRuleException => 'tenant.flash.four_eyes_required',
                default => 'tenant.flash.transition_not_allowed',
            }];
        }

        return ['ok' => true, 'message' => match ($transition) {
            'suspend' => 'tenant.flash.suspended',
            'reactivate' => 'tenant.flash.reactivated',
            default => 'tenant.flash.activated',
        }];
    }

    /**
     * Active SUPER_ADMIN users of the actor's own organisation who may be asked
     * to counter-sign (the request waits in that organisation's four-eyes
     * inbox); the actor is excluded.
     *
     * @return list<User>
     */
    public function fourEyesApprovers(?User $actor): array
    {
        return array_values(array_filter(
            $this->userRepository->findActiveInstanceOperators(),
            static fn (User $u): bool => $u !== $actor
                && ($actor === null || $u->getTenant()?->getId() === $actor->getTenant()?->getId()),
        ));
    }
}
