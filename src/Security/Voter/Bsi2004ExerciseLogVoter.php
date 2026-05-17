<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Bsi2004ExerciseLog;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorization voter for Bsi2004ExerciseLog.
 *
 * VIEW    — ROLE_MANAGER (same tenant)
 * EDIT    — ROLE_MANAGER (same tenant, not yet submitted)
 * CONFIRM — ROLE_AUDITOR (same tenant)
 * DELETE  — ROLE_ADMIN
 */
final class Bsi2004ExerciseLogVoter extends Voter
{
    public const string VIEW    = 'VIEW';
    public const string EDIT    = 'EDIT';
    public const string CONFIRM = 'CONFIRM';
    public const string DELETE  = 'DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::CONFIRM, self::DELETE], true)
            && $subject instanceof Bsi2004ExerciseLog;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Bsi2004ExerciseLog $log */
        $log = $subject;

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW    => $this->isSameTenant($log, $user) && $this->hasRole($user, 'ROLE_MANAGER'),
            self::EDIT    => $this->isSameTenant($log, $user) && $this->hasRole($user, 'ROLE_MANAGER') && !$log->isSubmitted(),
            self::CONFIRM => $this->isSameTenant($log, $user) && $this->hasRole($user, 'ROLE_AUDITOR'),
            self::DELETE  => false,
            default       => false,
        };
    }

    private function isSameTenant(Bsi2004ExerciseLog $log, User $user): bool
    {
        return $log->getTenant() !== null
            && $log->getTenant() === $user->getTenant();
    }

    private function hasRole(User $user, string $role): bool
    {
        return in_array($role, $user->getRoles(), true);
    }
}
