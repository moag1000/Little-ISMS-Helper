<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
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
 */
final class TenantStatusService
{
    public const string WORKFLOW = 'tenant_lifecycle';

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

        $approver = $approverId !== null && $approverId > 0 ? $this->userRepository->find($approverId) : null;
        $reason = trim((string) $reason);

        try {
            $this->lifecycleService->transition(
                $tenant,
                self::WORKFLOW,
                $transition,
                $actor,
                $reason !== '' ? $reason : null,
                $approver instanceof User ? $approver : null,
            );
        } catch (ReasonRequiredException | FourEyesRequiredException | InvalidTransitionException $e) {
            $this->entityManager->refresh($tenant);

            return ['ok' => false, 'message' => match (true) {
                $e instanceof ReasonRequiredException => 'tenant.flash.reason_required',
                $e instanceof FourEyesRequiredException => 'tenant.flash.four_eyes_required',
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
     * Active SUPER_ADMIN users who may act as second approver; the actor is
     * excluded.
     *
     * @return list<User>
     */
    public function fourEyesApprovers(?User $actor): array
    {
        return array_values(array_filter(
            $this->userRepository->findByRole('ROLE_SUPER_ADMIN'),
            static fn (User $u): bool => $u->isActive() && $u !== $actor,
        ));
    }
}
