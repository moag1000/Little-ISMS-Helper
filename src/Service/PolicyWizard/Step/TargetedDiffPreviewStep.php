<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Targeted Re-Run Step 3 — diff preview.
 *
 * Read-only step. The user inspects the generator's "what would
 * change" snapshot vs. the currently approved documents and confirms
 * via `confirm=true`.
 *
 * Real diff content lands in W3 (DocumentGenerator + diff service);
 * for W2 we just record the user confirmation.
 */
final class TargetedDiffPreviewStep extends AbstractStep
{
    public function key(): string
    {
        return WizardStepKeys::STEP_TARGETED_DIFF;
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
            'reviewed_topics' => $run->getTargetedTopics() ?? [],
        ];

        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }
}
