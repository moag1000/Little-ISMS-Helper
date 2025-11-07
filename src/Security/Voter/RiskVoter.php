<?php

namespace App\Security\Voter;

use App\Entity\Risk;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Security: Risk Access Control Voter
 *
 * Implements fine-grained access control for Risk entities with multi-tenancy support.
 */
class RiskVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Risk;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Security: User must be authenticated
        if (!$user instanceof User) {
            return false;
        }

        /** @var Risk $risk */
        $risk = $subject;

        // Security: Admins can do everything
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($risk, $user),
            self::EDIT => $this->canEdit($risk, $user),
            self::DELETE => $this->canDelete($risk, $user),
            default => false,
        };
    }

    private function canView(Risk $risk, User $user): bool
    {
        // Security: Multi-tenancy - users can view risks from their tenant
        return $risk->getTenant() === $user->getTenant() && $user->getTenant() !== null;
    }

    private function canEdit(Risk $risk, User $user): bool
    {
        // Security: Users can edit risks from their tenant
        // Could be extended with risk owner checks
        return $risk->getTenant() === $user->getTenant() && $user->getTenant() !== null;
    }

    private function canDelete(Risk $risk, User $user): bool
    {
        // Security: Only admins can delete
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
