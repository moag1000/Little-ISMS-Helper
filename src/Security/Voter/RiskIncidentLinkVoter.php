<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\RiskIncidentLink;
use App\Entity\Tenant;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * RiskIncidentLinkVoter — Sprint 9B / F16
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
    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
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

        $reachableRoles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        if (in_array('ROLE_ADMIN', $reachableRoles, true)) {
            return true;
        }

        $sameTenant = $link->getTenant() instanceof Tenant
            && $link->getTenant() === $user->getTenant();

        return match ($attribute) {
            self::VIEW   => $sameTenant && in_array('ROLE_USER', $reachableRoles, true),
            self::CREATE,
            self::DELETE => $sameTenant && in_array('ROLE_MANAGER', $reachableRoles, true),
            default      => false,
        };
    }
}
