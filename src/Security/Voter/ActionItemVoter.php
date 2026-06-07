<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ActionItem;
use App\Entity\Tenant;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * ActionItem Voter.
 *
 * Enforces tenant-isolation for Maßnahmenplanung action items.
 * - VIEW:        same tenant OR holding-tree access (group-CISO/Konzern-ISB)
 * - EDIT/DELETE: same tenant (ROLE_USER baseline — controller IsGranted enforces minimum role)
 * - SUPER_ADMIN: full bypass
 */
final class ActionItemVoter extends Voter
{
    use HoldingTreeAccessTrait;

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    protected function getRoleHierarchy(): RoleHierarchyInterface
    {
        return $this->roleHierarchy;
    }

    public const string VIEW   = 'view';
    public const string EDIT   = 'edit';
    public const string DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof ActionItem;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var ActionItem $item */
        $item = $subject;

        if ($this->hasRoleHierarchical($user, 'ROLE_SUPER_ADMIN')) {
            return true;
        }

        return match ($attribute) {
            self::VIEW              => $this->canView($item, $user),
            self::EDIT, self::DELETE => $this->canManage($item, $user),
            default                 => false,
        };
    }

    private function canView(ActionItem $item, User $user): bool
    {
        if ($item->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant) {
            return true;
        }

        return $this->canReadAcrossHoldingTree($user, $item->getTenant());
    }

    private function canManage(ActionItem $item, User $user): bool
    {
        return $item->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant;
    }

    private function hasRoleHierarchical(User $user, string $role): bool
    {
        $reachable = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        return in_array($role, $reachable, true);
    }
}
