<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Policy-Wizard W2-A — shared scaffolding for step evaluators.
 *
 * Provides the common `persist()` implementation (write into the
 * `WizardRun.inputs[$stepKey]` slot) plus the default `isApplicable()`
 * rule (always-on for full + sandbox; skip for targeted-re-run except
 * the welcome step which is shared across all modes).
 *
 * Subclasses override `validate()` / `defaults()` and may override
 * `isApplicable()` for step-specific gating.
 */
abstract class AbstractStep implements StepInterface
{
    /**
     * @inheritDoc
     */
    public function persist(WizardRun $run, array $input): void
    {
        $bag = $run->getInputs() ?? [];
        $bag[$this->key()] = $input;
        $run->setInputs($bag);
    }

    /**
     * Default applicability: full + sandbox modes include every default
     * step; targeted-re-run only includes the welcome step. Subclasses
     * representing targeted-only steps override this.
     */
    public function isApplicable(WizardRun $run): bool
    {
        $mode = $run->getMode();
        if ($mode === WizardStepKeys::MODE_TARGETED) {
            return $this->key() === WizardStepKeys::STEP_WELCOME;
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function defaults(WizardRun $run): array
    {
        $existing = $run->getInputs()[$this->key()] ?? [];
        return is_array($existing) ? $existing : [];
    }

    /**
     * Helper: read the persisted slot for a given step out of the run's
     * input bag. Returns an empty array when the step has not run yet.
     *
     * @return array<string, mixed>
     */
    protected function readSlot(WizardRun $run, string $stepKey): array
    {
        $bag = $run->getInputs() ?? [];
        $slot = $bag[$stepKey] ?? [];
        return is_array($slot) ? $slot : [];
    }
}
