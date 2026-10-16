<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Training Voter — tenant isolation for Training records (OWASP A01).
 *
 * VIEW / EDIT: the training belongs to the user's own tenant (ROLE_ADMIN
 * bypasses, as in RiskVoter / AssetVoter). Without a dedicated voter only the
 * permission-based EntityVoter answered, which requires an explicit
 * `training.edit` permission no built-in role carries — so per-record checks
 * such as the bulk "assign" action rejected every manager.
 */
final class TrainingVoter extends Voter
{
    public const string VIEW = 'view';
    public const string EDIT = 'edit';

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof Training;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Training) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true)) {
            return true;
        }

        return $user->getTenant() instanceof Tenant && $subject->getTenant() === $user->getTenant();
    }
}
