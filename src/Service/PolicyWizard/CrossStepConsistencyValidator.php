<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\WizardRun;

/**
 * Policy-Wizard — Cross-Step Consistency Validator (May 2026 follow-up).
 *
 * Surfaces NON-blocking warnings when settings collected in different
 * wizard steps are mutually inconsistent (e.g. very-conservative
 * risk-appetite combined with a 24-hour backup RPO). The orchestrator
 * surfaces the warnings on STEP_REVIEW_GENERATE; the user can still
 * proceed.
 *
 * Each warning DTO carries a `target_step` field naming the step the
 * user should jump to in order to fix the inconsistency. The Step 7
 * review-page renders this as a click-jump button (Junior-ISB Wish #6,
 * May 2026).
 *
 * Rules:
 *  1. risk_appetite_tier=1 (very_conservative) + backup_rpo_hours > 12
 *     → warn "conservative tier expects RPO ≤ 12h"
 *  2. risk_appetite_tier=5 (aggressive) + patch_sla_hours[critical] < 4
 *     → warn "aggressive tier rarely supports 4h critical-patch SLA"
 *  3. dora_in_scope + backup_rpo_hours > 24
 *     → warn "DORA Art. 12 expects RPO ≤ 24h for critical ICT systems"
 *  4. bcm_in_scope + continuity_rto_hours[high] > 24
 *     → warn "BCM critical processes typically need RTO ≤ 24h"
 *  5. is_significant=true + no DPO assigned
 *     → warn "DORA significant entity should have a DPO appointed"
 */
final class CrossStepConsistencyValidator
{
    public const RULE_CONSERVATIVE_RPO = 'conservative_tier_rpo';
    public const RULE_AGGRESSIVE_PATCH = 'aggressive_tier_patch';
    public const RULE_DORA_RPO = 'dora_rpo';
    public const RULE_BCM_RTO = 'bcm_rto';
    public const RULE_SIGNIFICANT_DPO = 'significant_no_dpo';

    /**
     * Per-rule mapping to the wizard step the user should jump to in
     * order to fix the inconsistency. Surfaced as `target_step` on every
     * warning so the Step 7 review-page can render a click-jump button
     * (Junior-ISB Wish #6).
     */
    private const RULE_TARGET_STEP = [
        self::RULE_CONSERVATIVE_RPO => WizardStepKeys::STEP_OPERATIONAL_BASELINES,
        self::RULE_AGGRESSIVE_PATCH => WizardStepKeys::STEP_OPERATIONAL_BASELINES,
        self::RULE_DORA_RPO => WizardStepKeys::STEP_OPERATIONAL_BASELINES,
        self::RULE_BCM_RTO => WizardStepKeys::STEP_OPERATIONAL_BASELINES,
        // DPO is assigned in Step 3 Roles; the OpBaselines fallback only
        // backs it up. Jump should land where the field actually lives.
        self::RULE_SIGNIFICANT_DPO => WizardStepKeys::STEP_ROLES,
    ];

