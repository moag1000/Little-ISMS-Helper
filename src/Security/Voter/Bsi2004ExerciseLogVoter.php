<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Bsi2004ExerciseLog;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Authorization voter for Bsi2004ExerciseLog.
 *
 * VIEW    — ROLE_MANAGER (same tenant); holding roles down the tree
 * EDIT    — ROLE_MANAGER (same tenant, not yet submitted)
 * CONFIRM — ROLE_AUDITOR (same tenant)
 * DELETE  — ROLE_ADMIN (same tenant)
 *
 * ROLE_SUPER_ADMIN is the only role that crosses tenants.
 */
final class Bsi2004ExerciseLogVoter extends Voter
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

    public const string VIEW    = 'VIEW';
    public const string EDIT    = 'EDIT';
    public const string CONFIRM = 'CONFIRM';
    public const string DELETE  = 'DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::CONFIRM, self::DELETE], true)
            && $subject instanceof Bsi2004ExerciseLog;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Bsi2004ExerciseLog $log */
        $log = $subject;

        // Tenant scope: SUPER_ADMIN everywhere, ROLE_ADMIN only inside its
        // own tenant, holding roles read-only down the tree.
        $scope = $this->decideTenantScope($user, $log->getTenant(), $attribute === self::VIEW);
        if ($scope !== null) {
            return $scope;
        }

        return match ($attribute) {
            self::VIEW    => $this->hasReachableRole($user, 'ROLE_MANAGER')
                || $this->canReadAcrossHoldingTree($user, $log->getTenant()),
            self::EDIT    => $this->hasReachableRole($user, 'ROLE_MANAGER') && !$log->isSubmitted(),
            self::CONFIRM => $this->hasReachableRole($user, 'ROLE_AUDITOR'),
            self::DELETE  => false,
            default       => false,
        };
    }
}
