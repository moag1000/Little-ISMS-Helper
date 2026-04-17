<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tag;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorization for the polymorphic tagging subsystem (WS-5, Anhang A).
 *
 *  - TAG_APPLY       → MANAGER+ (bulk or single apply).
 *  - TAG_REMOVE      → MANAGER+ (single soft-delete).
 *  - TAG_REMOVE_BULK → MANAGER+ *and* 4-eyes-required (handled by
 *    `FourEyesApprovalService` per DATA_REUSE_IMPROVEMENT_PLAN Anhang A).
 *    The voter only confirms the role precondition; the controller must
 *    route bulk-remove requests through the 4-eyes workflow.
 *  - TAG_MANAGE      → ADMIN+ (CRUD on Tag master data).
 */
final class TagVoter extends Voter
{
    public const TAG_APPLY = 'TAG_APPLY';
    public const TAG_REMOVE = 'TAG_REMOVE';
    public const TAG_REMOVE_BULK = 'TAG_REMOVE_BULK';
    public const TAG_MANAGE = 'TAG_MANAGE';

    private const ATTRIBUTES = [
        self::TAG_APPLY,
        self::TAG_REMOVE,
        self::TAG_REMOVE_BULK,
        self::TAG_MANAGE,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, self::ATTRIBUTES, true)) {
            return false;
        }
        return $subject === null || $subject instanceof Tag;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();
        $isAdmin = in_array('ROLE_ADMIN', $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $roles, true);
        $isManagerOrAdmin = $isAdmin || in_array('ROLE_MANAGER', $roles, true);

        return match ($attribute) {
            self::TAG_APPLY,
            self::TAG_REMOVE,
            self::TAG_REMOVE_BULK => $isManagerOrAdmin,
            self::TAG_MANAGE => $isAdmin,
            default => false,
        };
    }
}
