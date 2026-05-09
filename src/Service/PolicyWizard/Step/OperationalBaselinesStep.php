<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Step 5 — Operational Baselines.
 *
 * Crypto-allow-list, backup RPO target tier, patch-cadence SLAs (per
 * severity), continuity RTO targets per criticality (only when BCM is
 * in scope) and the DORA-specific block (only when DORA is in scope).
 *
 * Form-Audit follow-up (May 2026): the step also collects the
 * operational responsible-persons (DPO, BCM-Officer) so the cross-step
 * consistency validator can flag missing assignments before the user
 * lands on Step 7. The role-assignment in Step 3 (RolesStep) remains
 * the authoritative source — Step 5 simply lets the user CONFIRM /
 * OVERRIDE for the operational baselines context (DORA significant
 * entity → DPO required; BCM-in-scope → BCM-Officer required).
 */
final class OperationalBaselinesStep extends AbstractStep
{
    public const ALLOWED_CRYPTO_ALGOS = ['AES-256-GCM', 'AES-128-GCM', 'CHACHA20-POLY1305', 'RSA-3072', 'RSA-4096', 'ECDSA-P256', 'ECDSA-P384'];

    public const PATCH_SEVERITIES = ['critical', 'high', 'medium'];

    public const CONTINUITY_CRITICALITY_LEVELS = ['high', 'medium', 'low'];

    public const DORA_ENTITY_TYPES = [
        'kreditinstitut',
        'wertpapierfirma',
        'zahlungsdienstleister',
        'e_geld_institut',
        'fmi',
        'sonstige',
    ];

    public const DORA_COMPETENT_AUTHORITIES = [
        'BaFin',
        'Bundesbank',
        'ECB',
        'EBA',
        'sonstige',
    ];

    public function key(): string
    {
        return WizardStepKeys::STEP_OPERATIONAL_BASELINES;
    }

