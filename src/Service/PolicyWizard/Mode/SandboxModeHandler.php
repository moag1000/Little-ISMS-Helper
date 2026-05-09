<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Mode;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;
use DateTimeImmutable;

/**
 * Policy-Wizard W2-C — Mode 3 (Sandbox preview) handler.
 *
 * Wraps the standard 7-step flow but redirects every persistence-side
 * effect into a no-op. Concretely:
 *
 *  - On {@see onStart} the run's status is forced to
 *    {@see WizardStepKeys::STATUS_SANDBOX} (architecture §6.4).
 *  - On {@see onAfterStep} we accumulate the per-step normalised inputs
 *    into `WizardRun.inputs.sandbox_preview.steps[stepKey]` so the UI
 *    can review the would-be document content in one place.
 *  - On {@see generate} we synthesise an empty `document_ids` list and
 *    a non-null `sandbox_preview` payload — the orchestrator never
 *    invokes the real DocumentGenerator.
 *  - On {@see onComplete} we stamp the preview's finalised-at marker
 *    so audit logs can distinguish "abandoned half-way" vs "ran to
 *    completion in preview mode".
 *
 * Auto-purge: sandbox runs older than 7 days are deleted by the
 * `app:policy-wizard:purge-sandboxes` console command (cron-friendly).
 *
 * @see \App\Command\PurgeSandboxWizardRunsCommand
 */
final class SandboxModeHandler implements ModeHandlerInterface
{
    /**
     * Architecture §6.4: sandbox runs are auto-purged after 7 days.
     */
    public const int PURGE_AFTER_DAYS = 7;

    /** Slot inside `WizardRun.inputs` that holds the preview payload. */
    public const string PREVIEW_SLOT = 'sandbox_preview';

    public function mode(): string
    {
        return WizardStepKeys::MODE_SANDBOX;
    }

    public function onStart(WizardRun $run): void
    {
        // Sandbox status is set on the run no matter what the
        // orchestrator picked — the user might have clicked "sandbox"
        // mid-flow, in which case the orchestrator's status would
        // still be 'in_progress'.
        $run->setStatus(WizardStepKeys::STATUS_SANDBOX);
        $run->setMode(WizardStepKeys::MODE_SANDBOX);

        $bag = $run->getInputs() ?? [];
        if (!isset($bag[self::PREVIEW_SLOT]) || !is_array($bag[self::PREVIEW_SLOT])) {
            $bag[self::PREVIEW_SLOT] = $this->emptyPreview();
            $run->setInputs($bag);
        }
    }

    public function onAfterStep(WizardRun $run, string $stepKey): void
    {
        // Snapshot whatever the StepInterface persisted under
        // `inputs[$stepKey]` into `inputs.sandbox_preview.steps`.
        $bag = $run->getInputs() ?? [];
        $stepSlot = $bag[$stepKey] ?? [];
        if (!is_array($stepSlot)) {
            $stepSlot = [];
        }

        $preview = $bag[self::PREVIEW_SLOT] ?? $this->emptyPreview();
        if (!is_array($preview)) {
            $preview = $this->emptyPreview();
        }
        if (!isset($preview['steps']) || !is_array($preview['steps'])) {
            $preview['steps'] = [];
        }
        $preview['steps'][$stepKey] = $stepSlot;
        $preview['updated_at'] = (new DateTimeImmutable())->format(DATE_ATOM);

        $bag[self::PREVIEW_SLOT] = $preview;
        $run->setInputs($bag);
    }

    /**
     * Sandbox runs MUST NOT touch the DocumentGenerator. We synthesise
     * the canonical generator-result shape so the orchestrator can
     * apply the empty document_ids list uniformly, and we return the
     * accumulated preview payload as `sandbox_preview`.
     *
     * @return array{document_ids: list<int>, sandbox_preview: array<string, mixed>|null}
     */
    public function generate(WizardRun $run): ?array
    {
        $bag = $run->getInputs() ?? [];
        $preview = $bag[self::PREVIEW_SLOT] ?? $this->emptyPreview();
        if (!is_array($preview)) {
            $preview = $this->emptyPreview();
        }
        // Annotate the preview with a generated-at timestamp so the
        // UI can show "Generated (preview only) at ..." consistently.
        $preview['generated_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        $preview['mode'] = WizardStepKeys::MODE_SANDBOX;

        $bag[self::PREVIEW_SLOT] = $preview;
        $run->setInputs($bag);

        return [
            'document_ids' => [],
            'sandbox_preview' => $preview,
        ];
    }

    public function onComplete(WizardRun $run): void
    {
        // Lock the status back to 'sandbox' in case the orchestrator
        // tried to flip it to 'completed' for a non-sandbox path.
        $run->setStatus(WizardStepKeys::STATUS_SANDBOX);
        $run->setGeneratedDocumentIds([]);
    }

    /**
     * @return array{steps: array<string, array<string, mixed>>, generated_at: null, mode: string}
     */
    private function emptyPreview(): array
    {
        return [
            'steps' => [],
            'generated_at' => null,
            'mode' => WizardStepKeys::MODE_SANDBOX,
        ];
    }
}
