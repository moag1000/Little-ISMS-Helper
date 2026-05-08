<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use RuntimeException;

/**
 * Thrown by {@see WizardOrchestrator::processStep} when a step's
 * `validate()` returned non-empty errors. The controller catches this
 * and re-renders the form with the per-field i18n-key violations.
 */
final class StepValidationException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors field-name => list-of-i18n-keys
     */
    public function __construct(
        public readonly string $stepKey,
        public readonly array $errors,
    ) {
        parent::__construct(sprintf(
            'Step "%s" failed validation with %d field error(s).',
            $stepKey,
            count($errors),
        ));
    }
}
