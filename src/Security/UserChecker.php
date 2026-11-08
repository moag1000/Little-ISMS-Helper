<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The one account-status check for every way in: form login, SSO (Azure
 * OAuth/SAML, generic OIDC), remember-me, API token and impersonation. A
 * deactivated user is refused everywhere ("Account is disabled."). A browser
 * session that was open when the account was deactivated is ended by
 * {@see \App\EventSubscriber\DeactivatedUserSubscriber}; the user's API tokens
 * are revoked on deactivation by {@see \App\EventListener\RevokeApiTokensOnDeactivationListener}.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        self::assertActive($user);
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        self::assertActive($user);
    }

    public static function assertActive(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isActive()) {
            $exception = new DisabledException('Account is disabled.');
            $exception->setUser($user);

            throw $exception;
        }
    }
}
