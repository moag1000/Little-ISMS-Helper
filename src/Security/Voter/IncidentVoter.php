<?php

namespace App\Security\Voter;

use App\Entity\Incident;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Security: Incident Access Control Voter
 *
 * Implements fine-grained access control for Incident entities with multi-tenancy support.
 */
class IncidentVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Incident;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Security: User must be authenticated
        if (!$user instanceof User) {
            return false;
        }

        /** @var Incident $incident */
        $incident = $subject;

        // Security: Admins can do everything
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($incident, $user),
            self::EDIT => $this->canEdit($incident, $user),
            self::DELETE => $this->canDelete($incident, $user),
            default => false,
        };
    }

    private function canView(Incident $incident, User $user): bool
    {
        // Security: Multi-tenancy - users can view incidents from their tenant
        return $incident->getTenant() === $user->getTenant() && $user->getTenant() !== null;
    }

    private function canEdit(Incident $incident, User $user): bool
    {
        // Security: Users can edit incidents from their tenant
        // Could be extended with reporter/assigned user checks
        return $incident->getTenant() === $user->getTenant() && $user->getTenant() !== null;
    }

    private function canDelete(Incident $incident, User $user): bool
    {
        // Security: Only admins can delete
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
