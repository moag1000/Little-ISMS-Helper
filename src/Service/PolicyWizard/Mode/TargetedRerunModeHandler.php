<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Mode;

use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\WizardRunRepository;
use App\Service\PolicyWizard\Step\TargetedPickTopicsStep;
use App\Service\PolicyWizard\WizardStepKeys;
use App\Service\TenantSettingResolver\TenantSettingResolver;

/**
 * Policy-Wizard W2-C — Mode 2 (Targeted re-run) handler.
 *
 * Restricts the wizard to the 4-step sub-flow defined in §6.3:
 *
 *   1. STEP_TARGETED_PICK     — up to 10 topics from the tenant's
 *                                existing approved documents.
 *   2. STEP_TARGETED_FINDING  — optional Audit-Finding ID that
 *                                triggered the re-run (P1 ISB).
 *   3. STEP_TARGETED_DIFF     — read-only delta preview computed via
 *                                {@see TenantSettingResolver}.
 *   4. STEP_TARGETED_GENERATE — re-generates ONLY the picked subset.
 *
 * The handler does NOT re-implement the step machinery — it leans on
 * the StepInterface implementations registered in the orchestrator and
 * simply provides:
 *
 *  - {@see onStart}: jumps the run pointer past Welcome to PICK so the
 *    UI never offers Steps 2-6 of the default flow.
 *  - {@see computeDiff}: read-only helper returning the delta map for
 *    the diff-preview step's UI.
 *  - {@see generate}: returns null so the orchestrator falls through to
 *    the DocumentGenerator. The generator inspects
 *    `WizardRun.targetedTopics` and re-renders only the picked subset.
 *
 * The handler keeps the welcome step visible (mode picker shared) but
 * emits a clear pointer override on start so the user lands on PICK
 * the moment the orchestrator hands control back.
 */
final class TargetedRerunModeHandler implements ModeHandlerInterface
{
    /** Architecture §6.3 hard cap. */
    public const int MAX_TOPICS = TargetedPickTopicsStep::MAX_TOPICS;

    /**
     * Setting keys whose effective values are compared during the
     * diff-preview step. The list mirrors the wizard's persisted
     * inputs so the UI can render a "your current value vs. the
     * approved baseline" table without per-key boilerplate.
     *
     * @var list<array{topic: string, key: string, label_t_key: string}>
     */
    private const DIFF_KEY_MAP = [
        ['topic' => 'risk_classification', 'key' => 'risk.appetite_tier', 'label_t_key' => 'policy_wizard.diff.risk.appetite_tier'],
        ['topic' => 'risk_classification', 'key' => 'policy.review_interval_months', 'label_t_key' => 'policy_wizard.diff.policy.review_interval_months'],
        ['topic' => 'operational_baselines', 'key' => 'backup.rpo_hours', 'label_t_key' => 'policy_wizard.diff.backup.rpo_hours'],
        ['topic' => 'lifecycle', 'key' => 'policy.review_interval_months', 'label_t_key' => 'policy_wizard.diff.lifecycle.review_interval_months'],
    ];

    public function __construct(
        private readonly WizardRunRepository $wizardRunRepository,
        private readonly TenantSettingResolver $settingResolver,
    ) {
    }

    public function mode(): string
    {
        return WizardStepKeys::MODE_TARGETED;
    }

    public function onStart(WizardRun $run): void
    {
        // The orchestrator's `start()` lands on STEP_WELCOME because
        // the welcome step is shared across all modes. For targeted
        // runs we want the user to skip straight to the PICK step
        // once mode + finding-ref have been recorded. We jump the
        // pointer here unconditionally — the orchestrator's
        // StepEvaluator will route every subsequent navigate() through
        // the targeted sub-flow anyway.
        $run->setMode(WizardStepKeys::MODE_TARGETED);
        $run->setStep(WizardStepKeys::STEP_TARGETED_PICK);

        // Ensure inputs bag exists so the diff-preview step always
        // has a slot to read from — saves an `is_array` guard
        // downstream.
        if ($run->getInputs() === null) {
            $run->setInputs([]);
        }
    }

    public function onAfterStep(WizardRun $run, string $stepKey): void
    {
        // Targeted mode is a thin wrapper; per-step persistence is
        // entirely handled by the StepInterface implementations. The
        // only thing we need to verify is that PICK never exceeds the
        // 10-topic cap (the step's `validate()` already guards this,
        // but we double-check on persist as a defence in depth: a
        // misbehaving controller could call `persist()` directly).
        if ($stepKey !== WizardStepKeys::STEP_TARGETED_PICK) {
            return;
        }

        $topics = $run->getTargetedTopics() ?? [];
        if (count($topics) > self::MAX_TOPICS) {
            $clamped = array_slice($topics, 0, self::MAX_TOPICS);
            $run->setTargetedTopics(array_values($clamped));
        }
    }

