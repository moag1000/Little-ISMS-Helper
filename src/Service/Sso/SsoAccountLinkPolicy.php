<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\IdentityProviderRoleMapping;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\RoleNames;
use App\Security\TenantOwnership;
use App\Security\UserAdministrationGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Trust boundary for SSO logins (security hotfix, admin scope).
 *
 * A tenant-owned IdentityProvider is configured by a tenant admin, who
 * controls issuer, JWKS and therefore every claim (email, sub, groups). Such
 * a provider must not be able to:
 *  - log in as / link to an account outside the provider's tenant tree,
 *  - log in as / link to an account holding a privileged role
 *    ({@see RoleNames::PRIVILEGED}), or
 *  - hand out a privileged role through claim→role mapping or default roles.
 *
 * Global providers (tenant = null) can only be configured by a
 * ROLE_SUPER_ADMIN (instance operator) and stay unrestricted.
 */
final class SsoAccountLinkPolicy
{
    public const string EMAIL_LINKING_DISABLED_MESSAGE = 'This sign-in could not be linked to an existing account'
        . ' automatically. Sign in with your password if your account has one, or contact an administrator.';

    private function __construct()
    {
    }

    /**
     * May a login through $provider resolve to the existing local account
     * $target (link by email, or an existing provider+sub binding)?
     */
    public static function mayAuthenticateAs(IdentityProvider $provider, User $target): bool
    {
        $providerTenant = $provider->getTenant();
        if (!$providerTenant instanceof Tenant) {
            return true;
        }
        if (UserAdministrationGuard::privilegedRolesIn($target->getRoles()) !== []) {
            return false;
        }
        // Provider's organisation or below (id-safe tree comparison; a
        // tenantless target fails closed).
        return TenantOwnership::isSameOrDescendant($providerTenant, $target->getTenant());
    }

    /**
     * Link-by-email gate: an SSO login whose e-mail matches an existing
     * local account that is not yet bound to this provider+subject is only
     * linked when {@see mayLinkByEmail()} allows it (provider option on, account
     * not bound to another provider). Otherwise the login is refused; the
     * password login stays available (the enforcement subscribers fail open,
     * {@see canSsoLoginReach()}). Runs in addition to {@see mayAuthenticateAs()}.
     *
     * @throws CustomUserMessageAuthenticationException when linking is disabled
     */
    public static function assertMayLinkByEmail(
        IdentityProvider $provider,
        User $target,
        ?LoggerInterface $logger = null,
        ?string $externalId = null,
    ): void {
        if (self::mayLinkByEmail($provider, $target, $externalId)) {
            return;
        }
        $logger?->warning('SSO: login refused, automatic link by e-mail is not allowed for this account', [
            'provider' => $provider->getSlug(),
            'target_user_id' => $target->getId(),
        ]);

        throw new CustomUserMessageAuthenticationException(self::EMAIL_LINKING_DISABLED_MESSAGE);
    }

    /**
     * Would {@see assertMayLinkByEmail()} accept linking $target? Only when
     * the provider allows e-mail linking and the account is not bound to a
     * different provider (an existing binding is never moved silently).
     */
    public static function mayLinkByEmail(IdentityProvider $provider, User $target, ?string $externalId = null): bool
    {
        $boundProvider = $target->getSsoProvider();

        return $provider->isAllowEmailLinking()
            && ($boundProvider === null || self::isSameProvider($boundProvider, $provider))
            && !self::isSubjectChange($provider, $target, $externalId);
    }

    /**
     * $target is bound to $provider under a DIFFERENT subject id than the one
     * now presented with the same e-mail claim. Never re-linked silently — an
     * IdP subject change needs admin action; callers log a security event (#1059).
     */
    public static function isSubjectChange(IdentityProvider $provider, User $target, ?string $externalId): bool
    {
        $boundProvider = $target->getSsoProvider();
        $boundSubject = $target->getSsoExternalId();

        return $externalId !== null && $boundProvider !== null && self::isSameProvider($boundProvider, $provider)
            && $boundSubject !== null && $boundSubject !== $externalId;
    }

