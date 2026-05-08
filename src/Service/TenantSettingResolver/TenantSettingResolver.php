<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

use App\Entity\Tenant;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Policy-Wizard W1-C — generic tenant-setting resolver.
 *
 * Lifts the floor-pattern from PasswordPolicyResolver into a reusable
 * abstraction parametrised on (a) setting key and (b) override-mode.
 * Mirrors the §7 hierarchy spec from
 * `docs/plans/policy-wizard/05-architecture.md`.
 *
 * Resolution algorithm:
 * 1. Determine the override-mode for the requested key (via provider).
 * 2. Fetch the global default (provider) — used only when the chain is empty.
 * 3. Walk ancestors top-down (root first), folding each ancestor's stored
 *    value into the running effective value per the override-mode rule.
 * 4. Apply the tenant's own stored value, again per the override-mode rule.
 *    If the child's value would relax beyond what the chain enforces, it is
 *    clamped and a RelaxAttempt is emitted to the change-attempt logger
 *    (drift detection per §7.4).
 *
 * Boolean handling:
 * - For ForbiddenToRelax + booleans: parent=true ⇒ child forced true.
 * - For FloorOnly + booleans: same as ForbiddenToRelax (true is "stricter").
 * - For CeilingOnly + booleans: parent=false ⇒ child forced false.
 *
 * Caching:
 * Request-scoped array cache keyed on tenant-id + setting-key. Cache key is
 * scoped per resolver instance so different concrete resolvers do not bleed
 * into each other.
 */
class TenantSettingResolver
{
    /** @var array<string, SettingResolutionResult> */
    private array $cache = [];

