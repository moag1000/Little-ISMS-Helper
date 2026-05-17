<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

use App\Entity\Tenant;
use App\Repository\SystemSettingsRepository;

/**
 * Policy-Wizard W1 — default SettingProviderInterface used until the
 * W1-A `TenantPolicySetting` entity + repository land.
 *
 * Reads global defaults from the existing `system_settings` table (split by
 * "category.key" namespacing) and treats every tenant as having no own
 * stored value (returns null), so the resolver effectively falls back to
 * the global default. This preserves the current PasswordPolicyResolver
 * behaviour exactly.
 *
 * Override-mode is read from a per-key registry (overrideModeMap). Unknown
 * keys default to ForbiddenToRelax, which is the safe choice per §7.3 (most
 * settings).
 *
 * Once W1-A lands, swap this provider for one that hits
 * TenantPolicySettingRepository::findOneBy(['tenant'=>..., 'key'=>...]).
 */
final class SystemSettingsBackedProvider implements SettingProviderInterface
{
    /**
     * @param array<string, OverrideMode> $overrideModeMap key => mode override
     *                                                     (e.g. 'security.password_min_length' => OverrideMode::FloorOnly)
     */
    public function __construct(
        private readonly SystemSettingsRepository $systemSettingsRepository,
        private readonly array $overrideModeMap = [],
    ) {
    }

    public function getOverrideMode(string $key): OverrideMode
    {
        return $this->overrideModeMap[$key] ?? OverrideMode::ForbiddenToRelax;
    }

    public function getStoredValue(Tenant $tenant, string $key): mixed
    {
        // Phase 9A / W1-A hook: tenant-specific values land here once the
        // TenantPolicySetting entity is wired up. For now, no tenant has its
        // own stored value — everyone inherits the global default.
        return null;
    }

    public function getGlobalDefault(string $key, mixed $default): mixed
    {
        // Map "category.key" → SystemSettings (category, key).
        if (str_contains($key, '.')) {
            [$category, $settingKey] = explode('.', $key, 2);
            return $this->systemSettingsRepository->getSetting($category, $settingKey, $default);
        }
        return $default;
    }
}