    public function validate(WizardRun $run, array $input): array
    {
        $errors = [];

        // Crypto allow-list
        $crypto = $input['crypto_allowlist'] ?? [];
        if (!is_array($crypto)) {
            $errors['crypto_allowlist'][] = 'policy_wizard.error.crypto_allowlist_invalid';
            $crypto = [];
        }
        $crypto = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => is_string($v) ? strtoupper(trim($v)) : '',
            $crypto,
        ))));
        if ($crypto === []) {
            $errors['crypto_allowlist'][] = 'policy_wizard.error.crypto_allowlist_required';
        }

        // Backup RPO (hours) — must be a positive integer.
        $rpoHours = $input['backup_rpo_hours'] ?? null;
        if ($rpoHours === null || !is_numeric($rpoHours)) {
            $errors['backup_rpo_hours'][] = 'policy_wizard.error.backup_rpo_required';
            $rpoHours = null;
        } else {
            $rpoHours = (int) $rpoHours;
            if ($rpoHours <= 0 || $rpoHours > 24 * 30) {
                $errors['backup_rpo_hours'][] = 'policy_wizard.error.backup_rpo_invalid';
                $rpoHours = null;
            }
        }

        // Patch cadence SLAs by severity.
        $patchSlaHours = $input['patch_sla_hours'] ?? [];
        if (!is_array($patchSlaHours)) {
            $errors['patch_sla_hours'][] = 'policy_wizard.error.patch_sla_invalid';
            $patchSlaHours = [];
        }
        $normalisedSla = [];
        foreach (self::PATCH_SEVERITIES as $sev) {
            $val = $patchSlaHours[$sev] ?? null;
            if ($val !== null && is_numeric($val) && (int) $val > 0) {
                $normalisedSla[$sev] = (int) $val;
            } else {
                $errors['patch_sla_hours'][] = 'policy_wizard.error.patch_sla_required.' . $sev;
            }
        }

        $standards = $run->getStandardsAdopted() ?? [];

        // Continuity RTO — only when BCM in scope.
        $continuityRto = null;
        if (in_array('bcm', $standards, true)) {
            $rto = $input['continuity_rto_hours'] ?? [];
            if (!is_array($rto)) {
                $errors['continuity_rto_hours'][] = 'policy_wizard.error.continuity_rto_invalid';
                $rto = [];
            }
            $continuityRto = [];
            foreach ($rto as $criticality => $hours) {
                if (!is_string($criticality) || !is_numeric($hours)) {
                    continue;
                }
                $continuityRto[$criticality] = (int) $hours;
            }
            if ($continuityRto === []) {
                $errors['continuity_rto_hours'][] = 'policy_wizard.error.continuity_rto_required';
            }
        }

        // DORA-specific block — only when DORA in scope.
        $doraBlock = null;
        if (in_array('dora', $standards, true)) {
            $dora = $input['dora'] ?? [];
            if (!is_array($dora)) {
                $errors['dora'][] = 'policy_wizard.error.dora_block_invalid';
                $dora = [];
            }
            $doraBlock = [
                'entity_type' => is_string($dora['entity_type'] ?? null) ? $dora['entity_type'] : null,
                'is_significant' => (bool) ($dora['is_significant'] ?? false),
                // Backwards-compat: previously stored as `significance` string.
                'significance' => is_string($dora['significance'] ?? null) ? $dora['significance'] : null,
                'competent_authority' => is_string($dora['competent_authority'] ?? null)
                    ? $dora['competent_authority']
                    : null,
                'ictt_concentration_threshold_pct' => is_numeric($dora['ictt_concentration_threshold_pct'] ?? null)
                    ? (int) $dora['ictt_concentration_threshold_pct']
                    : null,
                'is_ctpp_self_assessment' => (bool) ($dora['is_ctpp_self_assessment'] ?? false),
            ];
            // `significance` is derived from is_significant when not
            // explicitly provided so older runs keep working.
            if ($doraBlock['significance'] === null || $doraBlock['significance'] === '') {
                $doraBlock['significance'] = $doraBlock['is_significant'] ? 'significant' : 'standard';
            }
            foreach (['entity_type', 'competent_authority'] as $req) {
                if ($doraBlock[$req] === null || $doraBlock[$req] === '') {
                    $errors['dora'][] = 'policy_wizard.error.dora_block_required.' . $req;
                }
            }
            if ($doraBlock['ictt_concentration_threshold_pct'] !== null) {
                $pct = $doraBlock['ictt_concentration_threshold_pct'];
                if ($pct < 1 || $pct > 100) {
                    $errors['dora'][] = 'policy_wizard.error.dora_block_invalid_threshold';
                    $doraBlock['ictt_concentration_threshold_pct'] = null;
                }
            }
        }

        // Operational responsible-persons (DPO + BCM-Officer). Optional
        // here — RolesStep is authoritative; Step 5 just confirms.
        $dpoUserId = $input['dpo_user_id'] ?? null;
        $dpoUserId = is_numeric($dpoUserId) && (int) $dpoUserId > 0 ? (int) $dpoUserId : null;

        $bcmOfficerUserId = $input['bcm_officer_user_id'] ?? null;
        $bcmOfficerUserId = is_numeric($bcmOfficerUserId) && (int) $bcmOfficerUserId > 0
            ? (int) $bcmOfficerUserId
            : null;

        // IndustryPresetBundle picker — optional one-shot apply hint.
        $industryPresetBundleKey = $input['industry_preset_bundle_key'] ?? null;
        $industryPresetBundleKey = is_string($industryPresetBundleKey) && $industryPresetBundleKey !== ''
            ? $industryPresetBundleKey
            : null;

        $normalised = [
            'crypto_allowlist' => $crypto,
            'backup_rpo_hours' => $rpoHours,
            'patch_sla_hours' => $normalisedSla,
            'continuity_rto_hours' => $continuityRto,
            'dora' => $doraBlock,
            'dpo_user_id' => $dpoUserId,
            'bcm_officer_user_id' => $bcmOfficerUserId,
            'industry_preset_bundle_key' => $industryPresetBundleKey,
        ];

        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }
}
