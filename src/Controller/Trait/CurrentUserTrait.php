<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\User;

/**
 * Provides a type-safe accessor for the authenticated concrete User.
 *
 * Callers MUST be protected by an authentication gate — either a class-level
 * or method-level `#[IsGranted('ROLE_USER')]` attribute (or stricter).
 * The assert() makes the contract explicit and resolves the PHPStan
 * `App\Entity\User|null` → `App\Entity\User` mismatch at call-sites where
 * the result is passed to service methods typed `User` (non-nullable).
 */
trait CurrentUserTrait
{
    /**
     * Returns the authenticated concrete User.
     * Callers MUST be behind an authentication gate (`#[IsGranted('ROLE_USER')]`
     * or stricter). assert() makes the contract explicit and fixes the
     * PHPStan UserInterface|null → User mismatch.
     */
    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
