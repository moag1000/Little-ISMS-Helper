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
