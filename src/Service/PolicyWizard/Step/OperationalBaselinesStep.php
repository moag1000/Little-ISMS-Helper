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
 */
final class OperationalBaselinesStep extends AbstractStep
{
    public const ALLOWED_CRYPTO_ALGOS = ['AES-256-GCM', 'AES-128-GCM', 'CHACHA20-POLY1305', 'RSA-3072', 'RSA-4096', 'ECDSA-P256', 'ECDSA-P384'];

    public const PATCH_SEVERITIES = ['critical', 'high', 'medium'];

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
                'significance' => is_string($dora['significance'] ?? null) ? $dora['significance'] : null,
                'competent_authority' => is_string($dora['competent_authority'] ?? null)
                    ? $dora['competent_authority']
                    : null,
                'ictt_concentration_threshold_pct' => is_numeric($dora['ictt_concentration_threshold_pct'] ?? null)
                    ? (int) $dora['ictt_concentration_threshold_pct']
                    : null,
                'is_ctpp_self_assessment' => (bool) ($dora['is_ctpp_self_assessment'] ?? false),
            ];
            foreach (['entity_type', 'significance', 'competent_authority'] as $req) {
                if ($doraBlock[$req] === null || $doraBlock[$req] === '') {
                    $errors['dora'][] = 'policy_wizard.error.dora_block_required.' . $req;
                }
            }
        }

        $normalised = [
            'crypto_allowlist' => $crypto,
            'backup_rpo_hours' => $rpoHours,
            'patch_sla_hours' => $normalisedSla,
            'continuity_rto_hours' => $continuityRto,
            'dora' => $doraBlock,
        ];

        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }
}
