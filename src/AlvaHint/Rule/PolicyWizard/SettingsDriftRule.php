<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Entity\User;
use App\Repository\TenantPolicySettingRepository;

/**
 * Policy-Wizard W3-B — Tier-1 settings-drift hint.
 *
 * Fires on the descendant Tenant when one or more of its
 * `TenantPolicySetting` rows carry the `_meta.settings_drift_detected`
 * flag — set by {@see App\Service\PolicyWizard\KonzernPushDownService}
 * after the Konzern-CISO raised a baseline that the descendant's stored
 * value now violates.
 *
 * Architecture: `docs/plans/policy-wizard/05-architecture.md` §7.4.
 *
 * Tier 1 (regulatory) because subsidiaries running in violation of
 * Konzern-mandated baselines accumulate audit-finding risk fast. The
 * hint is non-dismissible (Tier-1 invariant in {@see AlvaHint}).
 */
final class SettingsDriftRule extends AbstractAlvaHintRule
{
    public function __construct(
        private readonly TenantPolicySettingRepository $settingRepository,
    ) {
    }

    public function key(): string
    {
        return 'policy_wizard.settings_drift';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        // Hint is module-agnostic — it surfaces on the tenant landing
        // page so even tenants without specific modules see it.
        return [];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Tenant) {
            return false;
        }
        return $this->driftKeysFor($entity) !== [];
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Tenant);

        $driftKeys = $this->driftKeysFor($entity);
        $first = $this->firstDriftSample($entity);

        $konzern = $entity->getRootParent();
        $konzernName = $konzern->getName() ?? '';

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'policy_wizard.settings_drift.title',
            bodyTranslationKey: 'policy_wizard.settings_drift.body',
            bodyTranslationParams: [
                '%konzern_name%' => $konzernName,
                '%setting_key%' => $first['setting_key'] ?? '',
                '%old_value%' => $this->renderScalar($first['old_value'] ?? null),
                '%new_value%' => $this->renderScalar($first['new_value'] ?? null),
                '%affected_count%' => (string) count($driftKeys),
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Tenant',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'policy_wizard.settings_drift.action',
            actionRoute: 'app_policy_wizard_index',
            actionRouteParams: [
                'mode' => 'targeted',
                'drift' => implode(',', $driftKeys),
            ],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_CISO'],
            mood: 'thinking',
        );
    }

    /**
     * Return the keys of all TenantPolicySetting rows on $tenant whose
     * `_meta.settings_drift_detected` flag is true.
     *
     * @return list<string>
     */
    private function driftKeysFor(Tenant $tenant): array
    {
        $keys = [];
        foreach ($this->settingRepository->findByTenant($tenant) as $setting) {
            if ($this->hasDriftFlag($setting)) {
                $key = $setting->getKey();
                if ($key !== null && $key !== '') {
                    $keys[] = $key;
                }
            }
        }
        return $keys;
    }

    /**
     * Return the first drift sample as {setting_key, old_value, new_value}.
     *
     * @return array{setting_key?: string, old_value?: mixed, new_value?: mixed}
     */
    private function firstDriftSample(Tenant $tenant): array
    {
        foreach ($this->settingRepository->findByTenant($tenant) as $setting) {
            if (!$this->hasDriftFlag($setting)) {
                continue;
            }
            $value = $setting->getValue();
            $meta = (is_array($value) && isset($value['_meta']) && is_array($value['_meta']))
                ? $value['_meta']
                : [];
            $oldValue = is_array($value) && array_key_exists('value', $value)
                ? $value['value']
                : (is_array($value) ? $this->stripMeta($value) : $value);
            return [
                'setting_key' => (string) $setting->getKey(),
                'old_value' => $oldValue,
                'new_value' => $meta['drift_parent_value'] ?? null,
            ];
        }
        return [];
    }

    private function hasDriftFlag(TenantPolicySetting $setting): bool
    {
        $value = $setting->getValue();
        if (!is_array($value)) {
            return false;
        }
        $meta = $value['_meta'] ?? null;
        if (!is_array($meta)) {
            return false;
        }
        return ($meta['settings_drift_detected'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function stripMeta(array $value): array
    {
        unset($value['_meta']);
        return $value;
    }

    private function renderScalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '';
    }
}
