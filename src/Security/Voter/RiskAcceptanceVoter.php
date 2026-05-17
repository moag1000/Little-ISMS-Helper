<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Risk;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

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
final class RiskAcceptanceVoter extends Voter
{
    public const string APPROVE = 'risk_acceptance_approve';

    /** Role hierarchy: lower index = more privileged. */
    private const array ROLE_HIERARCHY = [
        'ROLE_SUPER_ADMIN',
        'ROLE_ADMIN',
        'ROLE_MANAGER',
        'ROLE_USER',
    ];

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

        return $this->userHasMinRole($user, $requiredRole);
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
     * the hierarchy (ROLE_ADMIN satisfies ROLE_MANAGER, etc.).
     */
    private function userHasMinRole(User $user, string $requiredRole): bool
    {
        $userRoles = $user->getRoles();
        $requiredIndex = array_search($requiredRole, self::ROLE_HIERARCHY, true);

        foreach (self::ROLE_HIERARCHY as $index => $role) {
            if ($index <= $requiredIndex && in_array($role, $userRoles, true)) {
                return true;
            }
        }

        return false;
    }
}
