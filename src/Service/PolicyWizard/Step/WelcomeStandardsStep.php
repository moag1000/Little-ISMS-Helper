<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\IndustryPresetBundle;
use App\Entity\WizardRun;
use App\Repository\IndustryPresetBundleRepository;
use App\Service\PolicyWizard\PresetBundleApplier;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Step 1 — Welcome + Standards selection.
 *
 * User picks the standards mix (ISO 27001 / BSI / DORA addon /
 * GDPR-scope / BCM coverage) and chooses a run mode (full / targeted /
 * sandbox). On submit we update `WizardRun.standardsAdopted` and
 * `WizardRun.mode` directly so downstream steps can branch on them.
 *
 * Full spec: `docs/plans/policy-wizard/05-architecture.md` §6.2 Step 1.
 */
final class WelcomeStandardsStep extends AbstractStep
{
    public const ALLOWED_STANDARDS = [
        'iso27001',
        'bsi',
        'dora',
        'gdpr',
        'bcm',
        'iso27701',
        // Compliance-Manager-Persona feedback (May 2026): mapping
        // catalogues for these four frameworks already ship via
        // Seed{Nis2,Tisax,Soc2,C52026}Iso27001MappingsCommand — but
        // Step 1 hid the toggles so customers regulated by NIS2 /
        // TISAX / SOC 2 / BSI C5 had to bolt the mapping on by hand.
        // Surfacing them in the picker closes that vertriebs-blocker.
        'nis2',
        'tisax',
        'soc2',
        'c5',
    ];

    public const ALLOWED_MODES = [
        WizardStepKeys::MODE_FULL,
        WizardStepKeys::MODE_TARGETED,
        WizardStepKeys::MODE_SANDBOX,
    ];

    /**
     * Optional collaborators — the W4-B IndustryPresetBundle picker
     * uses them when the user picks a sector preset. Wired via
     * service-container autowiring; nullable so legacy callers (and
     * older tests that instantiate the step directly) keep working.
     */
    public function __construct(
        private readonly ?IndustryPresetBundleRepository $presetBundleRepository = null,
        private readonly ?PresetBundleApplier $presetBundleApplier = null,
    ) {
    }

    public function key(): string
    {
        return WizardStepKeys::STEP_WELCOME;
    }

    /**
     * Welcome step is always applicable (every mode passes through it
     * before the flow branches).
     */
    public function isApplicable(WizardRun $run): bool
    {
        return true;
    }