    /**
     * Compute the diff preview map for {@see WizardStepKeys::STEP_TARGETED_DIFF}.
     *
     * Compares each picked topic's current effective value (resolved
     * via {@see TenantSettingResolver} against the holding chain) with
     * the value stored in the most recent COMPLETED wizard run for
     * the same tenant — i.e. the approved baseline. Topics whose
     * value is unchanged are omitted from the diff.
     *
     * For W2 the "approved baseline" is read from the latest
     * completed `WizardRun.inputs`. W3 will swap this out for
     * `Document.substitutionVariables` once the GeneratedPolicyDocument
     * fields land.
     *
     * @return list<array{
     *   topic: string,
     *   key: string,
     *   label_t_key: string,
     *   current_value: mixed,
     *   approved_value: mixed,
     *   changed: bool,
     * }>
     */
    public function computeDiff(WizardRun $run): array
    {
        $tenant = $run->getTenant();
        if ($tenant === null) {
            return [];
        }

        $pickedTopics = $run->getTargetedTopics() ?? [];
        if ($pickedTopics === []) {
            return [];
        }

        $baseline = $this->resolveApprovedBaseline($tenant, $run);
        $diff = [];

        foreach (self::DIFF_KEY_MAP as $entry) {
            // Only emit a row if the user picked the corresponding
            // topic. Topic = step-name lower-snake (matches
            // PolicyTemplate.topic convention).
            if (!in_array($entry['topic'], $pickedTopics, true)) {
                continue;
            }

            $current = $this->settingResolver->resolveFor($tenant, $entry['key'])->value;
            $approved = $baseline[$entry['key']] ?? null;
            $changed = !$this->valuesEqual($current, $approved);

            $diff[] = [
                'topic' => $entry['topic'],
                'key' => $entry['key'],
                'label_t_key' => $entry['label_t_key'],
                'current_value' => $current,
                'approved_value' => $approved,
                'changed' => $changed,
            ];
        }

        return $diff;
    }

    /**
     * Validate a list of picked topics against the 10-topic cap. Used
     * by callers that want to surface the cap before the StepInterface
     * runs (e.g. controller pre-checks). Throws when the cap is
     * exceeded — matches the StepInterface's i18n-error-key contract.
     *
     * @param list<string> $topics
     */
    public function assertTopicsWithinCap(array $topics): void
    {
        if (count($topics) > self::MAX_TOPICS) {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException(sprintf(
                'Targeted re-run accepts at most %d topics, %d given.',
                self::MAX_TOPICS,
                count($topics),
            ));
        }
    }

    public function generate(WizardRun $run): ?array
    {
        // Targeted mode delegates document generation back to the
        // standard DocumentGenerator (W3 lands the real impl). The
        // generator inspects WizardRun.targetedTopics and only emits
        // the picked subset.
        return null;
    }

    public function onComplete(WizardRun $run): void
    {
        // No mutation required — the orchestrator already stamps
        // status='completed'. We keep the hook explicit for
        // symmetry with SandboxModeHandler.
    }

    /**
     * Pull the substitution-variable baseline from the most recent
     * completed `WizardRun` of the same tenant. Returns a flat
     * setting-key → value map.
     *
     * @return array<string, mixed>
     */
    private function resolveApprovedBaseline(Tenant $tenant, WizardRun $current): array
    {
        $candidates = $this->wizardRunRepository->findBy(
            criteria: [
                'tenant' => $tenant,
                'status' => WizardStepKeys::STATUS_COMPLETED,
            ],
            orderBy: ['completedAt' => 'DESC'],
            limit: 1,
        );
        $baseline = $candidates[0] ?? null;
        if ($baseline === null || $baseline === $current) {
            return [];
        }

        $bag = $baseline->getInputs() ?? [];
        $flat = [];
        foreach (self::DIFF_KEY_MAP as $entry) {
            // Translate (topic, setting_key) back into the wizard's
            // step+input_field nesting and lift the value, if any.
            $flat[$entry['key']] = $this->liftFromInputs($bag, $entry['topic'], $entry['key']);
        }
        return $flat;
    }

    /**
     * Inverse of {@see HierarchyOverrideValidator::SETTING_MAP} — pulls
     * the value the previous run stored for a given setting key out of
     * its `inputs` JSON. We do not have a generic step→key registry
     * yet (W3 will add one), so we hand-roll the lookups for the four
     * keys covered in DIFF_KEY_MAP.
     *
     * @param array<string, mixed> $inputs
     */
    private function liftFromInputs(array $inputs, string $topic, string $settingKey): mixed
    {
        $stepKey = match ($topic) {
            'risk_classification' => WizardStepKeys::STEP_RISK_CLASSIFICATION,
            'operational_baselines' => WizardStepKeys::STEP_OPERATIONAL_BASELINES,
            'lifecycle' => WizardStepKeys::STEP_LIFECYCLE,
            default => null,
        };
        if ($stepKey === null) {
            return null;
        }
        $slot = $inputs[$stepKey] ?? null;
        if (!is_array($slot)) {
            return null;
        }

        $field = match ($settingKey) {
            'risk.appetite_tier' => 'risk_appetite_tier',
            'policy.review_interval_months' => $topic === 'lifecycle' ? 'default_review_interval_months' : 'review_interval_months',
            'backup.rpo_hours' => 'backup_rpo_hours',
            default => null,
        };
        if ($field === null) {
            return null;
        }
        return $slot[$field] ?? null;
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return $a === $b;
    }
}
