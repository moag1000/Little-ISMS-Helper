<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\IdentityProvider;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Tenant-scoped authorization for IdentityProvider entities.
 *
 * VIEW  — same tenant, any authenticated role
 * EDIT  — ROLE_ADMIN same tenant (or ROLE_SUPER_ADMIN for global)
 * DELETE — ROLE_ADMIN only
 *
 * Module gate: authentication module must be active. The controller
 * enforces this via ModuleGatedControllerTrait; the voter only checks
 * ownership/role.
 */
final class IdentityProviderVoter extends Voter
{
    public const string VIEW   = 'view';
    public const string EDIT   = 'edit';
    public const string DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof IdentityProvider;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var IdentityProvider $idp */
        $idp = $subject;

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $idpTenantId  = $idp->getTenant()?->getId();
        $userTenantId = $user->getTenant()?->getId();

        // Global IdP — only SUPER_ADMIN can access (handled above)
        if ($idpTenantId === null) {
            return false;
        }

        // Tenant isolation
        if ($idpTenantId !== $userTenantId) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => true,
            self::EDIT   => in_array('ROLE_ADMIN', $user->getRoles(), true),
            self::DELETE => in_array('ROLE_ADMIN', $user->getRoles(), true),
            default      => false,
        };
    }
}
