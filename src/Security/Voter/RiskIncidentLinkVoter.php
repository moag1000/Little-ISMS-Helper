<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\RiskIncidentLink;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * RiskIncidentLinkVoter
 *
 * Supported attributes:
 *   VIEW   — ROLE_USER, same tenant
 *   CREATE — ROLE_MANAGER, same tenant
 *   DELETE — ROLE_MANAGER, same tenant
 *
 * Note: attribute strings are used directly in controller IsGranted checks
 * as 'risk_incident_link_view', 'risk_incident_link_create',
 * 'risk_incident_link_delete'.
 */
final class RiskIncidentLinkVoter extends Voter
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

    public const string VIEW   = 'risk_incident_link_view';
    public const string CREATE = 'risk_incident_link_create';
    public const string DELETE = 'risk_incident_link_delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::CREATE, self::DELETE], true)
            && $subject instanceof RiskIncidentLink;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var RiskIncidentLink $link */
        $link = $subject;

        // Tenant scope: SUPER_ADMIN everywhere, ROLE_ADMIN only inside its
        // own tenant, holding roles read-only down the tree.
        $scope = $this->decideTenantScope($user, $link->getTenant(), $attribute === self::VIEW);
        if ($scope !== null) {
            return $scope;
        }

        return match ($attribute) {
            self::VIEW   => $this->hasReachableRole($user, 'ROLE_USER'),
            self::CREATE,
            self::DELETE => $this->hasReachableRole($user, 'ROLE_MANAGER'),
            default      => false,
        };
    }
}
