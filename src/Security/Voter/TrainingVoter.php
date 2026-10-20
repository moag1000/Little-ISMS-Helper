<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Training;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Training Voter — tenant isolation for Training records (OWASP A01).
 *
 * VIEW / EDIT: the training belongs to the user's own tenant (holding roles
 * may VIEW descendants; only ROLE_SUPER_ADMIN crosses tenants). Without a dedicated voter only the
 * permission-based EntityVoter answered, which requires an explicit
 * `training.edit` permission no built-in role carries — so per-record checks
 * such as the bulk "assign" action rejected every manager.
 */
final class TrainingVoter extends Voter
{
    use HoldingTreeAccessTrait;

    public const string VIEW = 'view';
    public const string EDIT = 'edit';

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    protected function getRoleHierarchy(): RoleHierarchyInterface
    {
        return $this->roleHierarchy;
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

        // SUPER_ADMIN everywhere; everyone else (ROLE_ADMIN included) only
        // inside the own tenant, holding roles read-only down the tree.
        return $this->decideTenantScope($user, $subject->getTenant(), $attribute === self::VIEW) ?? true;
    }
}
