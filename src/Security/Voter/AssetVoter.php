<?php

namespace App\Security\Voter;

use App\Entity\Asset;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Security: Asset Access Control Voter
 *
 * Implements fine-grained access control for Asset entities with multi-tenancy support.
 */
class AssetVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Asset;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Security: User must be authenticated
        if (!$user instanceof User) {
            return false;
        }

        /** @var Asset $asset */
        $asset = $subject;

        // Security: Admins can do everything
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($asset, $user),
            self::EDIT => $this->canEdit($asset, $user),
            self::DELETE => $this->canDelete($asset, $user),
            default => false,
        };
    }

    private function canView(Asset $asset, User $user): bool
    {
        // Security: Multi-tenancy - users can view assets from their tenant
        return $asset->getTenant() === $user->getTenant() && $user->getTenant() !== null;
    }

    private function canEdit(Asset $asset, User $user): bool
    {
        // Security: Only users from same tenant can edit
        return $asset->getTenant() === $user->getTenant() && $user->getTenant() !== null;
    }

    private function canDelete(Asset $asset, User $user): bool
    {
        // Security: Only admins can delete
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
