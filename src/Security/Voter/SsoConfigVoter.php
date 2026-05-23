<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\IdentityProvider;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Only tenant-admin (ROLE_ADMIN, same tenant) or ROLE_SUPER_ADMIN may change
 * SSO configuration (client secrets, enforcement flag, role mappings).
 *
 * Controllers call:
 *   $this->denyAccessUnlessGranted(SsoConfigVoter::CONFIGURE, $provider)
 */
final class SsoConfigVoter extends Voter
{
    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    public const string CONFIGURE = 'sso_configure';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::CONFIGURE && $subject instanceof IdentityProvider;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $reachableRoles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        if (in_array('ROLE_SUPER_ADMIN', $reachableRoles, true)) {
            return true;
        }

        if (!in_array('ROLE_ADMIN', $reachableRoles, true)) {
            return false;
        }

        /** @var IdentityProvider $idp */
        $idp = $subject;

        $idpTenantId  = $idp->getTenant()?->getId();
        $userTenantId = $user->getTenant()?->getId();

        // Global IdP config — only SUPER_ADMIN (handled above)
        if ($idpTenantId === null) {
            return false;
        }

        return $idpTenantId === $userTenantId;
    }
}
