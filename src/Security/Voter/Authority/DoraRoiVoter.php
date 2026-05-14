<?php

declare(strict_types=1);

namespace App\Security\Voter\Authority;

use App\Entity\Authority\DoraRegisterOfInformation;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * F30 — Voter for DORA Register of Information (RoI).
 *
 * Permissions:
 *  - VIEW          ROLE_MANAGER (read submission list + index page)
 *  - EXPORT        ROLE_MANAGER (generate + download XBRL)
 *  - MARK_SUBMITTED ROLE_ADMIN  (record authority confirmation number)
 *
 * Tenant isolation is enforced via TenantContext — the controller resolves
 * the record from the current tenant before calling this voter.
 */
class DoraRoiVoter extends Voter
{
    public const string VIEW           = 'view';
    public const string EXPORT         = 'export';
    public const string MARK_SUBMITTED = 'mark_submitted';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EXPORT, self::MARK_SUBMITTED], true)
            && $subject instanceof DoraRegisterOfInformation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VIEW, self::EXPORT  => $this->hasRole($user, 'ROLE_MANAGER'),
            self::MARK_SUBMITTED      => $this->hasRole($user, 'ROLE_ADMIN'),
            default                   => false,
        };
    }

    private function hasRole(User $user, string $role): bool
    {
        return in_array($role, $user->getRoles(), true);
    }
}