    /**
     * Run all consistency rules against the wizard run.
     *
     * @return list<array{
     *     rule: string,
     *     severity: string,
     *     message_key: string,
     *     params: array<string, mixed>,
     *     target_step: string,
     * }>
     */
    public function validate(WizardRun $run): array
    {
        $warnings = [];
        $bag = $run->getInputs() ?? [];
        $standards = $run->getStandardsAdopted() ?? [];

        $riskSlot = $this->slot($bag, WizardStepKeys::STEP_RISK_CLASSIFICATION);
        $opSlot = $this->slot($bag, WizardStepKeys::STEP_OPERATIONAL_BASELINES);

        $tier = isset($riskSlot['risk_appetite_tier']) && is_numeric($riskSlot['risk_appetite_tier'])
            ? (int) $riskSlot['risk_appetite_tier']
            : null;
        $rpo = isset($opSlot['backup_rpo_hours']) && is_numeric($opSlot['backup_rpo_hours'])
            ? (int) $opSlot['backup_rpo_hours']
            : null;
        $patchSla = is_array($opSlot['patch_sla_hours'] ?? null) ? $opSlot['patch_sla_hours'] : [];
        $rtoMap = is_array($opSlot['continuity_rto_hours'] ?? null) ? $opSlot['continuity_rto_hours'] : [];
        $dora = is_array($opSlot['dora'] ?? null) ? $opSlot['dora'] : null;

        // Rule 1 — conservative tier + lax RPO.
        if ($tier === 1 && $rpo !== null && $rpo > 12) {
            $warnings[] = $this->makeWarning(
                self::RULE_CONSERVATIVE_RPO,
                'policy_wizard.consistency.conservative_tier_rpo',
                ['%rpo%' => $rpo],
            );
        }

        // Rule 2 — aggressive tier + extreme patch SLA.
        $patchCritical = isset($patchSla['critical']) && is_numeric($patchSla['critical'])
            ? (int) $patchSla['critical']
            : null;
        if ($tier === 5 && $patchCritical !== null && $patchCritical < 4) {
            $warnings[] = $this->makeWarning(
                self::RULE_AGGRESSIVE_PATCH,
                'policy_wizard.consistency.aggressive_tier_patch',
                ['%hours%' => $patchCritical],
            );
        }

        // Rule 3 — DORA in scope + lax RPO.
        if (in_array('dora', $standards, true) && $rpo !== null && $rpo > 24) {
            $warnings[] = $this->makeWarning(
                self::RULE_DORA_RPO,
                'policy_wizard.consistency.dora_rpo',
                ['%rpo%' => $rpo],
            );
        }

        // Rule 4 — BCM in scope + lax RTO[high].
        if (in_array('bcm', $standards, true)) {
            $rtoHigh = isset($rtoMap['high']) && is_numeric($rtoMap['high'])
                ? (int) $rtoMap['high']
                : null;
            if ($rtoHigh !== null && $rtoHigh > 24) {
                $warnings[] = $this->makeWarning(
                    self::RULE_BCM_RTO,
                    'policy_wizard.consistency.bcm_rto',
                    ['%rto%' => $rtoHigh],
                );
            }
        }

        // Rule 5 — DORA significant entity without DPO.
        $isSignificant = $dora !== null && (bool) ($dora['is_significant'] ?? false);
        if ($isSignificant) {
            $hasDpo = false;
            // Operational baselines slot may carry the user-id picker.
            $dpoFromOp = $opSlot['dpo_user_id'] ?? null;
            if (is_numeric($dpoFromOp) && (int) $dpoFromOp > 0) {
                $hasDpo = true;
            }
            // Fall back to RolesStep slot.
            $rolesSlot = $this->slot($bag, WizardStepKeys::STEP_ROLES);
            if (!$hasDpo) {
                $rolesMap = is_array($rolesSlot['roles'] ?? null) ? $rolesSlot['roles'] : [];
                $dpoFromRoles = $rolesMap['dpo'] ?? null;
                if (is_numeric($dpoFromRoles) && (int) $dpoFromRoles > 0) {
                    $hasDpo = true;
                }
            }
            if (!$hasDpo) {
                $warnings[] = $this->makeWarning(
                    self::RULE_SIGNIFICANT_DPO,
                    'policy_wizard.consistency.significant_no_dpo',
                    [],
                );
            }
        }

        return $warnings;
    }

    /**
     * Build a warning DTO with the click-jump `target_step` resolved
     * from {@see self::RULE_TARGET_STEP}. Falls back to the review step
     * itself when a rule lacks an explicit mapping (defensive — should
     * never happen for the 5 documented rules).
     *
     * @param array<string, mixed> $params
     * @return array{
     *     rule: string,
     *     severity: string,
     *     message_key: string,
     *     params: array<string, mixed>,
     *     target_step: string,
     * }
     */
    private function makeWarning(string $rule, string $messageKey, array $params): array
    {
        return [
            'rule' => $rule,
            'severity' => 'warning',
            'message_key' => $messageKey,
            'params' => $params,
            'target_step' => self::RULE_TARGET_STEP[$rule] ?? WizardStepKeys::STEP_REVIEW_GENERATE,
        ];
    }

    /**
     * @param array<string, mixed> $bag
     * @return array<string, mixed>
     */
    private function slot(array $bag, string $stepKey): array
    {
        $slot = $bag[$stepKey] ?? [];
        return is_array($slot) ? $slot : [];
    }
}
