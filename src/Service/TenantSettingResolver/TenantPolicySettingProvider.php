<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Repository\SystemSettingsRepository;
use App\Repository\TenantPolicySettingRepository;

/**
 * SettingProviderInterface backed by the `tenant_policy_setting` table.
 *
 * Tenant values come from the tenant's own TenantPolicySetting row (written by
 * the Policy-Wizard, e.g. the Konzern-Defaults variant). Global defaults come
 * from `system_settings` ("category.key").
 *
 * Override-modes are registered per key in {@see OVERRIDE_MODES}; this map is
 * the single source for the holding hierarchy (KonzernPushDownService reads it
 * too). Unregistered keys are Free: only the tenant's own value counts and a
 * parent never overrides it.
 */
final class TenantPolicySettingProvider implements SettingProviderInterface
{
    /** @var array<string, OverrideMode> */
    public const array OVERRIDE_MODES = [
        'risk.appetite_tier' => OverrideMode::CeilingOnly,
        'policy.review_interval_months' => OverrideMode::CeilingOnly,
        'backup.rpo_hours' => OverrideMode::CeilingOnly,
        'crypto.minimum_key_length' => OverrideMode::FloorOnly,
        'security.password_min_length' => OverrideMode::FloorOnly,
        'gdpr.scope_flag' => OverrideMode::ForbiddenToRelax,
        'approval.top_management_chain' => OverrideMode::ForbiddenToChange,
    ];

    public function __construct(
        private readonly TenantPolicySettingRepository $settingRepository,
        private readonly SystemSettingsRepository $systemSettingsRepository,
    ) {
    }

    public static function overrideModeFor(string $key): OverrideMode
    {
        return self::OVERRIDE_MODES[$key] ?? OverrideMode::Free;
    }

    public function getOverrideMode(string $key): OverrideMode
    {
        return self::overrideModeFor($key);
    }

    public function getStoredValue(Tenant $tenant, string $key): mixed
    {
        if ($tenant->getId() === null) {
            return null;
        }
        $setting = $this->settingRepository->findOneByTenantAndKey($tenant, $key);
        if (!$setting instanceof TenantPolicySetting) {
            return null;
        }

        return self::stripMeta($setting->getValue());
    }

    public function getGlobalDefault(string $key, mixed $default): mixed
    {
        if (!str_contains($key, '.')) {
            return $default;
        }
        [$category, $settingKey] = explode('.', $key, 2);

        return $this->systemSettingsRepository->getSetting($category, $settingKey, $default);
    }

    /**
     * KonzernPushDownService wraps drifted values as `{value: x, _meta: {...}}`
     * (or adds `_meta` to an array value). The resolver compares pure values.
     */
    public static function stripMeta(mixed $value): mixed
    {
        if (!is_array($value) || !array_key_exists('_meta', $value) || !is_array($value['_meta'])) {
            return $value;
        }
        unset($value['_meta']);

        return array_keys($value) === ['value'] ? $value['value'] : $value;
    }
}
