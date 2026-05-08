<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Targeted Re-Run Step 1 (after Welcome) — pick the topic subset.
 *
 * Architecture §6.3: up to 10 topics. Skips Steps 2-6 of the default
 * flow because the underlying settings did not change; only the
 * picked subset of documents will regenerate.
 */
final class TargetedPickTopicsStep extends AbstractStep
{
    public const MAX_TOPICS = 10;

    public function key(): string
    {
        return WizardStepKeys::STEP_TARGETED_PICK;
    }

    public function isApplicable(WizardRun $run): bool
    {
        return $run->getMode() === WizardStepKeys::MODE_TARGETED;
    }

    public function validate(WizardRun $run, array $input): array
    {
        $errors = [];

        $topics = $input['topics'] ?? [];
        if (!is_array($topics)) {
            $errors['topics'][] = 'policy_wizard.error.topics_invalid';
            $topics = [];
        }
        $topics = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => is_string($v) ? trim($v) : '',
            $topics,
        ), static fn (string $s): bool => $s !== '')));

        if ($topics === []) {
            $errors['topics'][] = 'policy_wizard.error.topics_required';
        }
        if (count($topics) > self::MAX_TOPICS) {
            $errors['topics'][] = 'policy_wizard.error.topics_too_many';
            $topics = array_slice($topics, 0, self::MAX_TOPICS);
        }

        $normalised = ['topics' => $topics];
        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }

    public function persist(WizardRun $run, array $input): void
    {
        parent::persist($run, $input);

        $topics = $input['topics'] ?? [];
        if (is_array($topics)) {
            $run->setTargetedTopics(array_values($topics));
        }
    }
}
