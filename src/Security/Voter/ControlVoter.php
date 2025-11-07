<?php

namespace App\Security\Voter;

use App\Entity\Control;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Security: Control Access Control Voter
 *
 * Implements fine-grained access control for Control entities with multi-tenancy support.
 */
class ControlVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Control;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Security: User must be authenticated
        if (!$user instanceof User) {
            return false;
        }

        /** @var Control $control */
        $control = $subject;

        // Security: Admins can do everything
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($control, $user),
            self::EDIT => $this->canEdit($control, $user),
            self::DELETE => $this->canDelete($control, $user),
            default => false,
        };
    }

    private function canView(Control $control, User $user): bool
    {
        // Security: Multi-tenancy - users can view controls from their tenant
        return $control->getTenant() === $user->getTenant() && $user->getTenant() !== null;
    }

    private function canEdit(Control $control, User $user): bool
    {
        // Security: Users can edit controls from their tenant
        // Could be extended with control owner checks
        return $control->getTenant() === $user->getTenant() && $user->getTenant() !== null;
    }

    private function canDelete(Control $control, User $user): bool
    {
        // Security: Only admins can delete
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
