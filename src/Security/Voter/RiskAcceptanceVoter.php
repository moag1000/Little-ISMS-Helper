<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Risk;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * RiskAcceptanceVoter
 *
 * Controls who may formally accept (approve) a risk treatment decision.
 * Implements score-tier based minimum-role checks per ISO 31000 §6.5.4:
 *
 *  Score  1– 6  → ROLE_USER      (low risk — self-service)
 *  Score  7–12  → ROLE_MANAGER   (medium risk — manager sign-off)
 *  Score 13–19  → ROLE_ADMIN     (high risk — senior approval)
 *  Score 20–25  → ROLE_SUPER_ADMIN (critical risk — executive sign-off)
 */
// Junior-ISB-Audit-2026-05-22 #582-followup: RiskAcceptanceVoter hierarchy-aware
final class RiskAcceptanceVoter extends Voter
{
    public const string APPROVE = 'risk_acceptance_approve';

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::APPROVE && $subject instanceof Risk;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Risk $risk */
        $risk = $subject;

        $score = $risk->getRiskScore();
        $requiredRole = $this->resolveRequiredRole($score);

        return $this->hasRole($user, $requiredRole);
    }

    /**
     * Returns the minimum role required to approve acceptance for a given score.
     */
    private function resolveRequiredRole(int $score): string
    {
        return match (true) {
            $score >= 20 => 'ROLE_SUPER_ADMIN',
            $score >= 13 => 'ROLE_ADMIN',
            $score >= 7  => 'ROLE_MANAGER',
            default      => 'ROLE_USER',
        };
    }

    /**
     * Returns true when the user holds $requiredRole or any role above it in
     * Symfony's `role_hierarchy` (ROLE_ADMIN reaches ROLE_MANAGER, etc.).
     *
     * Expands the user's directly assigned roles via the configured
     * RoleHierarchyInterface so an admin with only `ROLE_ADMIN` in the DB row
     * still satisfies subordinate role checks (`ROLE_MANAGER`, `ROLE_USER`).
     */
    private function hasRole(User $user, string $requiredRole): bool
    {
        $reachable = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        return in_array($requiredRole, $reachable, true);
    }
}
