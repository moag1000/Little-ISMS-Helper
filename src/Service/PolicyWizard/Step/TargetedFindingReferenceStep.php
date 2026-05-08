<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Targeted Re-Run Step 2 — optional audit-finding reference.
 *
 * P1 ISB: surfaces in audit log so future auditors can see "this 3-
 * policy fix was triggered by Finding NCR-2026-04".
 */
final class TargetedFindingReferenceStep extends AbstractStep
{
    public function key(): string
    {
        return WizardStepKeys::STEP_TARGETED_FINDING;
    }

    public function isApplicable(WizardRun $run): bool
    {
        return $run->getMode() === WizardStepKeys::MODE_TARGETED;
    }

    public function validate(WizardRun $run, array $input): array
    {
        $errors = [];

        $finding = $input['finding_reference'] ?? null;
        if ($finding !== null) {
            if (!is_string($finding)) {
                $errors['finding_reference'][] = 'policy_wizard.error.finding_reference_invalid';
                $finding = null;
            } else {
                $finding = trim($finding);
                if ($finding === '') {
                    $finding = null;
                } elseif (strlen($finding) > 100) {
                    $errors['finding_reference'][] = 'policy_wizard.error.finding_reference_too_long';
                    $finding = substr($finding, 0, 100);
                }
            }
        }

        $normalised = ['finding_reference' => $finding];
        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }

    public function persist(WizardRun $run, array $input): void
    {
        parent::persist($run, $input);
        if (array_key_exists('finding_reference', $input)) {
            $ref = $input['finding_reference'];
            $run->setFindingReference(is_string($ref) && $ref !== '' ? $ref : null);
        }
    }
}
