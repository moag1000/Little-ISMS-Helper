<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

use App\Entity\Tenant;
use App\Repository\SystemSettingsRepository;

/**
 * SettingProviderInterface without tenant values: every tenant inherits the
 * global default from `system_settings` ("category.key").
 *
 * Fallback for services built outside the container (unit tests). The
 * container uses {@see TenantPolicySettingProvider}.
 *
 * Override-mode is read from a per-key registry (overrideModeMap). Unknown
 * keys default to ForbiddenToRelax.
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
