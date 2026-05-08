<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Step 7 — Review & Generate.
 *
 * Read-only summary of all collected settings. The user confirms by
 * submitting `confirm=true`; HierarchyOverrideValidator runs in the
 * orchestrator before this step is allowed to advance to Generate.
 *
 * In Sandbox mode the generator stub still fires but no Documents are
 * persisted (DocumentGeneratorStub is a no-op for sandbox).
 */
final class ReviewGenerateStep extends AbstractStep
{
    public function key(): string
    {
        return WizardStepKeys::STEP_REVIEW_GENERATE;
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
            'confirmed_at' => $confirm ? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM) : null,
        ];

        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }
}
