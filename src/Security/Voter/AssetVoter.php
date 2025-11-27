<?php

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\Asset;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Asset Voter
 *
 * Implements fine-grained authorization for Asset entity operations.
 * Enforces strict multi-tenancy isolation to prevent cross-tenant data access.
 *
 * Supported Operations:
 * - VIEW: View asset details
 * - EDIT: Modify asset information
 * - DELETE: Remove asset (admin only)
 *
 * Security Rules:
 * - ROLE_ADMIN bypasses all checks (can access all tenants)
 * - Users can only access assets from their own tenant
 * - Tenant isolation is strictly enforced (tenant !== null required)
 * - Only admins can delete assets
 *
 * Multi-tenancy:
 * - Implements OWASP A1: Broken Access Control prevention
 * - Tenant validation on all operations
 * - Prevents horizontal privilege escalation
 */
class AssetVoter extends Voter
{
    public const string VIEW = 'view';
    public const string EDIT = 'edit';
    public const string DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Asset;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
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
            self::DELETE => $this->canDelete($user),
            default => false,
        };
    }

    private function canView(Asset $asset, User $user): bool
    {
        // Security: Multi-tenancy - users can view assets from their tenant
        return $asset->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant;
    }

    private function canEdit(Asset $asset, User $user): bool
    {
        // Security: Only users from same tenant can edit
        return $asset->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant;
    }

    private function canDelete(User $user): bool
    {
        // Security: Only admins can delete
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
