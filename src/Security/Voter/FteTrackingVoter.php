<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Fte\FteTrackingMetric;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * F11 FTE-Tracking security voter.
 *
 * Rules:
 *  - VIEW:       ROLE_MANAGER (own tenant data only)
 *  - CALIBRATE:  ROLE_ADMIN (own tenant only)
 *
 * Subject may be an FteTrackingMetric or null (for non-entity checks).
 * When subject is null, tenant isolation is enforced by TenantContext in the controller.
 */
final class FteTrackingVoter extends Voter
{
    public const string VIEW = 'fte_tracking_view';
    public const string CALIBRATE = 'fte_tracking_calibrate';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::CALIBRATE], true)
            && ($subject === null || $subject instanceof FteTrackingMetric);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($subject instanceof FteTrackingMetric) {
            $metricTenant = $subject->getTenant();
            $userTenant = $user->getTenant();

            if ($userTenant === null || $metricTenant->getId() !== $userTenant->getId()) {
                return false;
            }
        }

        return match ($attribute) {
            self::VIEW => $this->hasRole($token, 'ROLE_MANAGER'),
            self::CALIBRATE => $this->hasRole($token, 'ROLE_ADMIN'),
            default => false,
        };
    }

    private function hasRole(TokenInterface $token, string $role): bool
    {
        return in_array($role, $token->getRoleNames(), true);
    }
}
