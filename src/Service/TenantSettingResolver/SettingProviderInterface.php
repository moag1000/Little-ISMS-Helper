<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

use App\Entity\Tenant;

/**
 * Policy-Wizard W1 — pluggable storage abstraction for TenantSettingResolver.
 *
 * Decouples the resolver from any concrete persistence layer so that:
 * - W1-A's `TenantPolicySetting` repository can plug in once the entity lands.
 * - Existing settings sources (SystemSettingsRepository) can keep working.
 * - Tests can use trivial in-memory providers without a DB round-trip.
 *
 * Each provider answers:
 * 1. "What is the override-mode for this key?" — defaults to ForbiddenToRelax
 *    when no key-specific config is registered (safe default per §7.3).
 * 2. "What value, if any, has this tenant explicitly set for this key?" —
 *    null means "no own value, inherit".
 * 3. "What is the global default for this key?" — used as the floor when
 *    no tenant in the chain (and no ancestor) provides a value.
 */
interface SettingProviderInterface
{
    /**
     * Returns the override-mode that governs how children may diverge from
     * the parent's value for this setting.
     */
    public function getOverrideMode(string $key): OverrideMode;

    /**
     * Returns the tenant's own stored value for this key, or null when the
     * tenant has not stored anything (i.e. should inherit from the chain).
     */
    public function getStoredValue(Tenant $tenant, string $key): mixed;

    /**
     * Returns the global default value for this key when neither the
     * tenant nor any of its ancestors has stored anything. Pass-through
     * the caller's $default if the provider has no opinion.
     */
    public function getGlobalDefault(string $key, mixed $default): mixed;
}