    /**
     * The provider an SSO-enforced password login is redirected to, or null
     * when no enabled provider can sign $user in (then the password login
     * stays available — anti-lockout, #1059):
     *  1. the enforced provider, when it can reach the user;
     *  2. otherwise the provider the user is already bound to, when it is
     *     enabled, has a slug and may act for the user (global, or the user's
     *     own organisation tree) — a binding to another provider must not
     *     turn enforcement into a password login.
     */
    public static function resolveLoginProvider(IdentityProvider $enforced, User $user): ?IdentityProvider
    {
        if (self::canSsoLoginReach($enforced, $user)) {
            return $enforced;
        }
        $bound = $user->getSsoProvider();
        if ($bound instanceof IdentityProvider
            && !self::isSameProvider($bound, $enforced)
            && $bound->isEnabled()
            && (string) $bound->getSlug() !== ''
            && self::mayAuthenticateAs($bound, $user)
        ) {
            return $bound;
        }

        return null;
    }

    /**
     * Can an SSO login through $provider end up as $user at all? Used by the
     * SSO-enforcement subscribers: when it cannot (account not bound to this
     * provider and linking refused, or a privileged / out-of-tree account of a
     * tenant-owned provider), redirecting the password login to the provider
     * would lock the user out, so the password login stays available (#1059).
     */
    public static function canSsoLoginReach(IdentityProvider $provider, User $user): bool
    {
        if (!self::mayAuthenticateAs($provider, $user)) {
            return false;
        }
        $boundProvider = $user->getSsoProvider();
        if ($boundProvider !== null && self::isSameProvider($boundProvider, $provider)) {
            return true;
        }

        return self::mayLinkByEmail($provider, $user);
    }

    private static function isSameProvider(IdentityProvider $a, IdentityProvider $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    /**
     * Link-by-email gate for the env-configured Azure OAuth / SAML logins
     * (no IdentityProvider record; AZURE_* variables). The object-id lookup
     * missed and the e-mail claim matches $target:
     *  - a privileged account (RoleNames::PRIVILEGED) is NEVER linked by
     *    e-mail, whatever the setting — the e-mail claim of the directory is
     *    not proof of identity for the instance operator (#1059);
     *  - with AZURE_ALLOW_EMAIL_LINKING=false, or when $target is already
     *    bound to another Azure object id or to an IdentityProvider record,
     *    the login is refused with the same message as provider-record logins.
     */
    public static function assertMayLinkAzureByEmail(User $target, bool $allowEmailLinking, ?LoggerInterface $logger = null): void
    {
        $context = ['target_user_id' => $target->getId(), 'provider' => 'azure_env'];
        if (UserAdministrationGuard::privilegedRolesIn($target->getRoles()) !== []) {
            $logger?->warning('SSO: login refused, privileged account is never linked by e-mail', $context);

            throw new AuthenticationException('SSO login is not permitted for this account.');
        }
        // Bound to another Azure object id or to a provider record: never re-bound.
        if (!$allowEmailLinking || $target->getAzureObjectId() !== null || $target->getSsoProvider() !== null) {
            $logger?->warning('SSO: login refused, automatic link by e-mail is not allowed', $context);

            throw new CustomUserMessageAuthenticationException(self::EMAIL_LINKING_DISABLED_MESSAGE);
        }
    }

    /**
     * May $provider assign $role at login (mapping rule, default role,
     * fallback role)? Tenant-owned providers never assign privileged roles.
     */
    public static function mayAssignRole(IdentityProvider $provider, ?string $role): bool
    {
        return $provider->isGlobal() || !RoleNames::isPrivileged($role);
    }

    /**
     * Privileged roles configured on $provider (active claim→role mappings,
     * default role, fallback role) that {@see mayAssignRole()} silently
     * drops at login because the provider is tenant-owned. Shown as a
     * warning in the provider UI so the configuration does not look
     * effective when it is not. Empty for global providers.
     *
     * @param iterable<IdentityProviderRoleMapping> $mappings the provider's mappings as loaded from the
     *                                                    repository (inactive ones are skipped)
     * @return list<string> upper-cased, unique, in configuration order
     */
    public static function ignoredPrivilegedRoles(IdentityProvider $provider, iterable $mappings): array
    {
        if ($provider->isGlobal()) {
            return [];
        }

        $configured = [];
        foreach ($mappings as $mapping) {
            if ($mapping->isActive()) {
                $configured[] = $mapping->getAssignedRole();
            }
        }
        $configured[] = $provider->getDefaultRole();
        $configured[] = $provider->getDefaultFallbackRole();

        $ignored = [];
        foreach ($configured as $role) {
            if (!self::mayAssignRole($provider, $role)) {
                $ignored[] = strtoupper(trim((string) $role));
            }
        }

        return array_values(array_unique($ignored));
    }
}
