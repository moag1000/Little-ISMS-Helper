<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\EvidenceReverificationTask;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * F4 Evidence-Versioning — EvidenceReverificationTask voter.
 *
 * Rules:
 *  - VIEW: any authenticated user of the same tenant.
 *  - COMPLETE: the assignee OR any MANAGER+ of the same tenant.
 *  - SKIP: same as COMPLETE.
 *  - DELETE: ADMIN+ of the same tenant.
 */
final class EvidenceReverificationTaskVoter extends Voter
{
    public const string VIEW = 'view';
    public const string COMPLETE = 'complete';
    public const string SKIP = 'skip';
    public const string DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::COMPLETE, self::SKIP, self::DELETE])
            && $subject instanceof EvidenceReverificationTask;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var EvidenceReverificationTask $task */
        $task = $subject;

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $taskTenant = $task->getTenant();
        $userTenant = $user->getTenant();

        if ($taskTenant === null || $userTenant === null) {
            return false;
        }

        // Cross-tenant access is never permitted
        if ($taskTenant->getId() !== $userTenant->getId()) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::COMPLETE, self::SKIP => $this->canCompleteOrSkip($task, $user, $token),
            self::DELETE => $this->hasRole($token, 'ROLE_ADMIN'),
            default => false,
        };
    }

    private function canCompleteOrSkip(EvidenceReverificationTask $task, User $user, TokenInterface $token): bool
    {
        // Assignee can always complete/skip their own task
        if ($task->getAssignedTo()?->getId() === $user->getId()) {
            return true;
        }
        // MANAGER+ can complete/skip any task in their tenant
        return $this->hasRole($token, 'ROLE_MANAGER');
    }

    private function hasRole(TokenInterface $token, string $role): bool
    {
        foreach ($token->getRoleNames() as $r) {
            if ($r === $role) {
                return true;
            }
        }
        // Check via hierarchy — Symfony stores effective roles in the token
        return in_array($role, $token->getRoleNames(), true);
    }
}
