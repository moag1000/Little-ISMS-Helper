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
 * deactivated user — or a member of a deactivated organisation — is refused
 * everywhere ("Account is disabled." / "Organisation is disabled."). A browser
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
        $reason = self::refusal($user);
        if ($reason !== null) {
            $exception = new DisabledException($reason);
            $exception->setUser($user);

            throw $exception;
        }
    }

    /**
     * Why this account may not get in, or null. A deactivated organisation
     * (suspended, terminated, archived) shuts out its members on every way in;
     * the instance operator (ROLE_SUPER_ADMIN) keeps access to reactivate it.
     */
    public static function refusal(UserInterface $user): ?string
    {
        if (!$user instanceof User) {
            return null;
        }
        if (!$user->isActive()) {
            return 'Account is disabled.';
        }
        $tenant = $user->getTenant();
        if ($tenant !== null && !$tenant->isActive() && !in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return 'Organisation is disabled.';
        }

        return null;
    }
}
