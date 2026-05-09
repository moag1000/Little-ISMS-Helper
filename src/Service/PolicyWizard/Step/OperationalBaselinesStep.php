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

    /**
     * Junior-ISB-friendly default crypto allow-list per BSI TR-02102-1
     * (2024-1 edition) plus modern AEAD/EdDSA suites widely accepted by
     * NIST SP 800-131A. Used by {@see self::defaults()} when the user
     * lands on Step 5 with an empty `crypto_allowlist` slot — the user
     * can still deselect anything they do not want.
     *
     * Set is intentionally narrow: AES-GCM (symmetric), modern hashes
     * (SHA-256+ / SHA3) and modern asymmetric/AEAD (ECDSA on NIST
     * curves, RSA ≥ 3072, EdDSA, ChaCha20-Poly1305). 3DES, MD5, SHA-1
     * and RSA < 3072 are intentionally absent.
     */
    public const BSI_TR02102_DEFAULT_ALGOS = [
        'AES-128-GCM',
        'AES-192-GCM',
        'AES-256-GCM',
        'CHACHA20-POLY1305',
        'SHA-256',
        'SHA-384',
        'SHA-512',
        'SHA3-256',
        'SHA3-384',
        'SHA3-512',
        'ECDH-P256',
        'ECDH-P384',
        'ECDH-P521',
        'ECDH-BRAINPOOLP256R1',
        'ECDH-BRAINPOOLP384R1',
        'ECDH-BRAINPOOLP512R1',
        'ECDSA-P256',
        'ECDSA-P384',
        'ECDSA-P521',
        'RSA-3072',
        'RSA-4096',
        'ED25519',
        'ED448',
    ];

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

    /**
     * Tenant industry-preset bundles that are clearly NOT in DORA's
     * primary scope (financial services). Used by {@see self::defaults()}
     * to surface a "DORA-not-applicable" hint when the user has a
     * non-financial preset selected but DORA is still in their
     * Step-1 standards-mix.
     *
     * @var list<string>
     */
    public const NON_FINANCIAL_BUNDLE_KEYS = [
        'ot_iec62443',
        'b2c_saas',
        'public_sector',
        'custom_general',
    ];

    public function key(): string
    {
        return WizardStepKeys::STEP_OPERATIONAL_BASELINES;
    }

    /**
     * Pre-fill helpers for Junior ISB. Currently:
     *   - BSI-TR-02102-2024 conformant `crypto_allowlist` when the slot
     *     is empty (Wish #1 from Junior-Implementer-Persona feedback).
     *   - `dora.not_applicable_hint` flag when DORA is in scope but the
     *     tenant picked a non-financial industry-preset bundle (Wish #4).
     *
     * Existing user input always wins — this method only fills empty
     * slots, never overrides explicit choices.
     */
    public function defaults(WizardRun $run): array
    {
        $existing = parent::defaults($run);

        if (!isset($existing['crypto_allowlist'])
            || !is_array($existing['crypto_allowlist'])
            || $existing['crypto_allowlist'] === []
        ) {
            $existing['crypto_allowlist'] = self::BSI_TR02102_DEFAULT_ALGOS;
        }

        // DORA-not-applicable detection — only when DORA is in scope.
        $standards = $run->getStandardsAdopted() ?? [];
        if (in_array('dora', $standards, true)) {
            $welcomeSlot = $this->readSlot($run, WizardStepKeys::STEP_WELCOME);
            $bundleKey = is_string($welcomeSlot['industry_preset_bundle_key'] ?? null)
                ? $welcomeSlot['industry_preset_bundle_key']
                : null;
            $doraSlot = is_array($existing['dora'] ?? null) ? $existing['dora'] : [];
            $entityType = is_string($doraSlot['entity_type'] ?? null) && $doraSlot['entity_type'] !== ''
                ? $doraSlot['entity_type']
                : null;

            $existing['_dora_not_applicable_hint'] = $bundleKey !== null
                && in_array($bundleKey, self::NON_FINANCIAL_BUNDLE_KEYS, true)
                && $entityType === null;
        } else {
            $existing['_dora_not_applicable_hint'] = false;
        }

        return $existing;
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

            // Junior-ISB Self-Check (DORA Art. 16 + RTS) — derive
            // `is_significant` server-side from three guided questions
            // when the user filled the Self-Check. Threshold: ≥ 2 yes
            // out of 3 → significant. The raw answers are persisted so
            // the next render restores the Self-Check state.
            $q1 = $this->parseBool($dora['significance_q1'] ?? null);
            $q2 = $this->parseBool($dora['significance_q2'] ?? null);
            $q3 = $this->parseBool($dora['significance_q3'] ?? null);
            $hasSelfCheckAnswers = array_key_exists('significance_q1', $dora)
                || array_key_exists('significance_q2', $dora)
                || array_key_exists('significance_q3', $dora);
            $derivedSignificant = (((int) $q1) + ((int) $q2) + ((int) $q3)) >= 2;

            $doraBlock = [
                'entity_type' => is_string($dora['entity_type'] ?? null) ? $dora['entity_type'] : null,
                'significance_q1' => $q1,
                'significance_q2' => $q2,
                'significance_q3' => $q3,
                // is_significant is server-derived from the Self-Check
                // when the user answered any question; otherwise we
                // honour the legacy boolean flag (backwards-compat for
                // sandbox runs / API callers that bypass the wizard UI).
                'is_significant' => $hasSelfCheckAnswers
                    ? $derivedSignificant
                    : (bool) ($dora['is_significant'] ?? false),
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

    /**
     * Permissive boolean coercion for HTML-form submitted values.
     * Accepts: true, '1', 1, 'true', 'yes', 'ja', 'on'. Everything
     * else (incl. null, '0', empty string, '') → false.
     */
    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'ja', 'on'], true);
        }
        return false;
    }
}
