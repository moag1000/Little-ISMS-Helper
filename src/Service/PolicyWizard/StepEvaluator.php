<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\Step\StepInterface;

/**
 * Policy-Wizard W2-A — flow controller.
 *
 * Given a `WizardRun`, returns the next step key based on:
 * 1. The run's `mode` (full / targeted / sandbox).
 * 2. The current `step`.
 * 3. Each step's `isApplicable($run)` rule (so e.g. targeted runs
 *    skip the default Steps 2-6, and W4-C Step 0 Bestandsaufnahme
 *    only applies for brownfield tenants — greenfield tenants flow
 *    straight to STEP_WELCOME).
 *
 * Default-flow transition contract (W4-C):
 *   STEP_BESTANDSAUFNAHME → STEP_WELCOME → STEP_ORG_SCOPE → … → STEP_REVIEW_GENERATE
 *
 * The evaluator does NOT mutate the run — it only computes which
 * step the orchestrator should advance to next. Returns null when
 * the flow is complete (the orchestrator then dispatches generation).
 */
final class StepEvaluator
{
    /** @var array<string, StepInterface> step-key indexed map */
    private array $stepIndex = [];

    /**
     * @param iterable<StepInterface> $steps Autowired by Symfony — every
     *                                       service implementing StepInterface
     *                                       is auto-tagged.
     */
    public function __construct(iterable $steps)
    {
        foreach ($steps as $step) {
            $this->stepIndex[$step->key()] = $step;
        }
    }

    /**
     * Returns the canonical flow for the run's mode.
     *
     * @return list<string>
     */
    public function flowFor(WizardRun $run): array
    {
        $mode = $run->getMode();
        return match ($mode) {
            WizardStepKeys::MODE_TARGETED => WizardStepKeys::targetedFlow(),
            // sandbox + full + anything else → default flow
            default => WizardStepKeys::defaultFlow(),
        };
    }

    /**
     * Compute the next step key after the current one, honouring each
     * step's `isApplicable($run)` rule. Returns null when no further
     * step applies.
     */
    public function nextStepFor(WizardRun $run): ?string
    {
        $flow = $this->flowFor($run);
        $current = $run->getStep();

        $idx = array_search($current, $flow, true);
        if ($idx === false) {
            // Run started outside the canonical flow — fall through to
            // the first applicable step.
            $idx = -1;
        }

        for ($i = $idx + 1, $n = count($flow); $i < $n; $i++) {
            $candidateKey = $flow[$i];
            $step = $this->stepIndex[$candidateKey] ?? null;
            if ($step === null) {
                continue;
            }
            if ($step->isApplicable($run)) {
                return $candidateKey;
            }
        }
        return null;
    }

    /**
     * Returns the first applicable step in the run's flow — useful when
     * a fresh run needs an initial pointer. Defaults to the WELCOME step
     * (always applicable) but stays defensive.
     */
    public function firstStepFor(WizardRun $run): ?string
    {
        foreach ($this->flowFor($run) as $key) {
            $step = $this->stepIndex[$key] ?? null;
            if ($step === null) {
                continue;
            }
            if ($step->isApplicable($run)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Look up the StepInterface implementation for a given key. Throws
     * when the key is unknown — the orchestrator catches and treats
     * unknown steps as a 4xx user error.
     */
    public function getStep(string $stepKey): StepInterface
    {
        if (!isset($this->stepIndex[$stepKey])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown wizard step: %s. Known steps: %s',
                $stepKey,
                implode(', ', array_keys($this->stepIndex)),
            ));
        }
        return $this->stepIndex[$stepKey];
    }

    /**
     * Whether the given step key is the terminal step in the run's
     * mode-specific flow.
     */
    public function isTerminalStep(WizardRun $run, string $stepKey): bool
    {
        $flow = $this->flowFor($run);
        if ($flow === []) {
            return false;
        }
        return end($flow) === $stepKey;
    }
}
