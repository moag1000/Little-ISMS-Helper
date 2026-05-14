<?php

declare(strict_types=1);

namespace App\Security\Voter\Authority;

use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * F29 — Voter for NIS-2 BSI Registration Profile.
 *
 * Permissions:
 *  - VIEW          ROLE_MANAGER (read profile + export page)
 *  - EDIT          ROLE_MANAGER (fill mandatory fields)
 *  - EXPORT        ROLE_MANAGER (download JSON)
 *  - MARK_REPORTED ROLE_ADMIN   (confirm BSI portal submission)
 *
 * Tenant isolation is enforced via TenantContext — the controller
 * resolves the profile from the current tenant before calling this voter.
 */
class Nis2RegistrationProfileVoter extends Voter
{
    public const string VIEW          = 'view';
    public const string EDIT          = 'edit';
    public const string EXPORT        = 'export';
    public const string MARK_REPORTED = 'mark_reported';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::EXPORT, self::MARK_REPORTED], true)
            && $subject instanceof Nis2RegistrationProfile;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VIEW, self::EDIT, self::EXPORT => $this->hasRole($user, 'ROLE_MANAGER'),
            self::MARK_REPORTED                  => $this->hasRole($user, 'ROLE_ADMIN'),
            default                              => false,
        };
    }

    private function hasRole(User $user, string $role): bool
    {
        return in_array($role, $user->getRoles(), true);
    }
}
