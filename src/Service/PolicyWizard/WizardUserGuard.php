<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\UserRepository;
use App\Security\TenantOwnership;

/**
 * Server-side check for every User id a policy-wizard step accepts.
 *
 * The wizard pickers are built from tenant-scoped lists, but the submitted
 * ids are plain integers and `User` is not covered by the Doctrine
 * TenantFilter. A step accepts an id only when the user belongs to the run's
 * organisation; approver slots additionally require the same predicate that
 * builds the approver choices ({@see UserRepository::findApproversInTenant()}).
 * A run without organisation accepts no user (fails closed).
 */
final class WizardUserGuard
{
    public const string ERROR = 'policy_wizard.error.user_other_tenant';

    /** @var array<int, list<int>> run object id => approver ids */
    private array $approverIds = [];

    public function __construct(private readonly UserRepository $userRepository)
    {
    }

    public function isRunUser(WizardRun $run, int $userId): bool
    {
        $tenant = $run->getTenant();
        if ($tenant === null) {
            return false;
        }
        $user = $this->userRepository->find($userId);

        return $user instanceof User && TenantOwnership::isSameTenant($user->getTenant(), $tenant);
    }

    public function isRunApprover(WizardRun $run, int $userId): bool
    {
        if (!$this->isRunUser($run, $userId)) {
            return false;
        }
        $key = spl_object_id($run);
        $this->approverIds[$key] ??= array_values(array_filter(array_map(
            static fn (User $u): ?int => $u->getId(),
            $this->userRepository->findApproversInTenant($run->getTenant()),
        )));

        return in_array($userId, $this->approverIds[$key], true);
    }
}
