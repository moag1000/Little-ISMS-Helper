<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Entity\IdentityProvider;
use App\Repository\IdentityProviderRoleMappingRepository;

/**
 * Resolves IdP claims to a Symfony ROLE_ string using IdentityProviderRoleMapping rules.
 *
 * Algorithm:
 *   1. Load all active mappings for the IdP, ordered by priority ASC (repo does this).
 *   2. For each mapping, check if the claim key exists and the value matches the expression.
 *   3. Return on first match (includes assignedPermissions).
 *   4. If no mapping matches, return defaultFallbackRole (or ROLE_USER as hard fallback).
 *
 * Do NOT mark final — tests may need to mock or extend.
 */
class ClaimToRoleResolver
{
    public function __construct(
        private readonly IdentityProviderRoleMappingRepository $repo,
    ) {
    }

    /**
     * @param array<string,mixed> $claims IdP token claims (from JWT or userinfo endpoint)
     */
    public function resolve(IdentityProvider $provider, array $claims): ClaimToRoleResolverResult
    {
        $mappings   = $this->repo->findActiveByProvider($provider);
        $traceLines = [];

        foreach ($mappings as $mapping) {
            $key   = $mapping->getClaimKey();
            $value = $claims[$key] ?? null;

            if ($value === null) {
                $traceLines[] = sprintf('skip mapping priority=%d: claim "%s" absent', $mapping->getPriority(), $key);
                continue;
            }

            if (!SsoAccountLinkPolicy::mayAssignRole($provider, $mapping->getAssignedRole())) {
                $traceLines[] = sprintf(
                    'skip mapping priority=%d: role %s is privileged or unknown, never assigned from claims',
                    $mapping->getPriority(),
                    $mapping->getAssignedRole(),
                );
                continue;
            }

            if ($mapping->matches($value)) {
                $traceLines[] = sprintf(
                    'match priority=%d: claim "%s" matched expression "%s" → %s',
                    $mapping->getPriority(),
                    $key,
                    $mapping->getClaimValueExpression(),
                    $mapping->getAssignedRole(),
                );
                return new ClaimToRoleResolverResult(
                    role: $mapping->getAssignedRole(),
                    matched: true,
                    trace: implode('; ', $traceLines),
                    assignedPermissions: $mapping->getAssignedPermissions(),
                );
            }

            $traceLines[] = sprintf(
                'no-match priority=%d: claim "%s" value did not match expression "%s"',
                $mapping->getPriority(),
                $key,
                $mapping->getClaimValueExpression(),
            );
        }

        $fallback     = $provider->getDefaultFallbackRole() ?: 'ROLE_USER';
        if (!SsoAccountLinkPolicy::mayAssignRole($provider, $fallback)) {
            $fallback = 'ROLE_USER';
        }
        $traceLines[] = sprintf('fallback: no mapping matched, using %s', $fallback);

        return new ClaimToRoleResolverResult(
            role: $fallback,
            matched: false,
            trace: implode('; ', $traceLines),
        );
    }

    /**
     * The roles this provider can hand out at login (active mappings, default
     * and fallback role) — the only roles a login may add or remove on an
     * account (S14). Privileged and unknown names are left out.
     *
     * @return list<string>
     */
    public function managedRoles(IdentityProvider $provider): array
    {
        $roles = [$provider->getDefaultRole(), (string) $provider->getDefaultFallbackRole()];
        foreach ($this->repo->findActiveByProvider($provider) as $mapping) {
            $roles[] = $mapping->getAssignedRole();
        }

        return array_values(array_unique(array_filter(
            $roles,
            static fn (string $role): bool => SsoAccountLinkPolicy::mayAssignRole($provider, $role),
        )));
    }
}