    public function validate(WizardRun $run, array $input): array
    {
        $errors = [];

        // Industry-Preset bundles (W4-B multi-select) — apply BEFORE other
        // validation so preselectedStandards become the default for an empty
        // `standards` submission. Accepts both:
        //   - `industry_preset_bundle_keys[]` (multi-select, canonical)
        //   - `industry_preset_bundle_key`     (single-select, backwards-compat)
        // Later entries in the keys array win for scalar fields (later-wins).
        $rawMultiKeys = $input['industry_preset_bundle_keys'] ?? null;
        if (is_array($rawMultiKeys) && $rawMultiKeys !== []) {
            $bundleKeys = array_values(array_filter(array_map(
                static fn ($v): string => is_string($v) ? trim($v) : '',
                $rawMultiKeys,
            ), static fn (string $k): bool => $k !== ''));
        } else {
            // Backwards-compat: single key field (old wizard-runs in DB).
            $singleRaw = $input['industry_preset_bundle_key'] ?? null;
            $singleKey = is_string($singleRaw) ? trim($singleRaw) : '';
            $bundleKeys = $singleKey !== '' ? [$singleKey] : [];
        }

        $appliedBundles = [];
        if ($bundleKeys !== []
            && $this->presetBundleRepository !== null
            && $this->presetBundleApplier !== null
        ) {
            $loadedBundles = [];
            foreach ($bundleKeys as $bundleKey) {
                $bundle = $this->presetBundleRepository->findByKey($bundleKey);
                if ($bundle instanceof IndustryPresetBundle && $bundle->isActive()) {
                    $loadedBundles[] = $bundle;
                } else {
                    $errors['industry_preset_bundle_keys'][] = 'policy_wizard.error.preset_bundle_unknown';
                }
            }
            if ($loadedBundles !== []) {
                $result = $this->presetBundleApplier->applyAll($run, $loadedBundles);
                $appliedBundles = $loadedBundles;
                // Store detected conflicts in the run's _preset_flags for
                // later retrieval by the template conflict-notice system.
                if ($result['conflicts'] !== []) {
                    $bag = $run->getInputs() ?? [];
                    $bag['_preset_flags'] = is_array($bag['_preset_flags'] ?? null) ? $bag['_preset_flags'] : [];
                    $bag['_preset_flags']['bundle_conflicts'] = $result['conflicts'];
                    $run->setInputs($bag);
                }
                // If the user did not submit any standards, inherit the
                // union of all bundles' preselectedStandards.
                $rawStandards = $input['standards'] ?? [];
                if (!is_array($rawStandards) || $rawStandards === []) {
                    $bag = $run->getInputs() ?? [];
                    $welcomeSlot = is_array($bag[WizardStepKeys::STEP_WELCOME] ?? null)
                        ? $bag[WizardStepKeys::STEP_WELCOME]
                        : [];
                    $input['standards'] = is_array($welcomeSlot['standards'] ?? null)
                        ? $welcomeSlot['standards']
                        : [];
                }
            }
        }

        $standards = $input['standards'] ?? [];
        if (!is_array($standards) || $standards === []) {
            $errors['standards'][] = 'policy_wizard.error.standards_required';
            $standards = [];
        }

        $standards = array_values(array_unique(array_map(
            static fn ($v): string => is_string($v) ? strtolower($v) : '',
            $standards,
        )));
        $standards = array_values(array_filter($standards, static fn (string $s): bool => $s !== ''));

        foreach ($standards as $standard) {
            if (!in_array($standard, self::ALLOWED_STANDARDS, true)) {
                $errors['standards'][] = 'policy_wizard.error.standard_unknown';
                break;
            }
        }

        // ISO 27001 must be present unless a GDPR-only or 27701-only run is
        // explicitly configured (architecture §12 GDPR-only + §3 PIMS).
        $isGdprOnly = $standards === ['gdpr'] || $standards === ['iso27701'];
        if (!$isGdprOnly && !in_array('iso27001', $standards, true)) {
            $errors['standards'][] = 'policy_wizard.error.iso27001_required';
        }

        $mode = $input['mode'] ?? WizardStepKeys::MODE_FULL;
        if (!is_string($mode) || !in_array($mode, self::ALLOWED_MODES, true)) {
            $errors['mode'][] = 'policy_wizard.error.mode_invalid';
            $mode = WizardStepKeys::MODE_FULL;
        }

        $findingRef = $input['finding_reference'] ?? null;
        if ($findingRef !== null) {
            if (!is_string($findingRef) || strlen($findingRef) > 100) {
                $errors['finding_reference'][] = 'policy_wizard.error.finding_reference_invalid';
                $findingRef = null;
            } else {
                $findingRef = trim($findingRef);
                if ($findingRef === '') {
                    $findingRef = null;
                }
            }
        }

        // Build canonical applied-keys list (multi-select).
        $appliedBundleKeys = array_map(
            static fn (IndustryPresetBundle $b): string => $b->getKey(),
            $appliedBundles,
        );
        $normalised = [
            'standards' => $standards,
            'mode' => $mode,
            'finding_reference' => $findingRef,
            // Multi-key (canonical, new runs).
            'industry_preset_bundle_keys' => $appliedBundleKeys,
            // Single-key (backwards-compat: last applied wins, or null).
            'industry_preset_bundle_key' => $appliedBundles !== []
                ? end($appliedBundles)->getKey()
                : null,
        ];

        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }

    public function persist(WizardRun $run, array $input): void
    {
        // Side-effect: hoist standards + mode + finding_reference onto
        // the run's first-class columns so downstream code can branch
        // without inspecting the inputs JSON.
        if (isset($input['standards']) && is_array($input['standards'])) {
            $run->setStandardsAdopted(array_values($input['standards']));
        }
        if (isset($input['mode']) && is_string($input['mode'])) {
            $run->setMode($input['mode']);
            // Sandbox runs flip status immediately so the resume / cancel
            // path knows we are in preview mode.
            if ($input['mode'] === WizardStepKeys::MODE_SANDBOX) {
                $run->setStatus(WizardStepKeys::STATUS_SANDBOX);
            }
        }
        if (array_key_exists('finding_reference', $input)) {
            $ref = $input['finding_reference'];
            $run->setFindingReference(is_string($ref) && $ref !== '' ? $ref : null);
        }

        parent::persist($run, $input);
    }
}
