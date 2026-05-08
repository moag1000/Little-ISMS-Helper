<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Mode;

use App\Entity\WizardRun;

/**
 * Policy-Wizard W2-C — contract for mode-specific orchestration hooks.
 *
 * The default {@see \App\Service\PolicyWizard\WizardOrchestrator} drives
 * the full-mode 7-step flow. Sandbox + targeted-re-run modes piggy-back
 * on the orchestrator's step machinery but differ in three places:
 *
 *   1. {@see onStart} — bootstrap-side hooks (e.g. mark sandbox status,
 *      restrict the targeted flow to its 4-step subset, lift the
 *      finding reference onto the run).
 *   2. {@see onAfterStep} — per-step hooks (e.g. accumulate the
 *      would-be document content into `inputs.sandbox_preview`).
 *   3. {@see onComplete} — completion-side hooks (e.g. swallow the
 *      DocumentGenerator side-effects in sandbox mode and finalise
 *      the preview payload).
 *
 * Mode handlers are STATELESS — every method receives the run and
 * mutates it in-place. Persistence is the caller's responsibility.
 *
 * Architecture:
 * - §6.1 Mode selector
 * - §6.3 Targeted re-run (Mode 2)
 * - §6.4 Sandbox preview (Mode 3)
 */
interface ModeHandlerInterface
{
    /**
     * The {@see \App\Service\PolicyWizard\WizardStepKeys} mode constant
     * this handler reacts to.
     */
    public function mode(): string;

    /**
     * Hook fired immediately after the orchestrator has bootstrapped
     * a fresh run. Implementations may further mutate `WizardRun`
     * (e.g. set status='sandbox', store finding ref, jump the step
     * pointer past welcome to the targeted-pick step).
     */
    public function onStart(WizardRun $run): void;

    /**
     * Hook fired immediately after a step's `persist()` has run but
     * BEFORE the orchestrator advances `WizardRun.step`. Implementations
     * may snapshot the would-be content into `inputs.sandbox_preview`
     * etc.
     */
    public function onAfterStep(WizardRun $run, string $stepKey): void;

    /**
     * Generation-side hook. Runs INSIDE
     * {@see \App\Service\PolicyWizard\WizardOrchestrator::complete} as
     * a replacement for the DocumentGenerator call. Returns the
     * canonical generator-result shape so the orchestrator can apply
     * `document_ids` + `sandbox_preview` uniformly.
     *
     * Implementations that want the orchestrator to keep its default
     * generator path return null.
     *
     * @return array{document_ids: list<int>, sandbox_preview: array<string, mixed>|null}|null
     */
    public function generate(WizardRun $run): ?array;

    /**
     * Post-completion hook. Runs AFTER `document_ids` + `status` have
     * been applied. Pure mutation of the run; caller flushes.
     */
    public function onComplete(WizardRun $run): void;
}
