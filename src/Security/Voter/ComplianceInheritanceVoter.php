<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\FulfillmentInheritanceLog;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorization for inheritance workflow. Matrix defined in
 * docs/DATA_REUSE_IMPROVEMENT_PLAN.md Anhang A.
 */
final class ComplianceInheritanceVoter extends Voter
{
    public const VIEW = 'compliance_inheritance.view';
    public const CREATE_SUGGESTIONS = 'compliance_inheritance.create';
    public const CONFIRM = 'compliance_inheritance.confirm';
    public const REJECT = 'compliance_inheritance.reject';
    public const OVERRIDE = 'compliance_inheritance.override';
    public const APPROVE_IMPLEMENT = 'compliance_inheritance.approve_implement';

    private const ATTRIBUTES = [
        self::VIEW,
        self::CREATE_SUGGESTIONS,
        self::CONFIRM,
        self::REJECT,
        self::OVERRIDE,
        self::APPROVE_IMPLEMENT,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, self::ATTRIBUTES, true)) {
            return false;
        }
        return $subject === null || $subject instanceof FulfillmentInheritanceLog;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();
        $isAuditorOrAbove = in_array('ROLE_AUDITOR', $roles, true)
            || in_array('ROLE_MANAGER', $roles, true)
            || in_array('ROLE_ADMIN', $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $roles, true);
        $isManagerOrAdmin = in_array('ROLE_MANAGER', $roles, true)
            || in_array('ROLE_ADMIN', $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $roles, true);

        return match ($attribute) {
            // AUDITOR gets read-only per Plan Anhang A (line 568–596).
            self::VIEW => $isAuditorOrAbove,
            // Write actions: MANAGER/ADMIN only. Plan explicitly excludes AUDITOR
            // from write operations to preserve read-only consistency.
            self::CREATE_SUGGESTIONS,
            self::CONFIRM,
            self::REJECT,
            self::OVERRIDE,
            self::APPROVE_IMPLEMENT => $isManagerOrAdmin,
            default => false,
        };
    }
}
