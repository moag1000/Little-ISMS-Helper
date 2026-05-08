<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\WizardRun;
use App\Service\TenantSettingResolver\OverrideMode;
use App\Service\TenantSettingResolver\TenantSettingResolver;

/**
 * Policy-Wizard W2-A — Step 7 hierarchy-conflict gate.
 *
 * Walks the inputs the wizard collected and consults the
 * `TenantSettingResolver` for each setting key to decide whether the
 * subsidiary's value satisfies the override-mode rule (architecture
 * §7.3 matrix). Conflicts BLOCK Step 7 generation.
 *
 * Conflict shape returned per item:
 *   [
 *     'key'          => string  — namespaced setting key
 *     'parent_value' => mixed   — value enforced by the chain
 *     'child_value'  => mixed   — value attempted by the wizard run
 *     'mode'         => OverrideMode — the rule applied
 *     'message'      => string  — i18n key for the UI to translate
 *   ]
 *
 * The validator performs no DB writes — it is a pure read-side check.
 * Persisted resolution + RelaxAttempt logging is handled by the
 * resolver itself when the wizard saves the setting downstream.
 */
class HierarchyOverrideValidator
{
    /**
     * Mapping of (step_key, input_field) → namespaced setting key, with
     * the override-mode hint we expect in production. The list intentionally
     * mirrors §7.3's sample matrix, extended with the wizard's collected
     * inputs.
     *
     * @var list<array{
     *   step: string,
     *   input_field: string,
     *   setting_key: string,
     *   expected_mode: OverrideMode,
     * }>
     */
    private const SETTING_MAP = [
        [
            'step' => WizardStepKeys::STEP_RISK_CLASSIFICATION,
            'input_field' => 'risk_appetite_tier',
            'setting_key' => 'risk.appetite_tier',
            'expected_mode' => OverrideMode::CeilingOnly,
        ],
        [
            'step' => WizardStepKeys::STEP_RISK_CLASSIFICATION,
            'input_field' => 'review_interval_months',
            'setting_key' => 'policy.review_interval_months',
            'expected_mode' => OverrideMode::CeilingOnly,
        ],
        [
            'step' => WizardStepKeys::STEP_OPERATIONAL_BASELINES,
            'input_field' => 'backup_rpo_hours',
            'setting_key' => 'backup.rpo_hours',
            'expected_mode' => OverrideMode::CeilingOnly,
        ],
        [
            'step' => WizardStepKeys::STEP_LIFECYCLE,
            'input_field' => 'default_review_interval_months',
            'setting_key' => 'policy.review_interval_months',
            'expected_mode' => OverrideMode::CeilingOnly,
        ],
    ];

    public function __construct(
        private readonly TenantSettingResolver $resolver,
    ) {
    }

    /**
     * Run the validation pass.
     *
     * @return list<array{
     *   key: string,
     *   parent_value: mixed,
     *   child_value: mixed,
     *   mode: OverrideMode,
     *   message: string,
     * }>
     */
    public function validate(WizardRun $run): array
    {
        $tenant = $run->getTenant();
        if ($tenant === null) {
            return [];
        }

        $inputs = $run->getInputs() ?? [];
        $conflicts = [];

        foreach (self::SETTING_MAP as $rule) {
            $stepSlot = $inputs[$rule['step']] ?? null;
            if (!is_array($stepSlot)) {
                continue;
            }
            if (!array_key_exists($rule['input_field'], $stepSlot)) {
                continue;
            }
            $childValue = $stepSlot[$rule['input_field']];
            if ($childValue === null) {
                continue;
            }

            $resolution = $this->resolver->resolveFor($tenant, $rule['setting_key']);
            $parentValue = $resolution->value;
            $mode = $resolution->effectiveMode;

            $violates = $this->violates($mode, $parentValue, $childValue);
            if (!$violates) {
                continue;
            }

            $conflicts[] = [
                'key' => $rule['setting_key'],
                'parent_value' => $parentValue,
                'child_value' => $childValue,
                'mode' => $mode,
                'message' => $this->messageKey($mode),
            ];
        }

        return $conflicts;
    }

    /**
     * Pure check: does the child's $candidate value violate the
     * override-mode against $parent?
     */
    private function violates(OverrideMode $mode, mixed $parent, mixed $candidate): bool
    {
        if ($parent === null) {
            // No parent value enforced → nothing to violate.
            return false;
        }

        return match ($mode) {
            OverrideMode::ForbiddenToChange => !$this->valuesEqual($parent, $candidate),
            OverrideMode::ForbiddenToRelax => $this->isLooser($mode, $parent, $candidate),
            OverrideMode::FloorOnly => is_numeric($parent) && is_numeric($candidate)
                ? (float) $candidate < (float) $parent
                : (is_bool($parent) && $parent === true && $candidate === false),
            OverrideMode::CeilingOnly => is_numeric($parent) && is_numeric($candidate)
                ? (float) $candidate > (float) $parent
                : (is_bool($parent) && $parent === false && $candidate === true),
            OverrideMode::Free => false,
        };
    }

    private function isLooser(OverrideMode $mode, mixed $parent, mixed $candidate): bool
    {
        if (is_bool($parent) || is_bool($candidate)) {
            // ForbiddenToRelax: parent=true must stay true.
            return ((bool) $parent) === true && ((bool) $candidate) === false;
        }
        if (is_numeric($parent) && is_numeric($candidate)) {
            // For ForbiddenToRelax we treat numeric "stricter = higher".
            return (float) $candidate < (float) $parent;
        }
        // Strings / arrays: any change counts as relax under
        // ForbiddenToRelax (no ordering defined).
        return !$this->valuesEqual($parent, $candidate);
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return $a === $b;
    }

    private function messageKey(OverrideMode $mode): string
    {
        return match ($mode) {
            OverrideMode::ForbiddenToChange => 'policy_wizard.error.hierarchy.forbidden_to_change',
            OverrideMode::ForbiddenToRelax => 'policy_wizard.error.hierarchy.forbidden_to_relax',
            OverrideMode::FloorOnly => 'policy_wizard.error.hierarchy.floor_only',
            OverrideMode::CeilingOnly => 'policy_wizard.error.hierarchy.ceiling_only',
            OverrideMode::Free => 'policy_wizard.error.hierarchy.free',
        };
    }
}
