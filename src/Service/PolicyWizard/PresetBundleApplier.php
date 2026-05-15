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
     * Apply multiple bundles to a single run in selection order.
     *
     * Merge strategy:
     *   - Scalar slots (risk_appetite_tier, backup_rpo_hours,
     *     default_patch_sla_critical_hours): LATER-WINS — the last
     *     bundle in the array overrides earlier bundle values.  User
     *     input (values present in the run BEFORE any bundle is applied)
     *     always wins over all bundle values.
     *   - Set/union slots (standards, annex_a_applicability,
     *     privacy_overlays): UNION — all bundles contribute; user
     *     input still takes precedence.
     *   - `dpo_sections_auto_enabled`: OR — any bundle that sets it
     *     true keeps it true for the run.
     *
     * Scalar-field conflicts (two bundles disagree on the same key)
     * are surfaced in the returned conflict-map so the caller can
     * render a user-facing notice.  The conflict-map shape:
     *
     *   [
     *     'risk_appetite_tier' => ['healthcare' => 1, 'b2c_saas' => 3],
     *     ...
     *   ]
     *
     * Returns the run for fluent chaining; the caller must flush the EM.
     *
     * @param list<IndustryPresetBundle> $bundles
     * @return array{run: WizardRun, conflicts: array<string, array<string, mixed>>}
     */
    public function applyAll(WizardRun $run, array $bundles): array
    {
        if ($bundles === []) {
            return ['run' => $run, 'conflicts' => []];
        }

        // Snapshot user-provided scalar values BEFORE any bundle is applied.
        // These take priority over all bundle defaults (user input always wins).
        $existingBag = $run->getInputs() ?? [];
        $userRiskSlot = is_array($existingBag[WizardStepKeys::STEP_RISK_CLASSIFICATION] ?? null)
            ? $existingBag[WizardStepKeys::STEP_RISK_CLASSIFICATION]
            : [];
        $userOpSlot = is_array($existingBag[WizardStepKeys::STEP_OPERATIONAL_BASELINES] ?? null)
            ? $existingBag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]
            : [];
        $userStandardsAdopted = $run->getStandardsAdopted();

        // Collect user-provided scalar values (non-empty = explicit user input).
        $userScalars = [
            'risk_appetite_tier' => $this->slotHasValue($userRiskSlot, 'risk_appetite_tier')
                ? $userRiskSlot['risk_appetite_tier'] : null,
            'data_classification_levels' => $this->slotHasValue($userRiskSlot, 'data_classification_levels')
                ? $userRiskSlot['data_classification_levels'] : null,
            'backup_rpo_hours' => $this->slotHasValue($userOpSlot, 'backup_rpo_hours')
                ? $userOpSlot['backup_rpo_hours'] : null,
            'patch_sla_hours_critical' => isset($userOpSlot['patch_sla_hours']['critical'])
                ? $userOpSlot['patch_sla_hours']['critical'] : null,
        ];

        // Apply bundles in REVERSE order so that applyTo's "first-wins"
        // (skip if slot non-empty) effectively implements LATER-WINS:
        // last-in-list bundle is applied first → fills slots.
        // Earlier bundles (applied second, third…) are blocked by applyTo's
        // guard, so the LAST bundle's values survive. This means:
        //   bundles=[A, B] → apply B first (B wins), then apply A (A blocked).
        // User scalars are cleared temporarily so bundle values can flow in;
        // they are restored with full priority after all bundles are applied.
        $wipBag = $existingBag;
        // Clear scalar slots so bundles can fill them.
        if (isset($wipBag[WizardStepKeys::STEP_RISK_CLASSIFICATION])) {
            unset(
                $wipBag[WizardStepKeys::STEP_RISK_CLASSIFICATION]['risk_appetite_tier'],
                $wipBag[WizardStepKeys::STEP_RISK_CLASSIFICATION]['data_classification_levels'],
            );
        }
        if (isset($wipBag[WizardStepKeys::STEP_OPERATIONAL_BASELINES])) {
            unset($wipBag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]['backup_rpo_hours']);
            if (isset($wipBag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]['patch_sla_hours']['critical'])) {
                unset($wipBag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]['patch_sla_hours']['critical']);
            }
        }
        // Clear standards and multi-key tracker — rebuilt fresh below.
        if (isset($wipBag[WizardStepKeys::STEP_WELCOME])) {
            $wipBag[WizardStepKeys::STEP_WELCOME]['standards'] = [];
            $wipBag[WizardStepKeys::STEP_WELCOME]['industry_preset_bundle_keys'] = [];
            unset($wipBag[WizardStepKeys::STEP_WELCOME]['industry_preset_bundle_key']);
        }
        // Also clear first-class standardsAdopted so applyTo can set it.
        $run->setStandardsAdopted(null);
        $run->setInputs($wipBag);

        // Apply in reverse (later-wins via applyTo first-wins logic).
        $reversed = array_reverse($bundles);
        foreach ($reversed as $bundle) {
            $this->applyTo($run, $bundle);
        }

        // Standards UNION: all bundle standards combined (regardless of
        // apply order). Standards from every bundle are merged additively.
        $allBundleStandards = [];
        foreach ($bundles as $bundle) {
            foreach ($bundle->getPreselectedStandards() as $std) {
                $allBundleStandards[] = $std;
            }
        }
        $allBundleStandards = array_values(array_unique($allBundleStandards));

        // Merge user standards (if any) with the union of bundle standards.
        $baseStandards = $userStandardsAdopted !== null && $userStandardsAdopted !== []
            ? array_values(array_unique(array_merge($userStandardsAdopted, $allBundleStandards)))
            : $allBundleStandards;

        $run->setStandardsAdopted($baseStandards);
        $finalBag = $run->getInputs() ?? [];
        if (isset($finalBag[WizardStepKeys::STEP_WELCOME])) {
            $finalBag[WizardStepKeys::STEP_WELCOME]['standards'] = $baseStandards;
        }

        // Re-overlay explicit user scalars (user input always wins over bundles).
        $riskSlot = is_array($finalBag[WizardStepKeys::STEP_RISK_CLASSIFICATION] ?? null)
            ? $finalBag[WizardStepKeys::STEP_RISK_CLASSIFICATION]
            : [];
        if ($userScalars['risk_appetite_tier'] !== null) {
            $riskSlot['risk_appetite_tier'] = $userScalars['risk_appetite_tier'];
        }
        if ($userScalars['data_classification_levels'] !== null) {
            $riskSlot['data_classification_levels'] = $userScalars['data_classification_levels'];
        }
        $finalBag[WizardStepKeys::STEP_RISK_CLASSIFICATION] = $riskSlot;

        $opSlot = is_array($finalBag[WizardStepKeys::STEP_OPERATIONAL_BASELINES] ?? null)
            ? $finalBag[WizardStepKeys::STEP_OPERATIONAL_BASELINES]
            : [];
        if ($userScalars['backup_rpo_hours'] !== null) {
            $opSlot['backup_rpo_hours'] = $userScalars['backup_rpo_hours'];
        }
        if ($userScalars['patch_sla_hours_critical'] !== null) {
            $opSlot['patch_sla_hours'] = array_merge(
                is_array($opSlot['patch_sla_hours'] ?? null) ? $opSlot['patch_sla_hours'] : [],
                ['critical' => $userScalars['patch_sla_hours_critical']],
            );
        }
        $finalBag[WizardStepKeys::STEP_OPERATIONAL_BASELINES] = $opSlot;

        $run->setInputs($finalBag);

        // Rebuild multi-key tracker in ORIGINAL order (for UI display + audit).
        $finalBag2 = $run->getInputs() ?? [];
        if (isset($finalBag2[WizardStepKeys::STEP_WELCOME])) {
            $finalBag2[WizardStepKeys::STEP_WELCOME]['industry_preset_bundle_keys'] = array_map(
                static fn (IndustryPresetBundle $b): string => $b->getKey(),
                $bundles,
            );
            // Backwards-compat single-key = last in original order.
            $lastBundle = end($bundles);
            $finalBag2[WizardStepKeys::STEP_WELCOME]['industry_preset_bundle_key'] = $lastBundle->getKey();
        }
        $run->setInputs($finalBag2);

        // Detect scalar-field conflicts — two bundles with different values
        // for the same field. Last-in-list value wins but user should know.
        $conflicts = [];
        $scalarGetters = [
            'risk_appetite_tier' => static fn (IndustryPresetBundle $b): int => $b->getDefaultRiskAppetiteTier(),
            'data_classification_levels' => static fn (IndustryPresetBundle $b): int => $b->getDefaultDataClassificationLevels(),
            'backup_rpo_hours' => static fn (IndustryPresetBundle $b): int => $b->getDefaultBackupRpoHours(),
        ];
        foreach ($scalarGetters as $field => $getter) {
            $seenValues = [];
            foreach ($bundles as $bundle) {
                $seenValues[$bundle->getKey()] = $getter($bundle);
            }
            $distinctValues = array_unique(array_values($seenValues));
            if (count($distinctValues) > 1) {
                $conflicts[$field] = $seenValues;
            }
        }

        return ['run' => $run, 'conflicts' => $conflicts];
    }

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
        // Track which bundles were applied (UI surface + audit trail).
        // Multi-key array is the canonical store; single-key is kept for
        // backwards-compat with existing runs that read ['industry_preset_bundle_key'].
        $existingKeys = is_array($welcomeSlot['industry_preset_bundle_keys'] ?? null)
            ? $welcomeSlot['industry_preset_bundle_keys']
            : (is_string($welcomeSlot['industry_preset_bundle_key'] ?? null) && $welcomeSlot['industry_preset_bundle_key'] !== ''
                ? [$welcomeSlot['industry_preset_bundle_key']]
                : []);
        if (!in_array($bundle->getKey(), $existingKeys, true)) {
            $existingKeys[] = $bundle->getKey();
        }
        $welcomeSlot['industry_preset_bundle_keys'] = array_values($existingKeys);
        // Backwards-compat: keep single-key pointing to the LAST applied bundle.
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
