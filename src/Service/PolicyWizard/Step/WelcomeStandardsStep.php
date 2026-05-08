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

        // Industry-Preset bundle (W4-B) — apply BEFORE other validation
        // so the bundle's preselectedStandards becomes the default for
        // an empty `standards` submission. The applier is no-op when
        // the user already picked their own standards.
        $bundleKeyRaw = $input['industry_preset_bundle_key'] ?? null;
        $bundleKey = is_string($bundleKeyRaw) ? trim($bundleKeyRaw) : null;
        if ($bundleKey === '') {
            $bundleKey = null;
        }
        $appliedBundle = null;
        if ($bundleKey !== null
            && $this->presetBundleRepository !== null
            && $this->presetBundleApplier !== null
        ) {
            $bundle = $this->presetBundleRepository->findByKey($bundleKey);
            if ($bundle instanceof IndustryPresetBundle && $bundle->isActive()) {
                $this->presetBundleApplier->applyTo($run, $bundle);
                $appliedBundle = $bundle;
                // If the user did not submit any standards, inherit the
                // bundle's preselectedStandards so validation does not
                // bounce them with `standards_required`.
                $rawStandards = $input['standards'] ?? [];
                if (!is_array($rawStandards) || $rawStandards === []) {
                    $input['standards'] = $bundle->getPreselectedStandards();
                }
            } else {
                $errors['industry_preset_bundle_key'][] = 'policy_wizard.error.preset_bundle_unknown';
                $bundleKey = null;
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

        $normalised = [
            'standards' => $standards,
            'mode' => $mode,
            'finding_reference' => $findingRef,
            'industry_preset_bundle_key' => $appliedBundle instanceof IndustryPresetBundle
                ? $appliedBundle->getKey()
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
