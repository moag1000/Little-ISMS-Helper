<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\IndustryPresetBundle;
use App\Entity\WizardRun;

/**
 * Policy-Wizard W4-B — applies an {@see IndustryPresetBundle} to a
 * {@see WizardRun}, pre-filling the run's `inputs` snapshot with
 * sector-specific defaults.
 *
 * Contract:
 *   - The applier MUST be called BEFORE the user submits Step 1, so
 *     the user can still tweak any pre-filled value.
 *   - Existing user input takes precedence: if a slot already carries
 *     a non-empty value the bundle does NOT overwrite it (idempotent
 *     re-apply, also covers re-renders after validation errors).
 *   - The bundle's `preselectedStandards` is merged into the welcome
 *     slot's `standards` field; the run's first-class
 *     `standardsAdopted` column is also synchronised so downstream
 *     steps that branch on `WizardRun::getStandardsAdopted()` already
 *     see the right mix.
 *   - Inactive bundles are rejected — the caller gets an
 *     {@see \InvalidArgumentException}.
 *
 * Spec: docs/plans/policy-wizard/05-architecture.md §6 Step 4 +
 *       docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md §3 W4.
 */
final class PresetBundleApplier
{
    /**
     * Privacy-overlay flag for the German healthcare sector — adds the
     * § 22 BDSG (special-category exception) overlay to RoPA / DPIA /
     * §2.16 templates per `06-dpo-input.md` §7.1.
     */
    public const string PRIVACY_OVERLAY_HEALTHCARE_BDSG22 = 'healthcare_bdsg22';

    /**
     * Privacy-overlay flag for the German public sector — adds the
     * BBG / state-DSG overlay (BDSG § 70 ff., BArchG § 5) per
     * `06-dpo-input.md` §7.6.
     */
    public const string PRIVACY_OVERLAY_PUBLIC_SECTOR_BBG = 'public_sector_bbg';

    /**
     * Per-bundle privacy-overlay map. Bundles without a privacy overlay
     * (B2C-SaaS = default GDPR; OT/IEC 62443 = no privacy concern) are
     * intentionally omitted — the absence is the signal.
     *
     * Spec: `docs/plans/policy-wizard/06-dpo-input.md` §7 sectoral
     * overlays. Healthcare → § 22 BDSG; Public-Sector → BBG / state-DSG;
     * B2C-SaaS → default GDPR (no overlay row); OT → no privacy overlay
     * (operational-technology, no personal data flow by definition).
     *
     * @var array<string, string>
     */
    private const array PRIVACY_OVERLAY_PER_BUNDLE = [
        IndustryPresetBundle::KEY_HEALTHCARE => self::PRIVACY_OVERLAY_HEALTHCARE_BDSG22,
        IndustryPresetBundle::KEY_PUBLIC_SECTOR => self::PRIVACY_OVERLAY_PUBLIC_SECTOR_BBG,
        // KEY_B2C_SAAS, KEY_OT_IEC62443 → no overlay (default GDPR / no privacy).
    ];

