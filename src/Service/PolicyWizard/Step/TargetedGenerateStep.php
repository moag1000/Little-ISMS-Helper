<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Targeted Re-Run Step 4 — generate.
 *
 * Final-step wrapper that mirrors {@see ReviewGenerateStep} for the
 * targeted re-run flow. Confirmation gate before the orchestrator
 * fires the DocumentGenerator on the picked topic subset.
 */
final class TargetedGenerateStep extends AbstractStep
{
    public function key(): string
    {
        return WizardStepKeys::STEP_TARGETED_GENERATE;
    }

    public function isApplicable(WizardRun $run): bool
    {
        return $run->getMode() === WizardStepKeys::MODE_TARGETED;
    }

    public function validate(WizardRun $run, array $input): array
    {
        $errors = [];

        $confirm = (bool) ($input['confirm'] ?? false);
        if (!$confirm) {
            $errors['confirm'][] = 'policy_wizard.error.confirmation_required';
        }

        $normalised = [
            'confirm' => $confirm,
            'topics' => $run->getTargetedTopics() ?? [],
            'finding_reference' => $run->getFindingReference(),
        ];

        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }
}
