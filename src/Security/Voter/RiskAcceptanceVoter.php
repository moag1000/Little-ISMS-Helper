<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\RiskApprovalConfigResolver;
use App\Service\RiskApprovalConfigView;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Who may approve or reject a requested risk acceptance (ISO 27005 §8.4.4).
 *
 * Uses the same tiers that decide who is asked in the first place
 * (admin → risk approval thresholds, per organisation, holding ceilings
 * applied): residual score up to the manager threshold → ROLE_MANAGER,
 * above it → ROLE_ADMIN. Role hierarchy applies, and the approver must
 * belong to the risk's organisation.
 */
final class RiskAcceptanceVoter extends Voter
{
    public const string APPROVE = 'risk_acceptance_approve';

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly RiskApprovalConfigResolver $approvalConfig,
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
        $tenant = $risk->getTenant();
        if ($tenant instanceof Tenant && $user->getTenant()?->getId() !== $tenant->getId()
            && !$this->hasRole($user, 'ROLE_SUPER_ADMIN')) {
            return false;
        }

        $config = $tenant instanceof Tenant ? $this->approvalConfig->resolveFor($tenant) : RiskApprovalConfigView::defaults();

        return $this->hasRole($user, RiskApprovalConfigView::approverRole($config->levelFor($risk->getResidualRiskLevel())));
    }

    /** True when the user holds $requiredRole directly or through the role hierarchy. */
    private function hasRole(User $user, string $requiredRole): bool
    {
        return in_array($requiredRole, $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true);
    }
}