    public function __construct(
        private readonly SettingProviderInterface $provider,
        private readonly ChangeAttemptLoggerInterface $changeAttemptLogger = new NullChangeAttemptLogger(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Resolve the effective value of $key for $tenant, applying the
     * holding-hierarchy override-mode rules.
     */
    public function resolveFor(Tenant $tenant, string $key, mixed $default = null): SettingResolutionResult
    {
        $cacheKey = $this->cacheKey($tenant, $key);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $mode = $this->provider->getOverrideMode($key);
        $globalDefault = $this->provider->getGlobalDefault($key, $default);

        // Build ordered chain: root -> ... -> tenant.
        // getAllAncestors() returns immediate-parent first; reverse for top-down walk.
        $ancestors = array_reverse($tenant->getAllAncestors());
        /** @var list<Tenant> $chain */
        $chain = [...$ancestors, $tenant];

        $effectiveValue = $globalDefault;
        $effectiveSourceId = null; // null = pure default
        $enforcedByTenantId = null; // last ancestor with a stored value
        $relaxAttempts = [];

        foreach ($chain as $i => $node) {
            $isOwn = ($i === count($chain) - 1);
            $stored = $this->provider->getStoredValue($node, $key);

            if ($stored === null) {
                // No stored value at this node — chain pass-through.
                continue;
            }

            // Free mode: only the node's own stored value is consulted; ancestor
            // values are ignored entirely for that key.
            if ($mode === OverrideMode::Free) {
                if ($isOwn) {
                    $effectiveValue = $stored;
                    $effectiveSourceId = $node->getId();
                }
                continue;
            }

            if (!$isOwn) {
                // Ancestor with a stored value — becomes the new floor/ceiling/lock.
                $effectiveValue = $stored;
                $effectiveSourceId = $node->getId();
                $enforcedByTenantId = $node->getId();
                continue;
            }

            // OWN node: merge per override-mode against current $effectiveValue.
            $merged = $this->applyOverrideMode($mode, $effectiveValue, $stored);

            if (!$this->valuesEqual($merged, $stored) && $enforcedByTenantId !== null) {
                // Child tried to relax — record the drift attempt.
                $attempt = new RelaxAttempt(
                    tenantId: $node->getId(),
                    key: $key,
                    attemptedValue: $stored,
                    enforcedValue: $merged,
                    mode: $mode,
                    blockedByTenantId: $enforcedByTenantId,
                );
                $relaxAttempts[] = $attempt;
                $this->changeAttemptLogger->log($attempt);
                $this->logger->info('TenantSettingResolver clamped child relax attempt', [
                    'tenant_id' => $node->getId(),
                    'key' => $key,
                    'attempted' => $stored,
                    'enforced' => $merged,
                    'mode' => $mode->value,
                    'blocked_by_tenant_id' => $enforcedByTenantId,
                ]);
            }

            $effectiveValue = $merged;
            // Source id: if the merge kept the child's value, source is child;
            // otherwise it remains whichever ancestor enforced the clamp.
            if ($this->valuesEqual($merged, $stored)) {
                $effectiveSourceId = $node->getId();
            }
        }

        $result = new SettingResolutionResult(
            value: $effectiveValue,
            sourceTenantId: $effectiveSourceId,
            effectiveMode: $mode,
            childRelaxBlocked: $relaxAttempts !== [],
            relaxAttempts: $relaxAttempts,
        );

        $this->logger->debug('TenantSettingResolver resolved', [
            'tenant_id' => $tenant->getId(),
            'key' => $key,
            'mode' => $mode->value,
            'value' => $effectiveValue,
            'source_tenant_id' => $effectiveSourceId,
            'relax_attempts' => count($relaxAttempts),
        ]);

        $this->cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Invalidate the cache. Pass null to flush everything (after a global
     * setting change), or a specific tenant to drop only that tenant's
     * cached resolutions.
     */
    public function invalidate(?Tenant $tenant = null, ?string $key = null): void
    {
        if ($tenant === null && $key === null) {
            $this->cache = [];
            return;
        }
        $tenantPart = $tenant !== null ? (string) ($tenant->getId() ?? 'no_id') : null;
        foreach (array_keys($this->cache) as $cacheKey) {
            // cache keys: "<tenant_id>::<setting_key>"
            [$tid, $k] = explode('::', $cacheKey, 2);
            if ($tenantPart !== null && $tid !== $tenantPart) {
                continue;
            }
            if ($key !== null && $k !== $key) {
                continue;
            }
            unset($this->cache[$cacheKey]);
        }
    }

    private function cacheKey(Tenant $tenant, string $key): string
    {
        return ($tenant->getId() ?? 'no_id') . '::' . $key;
    }

    /**
     * Apply the override-mode rule to merge the child's value against the
     * value enforced by the chain so far. Returns the value the child is
     * allowed to keep.
     */
    private function applyOverrideMode(OverrideMode $mode, mixed $chainValue, mixed $childValue): mixed
    {
        return match ($mode) {
            OverrideMode::ForbiddenToChange => $chainValue,
            OverrideMode::ForbiddenToRelax => $this->mergeForbiddenToRelax($chainValue, $childValue),
            OverrideMode::FloorOnly => $this->mergeFloor($chainValue, $childValue),
            OverrideMode::CeilingOnly => $this->mergeCeiling($chainValue, $childValue),
            OverrideMode::Free => $childValue,
        };
    }

    private function mergeForbiddenToRelax(mixed $chainValue, mixed $childValue): mixed
    {
        // Booleans: true is "stricter". Once parent=true, child stays true.
        if (is_bool($chainValue) || is_bool($childValue)) {
            $chainBool = (bool) $chainValue;
            $childBool = (bool) $childValue;
            // If chain enforces true, child cannot loosen to false.
            return $chainBool ? true : $childBool;
        }

        // Numerics: child must be >= chain value.
        if (is_numeric($chainValue) && is_numeric($childValue)) {
            return $this->numericMax($chainValue, $childValue);
        }

        // Strings / unknown types: lock to chain value when child is "different
        // and weaker" is undefined → fall back to chain value to stay safe.
        return $chainValue;
    }

    private function mergeFloor(mixed $chainValue, mixed $childValue): mixed
    {
        if (is_bool($chainValue) || is_bool($childValue)) {
            $chainBool = (bool) $chainValue;
            $childBool = (bool) $childValue;
            return $chainBool ? true : $childBool;
        }
        if (is_numeric($chainValue) && is_numeric($childValue)) {
            return $this->numericMax($chainValue, $childValue);
        }
        return $chainValue;
    }

    private function mergeCeiling(mixed $chainValue, mixed $childValue): mixed
    {
        if (is_bool($chainValue) || is_bool($childValue)) {
            $chainBool = (bool) $chainValue;
            $childBool = (bool) $childValue;
            // false is "stricter" under ceiling_only; parent=false locks child=false.
            return $chainBool === false ? false : $childBool;
        }
        if (is_numeric($chainValue) && is_numeric($childValue)) {
            return $this->numericMin($chainValue, $childValue);
        }
        return $chainValue;
    }

    private function numericMax(mixed $a, mixed $b): int|float
    {
        if (is_int($a) && is_int($b)) {
            return $a >= $b ? $a : $b;
        }
        return ((float) $a >= (float) $b) ? (float) $a : (float) $b;
    }

    private function numericMin(mixed $a, mixed $b): int|float
    {
        if (is_int($a) && is_int($b)) {
            return $a <= $b ? $a : $b;
        }
        return ((float) $a <= (float) $b) ? (float) $a : (float) $b;
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return $a === $b;
    }
}
