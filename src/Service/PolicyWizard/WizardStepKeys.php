<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

/**
 * Policy-Wizard W2-A — canonical step keys used by every step evaluator
 * and by `WizardRun.step` persistence.
 *
 * Step keys mirror the §6 flow in
 * `docs/plans/policy-wizard/05-architecture.md` plus the §6.3 targeted
 * re-run modus. Sandbox mode (Mode 3) reuses the default 7-step set
 * because only the persistence-side differs (no documents, no SoA
 * mutation), not the input collection.
 *
 * Constants are exposed as PHP class constants (rather than an enum)
 * because `WizardRun.step` is a plain `string` column for forward
 * compatibility with future per-tenant custom steps.
 */
final class WizardStepKeys
{
    // -- Default 7-step flow (Mode 1 / Mode 3) -------------------------------

    public const STEP_WELCOME = 'welcome';
    public const STEP_ORG_SCOPE = 'organisation_scope';
    public const STEP_ROLES = 'roles';
    public const STEP_RISK_CLASSIFICATION = 'risk_classification';
    public const STEP_OPERATIONAL_BASELINES = 'operational_baselines';
    public const STEP_LIFECYCLE = 'lifecycle';
    public const STEP_REVIEW_GENERATE = 'review_generate';

    // -- Targeted re-run flow (Mode 2) ---------------------------------------

    public const STEP_TARGETED_PICK = 'targeted_pick_topics';
    public const STEP_TARGETED_FINDING = 'targeted_finding_reference';
    public const STEP_TARGETED_DIFF = 'targeted_diff_preview';
    public const STEP_TARGETED_GENERATE = 'targeted_generate';

    // -- Run modes -----------------------------------------------------------

    public const MODE_FULL = 'full';
    public const MODE_TARGETED = 'targeted';
    public const MODE_SANDBOX = 'sandbox';

    // -- Run statuses --------------------------------------------------------

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SANDBOX = 'sandbox';

    /**
     * Default ordered flow used by full + sandbox modes.
     *
     * @return list<string>
     */
    public static function defaultFlow(): array
    {
        return [
            self::STEP_WELCOME,
            self::STEP_ORG_SCOPE,
            self::STEP_ROLES,
            self::STEP_RISK_CLASSIFICATION,
            self::STEP_OPERATIONAL_BASELINES,
            self::STEP_LIFECYCLE,
            self::STEP_REVIEW_GENERATE,
        ];
    }

    /**
     * Targeted re-run flow (Mode 2). Step 1 is shared with the default
     * flow (welcome) so users always pick a mode first.
     *
     * @return list<string>
     */
    public static function targetedFlow(): array
    {
        return [
            self::STEP_WELCOME,
            self::STEP_TARGETED_PICK,
            self::STEP_TARGETED_FINDING,
            self::STEP_TARGETED_DIFF,
            self::STEP_TARGETED_GENERATE,
        ];
    }
}
