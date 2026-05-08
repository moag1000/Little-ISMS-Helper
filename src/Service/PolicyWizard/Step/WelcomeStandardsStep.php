<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
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
