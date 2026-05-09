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
     * Run all consistency rules against the wizard run.
     *
     * @return list<array{
     *     rule: string,
     *     severity: string,
     *     message_key: string,
     *     params: array<string, mixed>,
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
            $warnings[] = [
                'rule' => self::RULE_CONSERVATIVE_RPO,
                'severity' => 'warning',
                'message_key' => 'policy_wizard.consistency.conservative_tier_rpo',
                'params' => ['%rpo%' => $rpo],
            ];
        }

        // Rule 2 — aggressive tier + extreme patch SLA.
        $patchCritical = isset($patchSla['critical']) && is_numeric($patchSla['critical'])
            ? (int) $patchSla['critical']
            : null;
        if ($tier === 5 && $patchCritical !== null && $patchCritical < 4) {
            $warnings[] = [
                'rule' => self::RULE_AGGRESSIVE_PATCH,
                'severity' => 'warning',
                'message_key' => 'policy_wizard.consistency.aggressive_tier_patch',
                'params' => ['%hours%' => $patchCritical],
            ];
        }

        // Rule 3 — DORA in scope + lax RPO.
        if (in_array('dora', $standards, true) && $rpo !== null && $rpo > 24) {
            $warnings[] = [
                'rule' => self::RULE_DORA_RPO,
                'severity' => 'warning',
                'message_key' => 'policy_wizard.consistency.dora_rpo',
                'params' => ['%rpo%' => $rpo],
            ];
        }

        // Rule 4 — BCM in scope + lax RTO[high].
        if (in_array('bcm', $standards, true)) {
            $rtoHigh = isset($rtoMap['high']) && is_numeric($rtoMap['high'])
                ? (int) $rtoMap['high']
                : null;
            if ($rtoHigh !== null && $rtoHigh > 24) {
                $warnings[] = [
                    'rule' => self::RULE_BCM_RTO,
                    'severity' => 'warning',
                    'message_key' => 'policy_wizard.consistency.bcm_rto',
                    'params' => ['%rto%' => $rtoHigh],
                ];
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
                $warnings[] = [
                    'rule' => self::RULE_SIGNIFICANT_DPO,
                    'severity' => 'warning',
                    'message_key' => 'policy_wizard.consistency.significant_no_dpo',
                    'params' => [],
                ];
            }
        }

        return $warnings;
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
