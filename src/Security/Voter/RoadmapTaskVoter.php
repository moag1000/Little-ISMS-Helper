<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\RoadmapTask;
use App\Entity\Tenant;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * RoadmapTask Voter.
 *
 * Enforces tenant-isolation and role-hierarchy for RoadmapTask data.
 * - VIEW: same tenant OR holding-tree access for group-CISO/Konzern-ISB
 * - EDIT / DELETE: same tenant + ROLE_MANAGER minimum
 */
final class RoadmapTaskVoter extends Voter
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

    public const string VIEW = 'view';
    public const string EDIT = 'edit';
    public const string DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof RoadmapTask;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var RoadmapTask $roadmapTask */
        $roadmapTask = $subject;

        if ($this->hasRoleHierarchical($user, 'ROLE_SUPER_ADMIN')) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($roadmapTask, $user),
            self::EDIT, self::DELETE => $this->canManage($roadmapTask, $user),
            default => false,
        };
    }

    private function canView(RoadmapTask $roadmapTask, User $user): bool
    {
        if ($roadmapTask->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant) {
            return true;
        }
        return $this->canReadAcrossHoldingTree($user, $roadmapTask->getTenant());
    }

    private function canManage(RoadmapTask $roadmapTask, User $user): bool
    {
        if (!$this->hasRoleHierarchical($user, 'ROLE_MANAGER')) {
            return false;
        }
        return $roadmapTask->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant;
    }

    private function hasRoleHierarchical(User $user, string $role): bool
    {
        $reachable = $this->roleHierarchy->getReachableRoleNames($user->getRoles());
        return in_array($role, $reachable, true);
    }
}
