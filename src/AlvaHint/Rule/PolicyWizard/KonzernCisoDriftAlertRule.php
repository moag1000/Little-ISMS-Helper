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
 * Policy-Wizard W7-D — Tier-2 Konzern-CISO drift alert.
 *
 * Counterpart to {@see SettingsDriftRule}: SettingsDriftRule fires on
 * the descendant Tochter when its own value violates the Konzern
 * baseline. This rule fires on the Konzern parent when ANY descendant
 * carries a non-compliant override against the Konzern default whose
 * `overrideMode` is `forbidden_to_change` or `forbidden_to_relax`.
 *
 * The Konzern-CISO uses the rule as an early-warning channel: a
 * Tochter may have drifted before the central CISO had a chance to
 * inspect the rollup tab. Tier-2 because it is an audit-gap signal,
 * not a hard regulatory deadline (Tochter remains responsible for
 * its own audit). CTA links into the W7-B drift tab of the Konzern
 * rollup view.
 *
 * Architecture: `docs/plans/policy-wizard/05-architecture.md` §7.4 +
 * `07-phase4-sprint-reconciliation.md` lines 309-311.
 */
final class KonzernCisoDriftAlertRule extends AbstractAlvaHintRule
{
    /** Bump when the rule's threshold or condition changes. */
    public const VERSION = 1;

    /**
     * Override modes that, when combined with a non-equal descendant
     * value, count as drift requiring CISO attention.
     */
    private const STRICT_OVERRIDE_MODES = [
        'forbidden_to_change',
        'forbidden_to_relax',
    ];

    public function __construct(
        private readonly TenantPolicySettingRepository $settingRepository,
    ) {
    }

    public function key(): string
    {
        return 'policy_wizard.konzern_ciso_drift_alert';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    /**
     * @return array<int, string>
     */
    public function requiredModules(): array
    {
        return ['policy_wizard'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Tenant) {
            return false;
        }
        if ($entity->getSubsidiaries()->count() === 0) {
            return false;
        }
        return $this->driftingDescendants($entity) !== [];
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Tenant);

        $drift = $this->driftingDescendants($entity);
        $first = $drift[0] ?? ['tenant_name' => '', 'setting_key' => ''];

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'alva_hint.konzern_ciso_drift_alert.title',
            bodyTranslationKey: 'alva_hint.konzern_ciso_drift_alert.body',
            bodyTranslationParams: [
                '%konzern_name%' => (string) ($entity->getName() ?? ''),
                '%affected_count%' => (string) count($drift),
                '%first_tenant_name%' => $first['tenant_name'],
                '%first_setting_key%' => $first['setting_key'],
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'alva_hint.konzern_ciso_drift_alert.cta_label',
            actionRoute: 'app_policy_wizard_konzern_rollup_index',
            actionRouteParams: ['tab' => 'drift'],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_GROUP_CISO'],
            mood: 'thinking',
            version: self::VERSION,
        );
    }

    /**
     * Walk every descendant of $konzern and collect (tenant_name, setting_key)
     * pairs whose Konzern-mode is strict (forbidden_to_change /
     * forbidden_to_relax) AND whose value diverges from the Konzern default.
     *
     * Cheap path: the rule only inspects Konzern-side settings whose
     * overrideMode is strict, then for each descendant looks up the
     * matching key. No N×M query explosion — the strict-mode set is
     * typically <20 keys.
     *
     * @return list<array{tenant_name: string, setting_key: string}>
     */
    private function driftingDescendants(Tenant $konzern): array
    {
        $strictKonzernSettings = [];
        foreach ($this->settingRepository->findByTenant($konzern) as $setting) {
            if (!in_array($setting->getOverrideMode(), self::STRICT_OVERRIDE_MODES, true)) {
                continue;
            }
            $key = $setting->getKey();
            if ($key === null || $key === '') {
                continue;
            }
            $strictKonzernSettings[$key] = $this->scalarValue($setting);
        }

        if ($strictKonzernSettings === []) {
            return [];
        }

        $drift = [];
        foreach ($konzern->getSubsidiaries() as $subsidiary) {
            foreach ($this->settingRepository->findByTenant($subsidiary) as $setting) {
                $key = $setting->getKey();
                if ($key === null || !array_key_exists($key, $strictKonzernSettings)) {
                    continue;
                }
                $descendantValue = $this->scalarValue($setting);
                if ($descendantValue !== $strictKonzernSettings[$key]) {
                    $drift[] = [
                        'tenant_name' => (string) ($subsidiary->getName() ?? ''),
                        'setting_key' => $key,
                    ];
                }
            }
        }
        return $drift;
    }

    /**
     * Strip the W3-B `_meta` envelope and return the comparable scalar
     * (or array) payload of the setting.
     */
    private function scalarValue(TenantPolicySetting $setting): mixed
    {
        $value = $setting->getValue();
        if (is_array($value) && array_key_exists('value', $value)) {
            return $value['value'];
        }
        return $value;
    }
}
