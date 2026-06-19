<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ComplianceCertificate;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Authorization voter for ComplianceCertificate records.
 *
 * Rules (all gated on same-tenant access):
 *  - CERT_VIEW:   ROLE_AUDITOR and above (auditor, manager, admin, …).
 *  - CERT_MANAGE: ROLE_MANAGER and above (create / upload / apply).
 *  - CERT_DELETE: ROLE_ADMIN and above.
 *
 * Cross-tenant access is never permitted regardless of role.
 */
final class ComplianceCertificateVoter extends Voter
{
    public const string CERT_VIEW = 'CERT_VIEW';
    public const string CERT_MANAGE = 'CERT_MANAGE';
    public const string CERT_DELETE = 'CERT_DELETE';

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // A null subject supports class-level role gating (#[IsGranted('CERT_VIEW')]
        // on the controller, where no certificate instance is available yet).
        return in_array($attribute, [self::CERT_VIEW, self::CERT_MANAGE, self::CERT_DELETE], true)
            && ($subject === null || $subject instanceof ComplianceCertificate);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Subject-bound checks additionally enforce same-tenant access. The
        // null-subject path is the role-only gate used at class level.
        if ($subject instanceof ComplianceCertificate) {
            $certTenant = $subject->getTenant();
            $userTenant = $user->getTenant();

            if ($certTenant === null || $userTenant === null) {
                return false;
            }

            // Cross-tenant access is never permitted.
            if ($certTenant->getId() !== $userTenant->getId()) {
                return false;
            }
        }

        return match ($attribute) {
            self::CERT_VIEW => $this->hasRole($token, 'ROLE_AUDITOR'),
            self::CERT_MANAGE => $this->hasRole($token, 'ROLE_MANAGER'),
            self::CERT_DELETE => $this->hasRole($token, 'ROLE_ADMIN'),
            default => false,
        };
    }

    private function hasRole(TokenInterface $token, string $role): bool
    {
        $reachable = $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());

        return in_array($role, $reachable, true);
    }
}
