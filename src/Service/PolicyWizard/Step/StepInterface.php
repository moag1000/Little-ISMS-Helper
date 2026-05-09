<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;

/**
 * Policy-Wizard W2-A — contract for individual step evaluators.
 *
 * Each step in the 7-step flow (plus the targeted re-run sub-flow) is
 * implemented as a class behind this interface so the
 * `WizardOrchestrator` can dispatch input handling generically without
 * knowing the per-step shape. See
 * `docs/plans/policy-wizard/05-architecture.md` §5 + §6.
 *
 * Lifecycle per step:
 *   1. `defaults($run)` — pull pre-fills from existing tenant data so
 *      the user does not retype information already in the system.
 *   2. `validate($run, $input)` — return field-level errors plus the
 *      normalised input the orchestrator will persist.
 *   3. `persist($run, $input)` — write the normalised input into
 *      `WizardRun.inputs[$stepKey]` (the orchestrator does the actual
 *      flush; the step is responsible for the in-memory mutation).
 *   4. `isApplicable($run)` — short-circuit when the step does not
 *      apply in the current mode (e.g. targeted-re-run skips Steps 2-6).
 */
interface StepInterface
{
    /**
     * Canonical step key (one of {@see \App\Service\PolicyWizard\WizardStepKeys}).
     */
    public function key(): string;

    /**
     * Validate user input. The returned `errors` map is keyed by
     * input-field name and carries i18n-key strings (no UI-rendered
     * text — the controller / Twig template translates).
     *
     * `normalised_input` is what the orchestrator will store into
     * `WizardRun.inputs[$stepKey]`. It MUST be JSON-serialisable.
     *
     * @param array<string, mixed> $input
     * @return array{errors: array<string, list<string>>, normalised_input: array<string, mixed>}
     */
    public function validate(WizardRun $run, array $input): array;

    /**
     * Persist the normalised input into the run's `inputs` JSON column
     * (in-memory mutation only — the orchestrator flushes).
     *
     * @param array<string, mixed> $input Already validated + normalised.
     */
    public function persist(WizardRun $run, array $input): void;

    /**
     * Whether this step is applicable for the current run state.
     * Targeted re-runs skip Steps 2-6; sandbox runs include all steps
     * but produce no documents downstream.
     */
    public function isApplicable(WizardRun $run): bool;

    /**
     * Pre-fill values for the step's form, sourced from existing
     * tenant data (for now: from prior wizard runs of the same tenant
     * — full integration with VariableCollector lands in W3).
     *
     * @return array<string, mixed>
     */
    public function defaults(WizardRun $run): array;
}