    /**
     * Pre-fill the run's `inputs` JSON with the bundle's defaults.
     * Returns the run for fluent chaining; the caller is responsible
     * for flushing the EntityManager.
     */
    public function applyTo(WizardRun $run, IndustryPresetBundle $bundle): WizardRun
    {
        if (!$bundle->isActive()) {
            throw new \InvalidArgumentException(sprintf(
                'IndustryPresetBundle "%s" is inactive and cannot be applied.',
                $bundle->getKey(),
            ));
        }

        $bag = $run->getInputs() ?? [];

        // ── Step 1: welcome / standards mix ────────────────────────────
        $welcomeSlot = is_array($bag[WizardStepKeys::STEP_WELCOME] ?? null)
            ? $bag[WizardStepKeys::STEP_WELCOME]
            : [];
        $existingStandards = is_array($welcomeSlot['standards'] ?? null)
            ? $welcomeSlot['standards']
            : [];
        if ($existingStandards === []) {
            $welcomeSlot['standards'] = $bundle->getPreselectedStandards();
        }
        // Track which bundle was applied (UI surface + audit trail).
        $welcomeSlot['industry_preset_bundle_key'] = $bundle->getKey();
        $bag[WizardStepKeys::STEP_WELCOME] = $welcomeSlot;

        // Mirror onto first-class column when caller has not yet set it.
        if ($run->getStandardsAdopted() === null || $run->getStandardsAdopted() === []) {
            $run->setStandardsAdopted($bundle->getPreselectedStandards());
        }

        // ── Step 4: risk_classification ────────────────────────────────
        $riskSlot = is_array($bag[WizardStepKeys::STEP_RISK_CLASSIFICATION] ?? null)
            ? $bag[WizardStepKeys::STEP_RISK_CLASSIFICATION]
            : [];
        if (!$this->slotHasValue($riskSlot, 'risk_appetite_tier')) {
            $riskSlot['risk_appetite_tier'] = $bundle->getDefaultRiskAppetiteTier();
        }
        if (!$this->slotHasValue($riskSlot, 'data_classification_levels')) {
            $riskSlot['data_classification_levels'] = $bundle->getDefaultDataClassificationLevels();
        }
        $existingOverrides = is_array($riskSlot['annex_a_applicability'] ?? null)
            ? $riskSlot['annex_a_applicability']
            : [];
        $bundleOverrides = $bundle->getAnnexAApplicabilityOverrides();
        if ($bundleOverrides !== []) {
            // User-set entries win over bundle entries; fill the gaps.
            foreach ($bundleOverrides as $controlId => $applicability) {
                if (!array_key_exists($controlId, $existingOverrides)) {
                    $existingOverrides[$controlId] = $applicability;
                }
            }
            $riskSlot['annex_a_applicability'] = $existingOverrides;
        }
        $bag[WizardStepKeys::STEP_RISK_CLASSIFICATION] = $riskSlot;

        // ── Step 5: operational_baselines ──────────────────────────────
        $opSlot = is_array($bag[WizardStepKeys::STEP_OPERATIONAL_BASELINES] ?? null)
            ? $bag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]
            : [];
        if (!$this->slotHasValue($opSlot, 'backup_rpo_hours')) {
            $opSlot['backup_rpo_hours'] = $bundle->getDefaultBackupRpoHours();
        }
        $existingPatchSla = is_array($opSlot['patch_sla_hours'] ?? null)
            ? $opSlot['patch_sla_hours']
            : [];
        if (!array_key_exists('critical', $existingPatchSla)) {
            $existingPatchSla['critical'] = $bundle->getDefaultPatchSlaCriticalHours();
            $opSlot['patch_sla_hours'] = $existingPatchSla;
        }
        $bag[WizardStepKeys::STEP_OPERATIONAL_BASELINES] = $opSlot;

        // ── DPO / privacy auto-enable flag ─────────────────────────────
        if ($bundle->isDpoSectionsAutoEnabled()) {
            $bag['_preset_flags'] = is_array($bag['_preset_flags'] ?? null) ? $bag['_preset_flags'] : [];
            $bag['_preset_flags']['dpo_sections_auto_enabled'] = true;
        }

        // ── W6-B Sectoral privacy overlays ─────────────────────────────
        // Spec: `06-dpo-input.md` §7. The overlay flag drives subsequent
        // template generation (e.g. §2.16 Special-Category-Data adds
        // § 22 BDSG section for healthcare; §2.2 RoPA adds public-body
        // variant for public_sector). Stored on the run's _preset_flags
        // bag (idempotent — same key wins on re-apply, last bundle wins).
        $overlay = self::PRIVACY_OVERLAY_PER_BUNDLE[$bundle->getKey()] ?? null;
        if ($overlay !== null) {
            $bag['_preset_flags'] = is_array($bag['_preset_flags'] ?? null) ? $bag['_preset_flags'] : [];
            $existing = is_array($bag['_preset_flags']['privacy_overlays'] ?? null)
                ? $bag['_preset_flags']['privacy_overlays']
                : [];
            if (!in_array($overlay, $existing, true)) {
                $existing[] = $overlay;
            }
            $bag['_preset_flags']['privacy_overlays'] = array_values($existing);
        }

        $run->setInputs($bag);

        return $run;
    }

    /**
     * Detect whether a slot already carries a "real" value for a key.
     * Empty strings, empty arrays and nulls are treated as "absent" so
     * the bundle defaults still apply.
     *
     * @param array<string, mixed> $slot
     */
    private function slotHasValue(array $slot, string $key): bool
    {
        if (!array_key_exists($key, $slot)) {
            return false;
        }
        $value = $slot[$key];
        if ($value === null || $value === '') {
            return false;
        }
        if (is_array($value) && $value === []) {
            return false;
        }
        return true;
    }
}
