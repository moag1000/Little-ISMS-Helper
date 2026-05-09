<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Step 6 — Lifecycle & Cadence.
 *
 * Default review interval (≤ 24 months — hard cap echoed from Step 4),
 * per-policy overrides (advanced/optional), per-document approver
 * designation and the auto-publish flag (FORCED FALSE — architecture
 * §6 Step 6 + §11.5 "no auto-publish, ever").
 */
final class LifecycleStep extends AbstractStep
{
    public const REVIEW_INTERVAL_HARD_CAP_MONTHS = 24;

    public function key(): string
    {
        return WizardStepKeys::STEP_LIFECYCLE;
    }

    public function validate(WizardRun $run, array $input): array
    {
        $errors = [];

        // Default review interval.
        $defaultInterval = $input['default_review_interval_months'] ?? 12;
        if (!is_numeric($defaultInterval)) {
            $errors['default_review_interval_months'][] = 'policy_wizard.error.review_interval_invalid';
            $defaultInterval = 12;
        } else {
            $defaultInterval = (int) $defaultInterval;
            if ($defaultInterval < 1) {
                $errors['default_review_interval_months'][] = 'policy_wizard.error.review_interval_invalid';
                $defaultInterval = 12;
            } elseif ($defaultInterval > self::REVIEW_INTERVAL_HARD_CAP_MONTHS) {
                $errors['default_review_interval_months'][] = 'policy_wizard.error.review_interval_too_long';
                $defaultInterval = self::REVIEW_INTERVAL_HARD_CAP_MONTHS;
            }
        }

        // Per-policy overrides (optional, collapsible).
        $perPolicy = $input['per_policy_overrides'] ?? [];
        if (!is_array($perPolicy)) {
            $errors['per_policy_overrides'][] = 'policy_wizard.error.per_policy_overrides_invalid';
            $perPolicy = [];
        }
        $normalisedOverrides = [];
        foreach ($perPolicy as $templateKey => $months) {
            if (!is_string($templateKey) || $templateKey === '') {
                continue;
            }
            if (!is_numeric($months)) {
                $errors['per_policy_overrides'][] = 'policy_wizard.error.review_interval_invalid';
                continue;
            }
            $months = (int) $months;
            if ($months < 1) {
                continue;
            }
            if ($months > self::REVIEW_INTERVAL_HARD_CAP_MONTHS) {
                $errors['per_policy_overrides'][] = 'policy_wizard.error.review_interval_too_long';
                $months = self::REVIEW_INTERVAL_HARD_CAP_MONTHS;
            }
            $normalisedOverrides[$templateKey] = $months;
        }

        // Per-document approver mapping (template_key => user_id INT).
        // Form-Audit (May 2026): plain role strings ('ROLE_CISO') are no
        // longer accepted — the picker must yield concrete user-ids so
        // ApprovalKickoffService can route the WorkflowInstance to a
        // real assignee. Strings get rejected here to surface the
        // mismatch instead of silently dropping.
        $approvers = $input['approver_per_template'] ?? [];
        if (!is_array($approvers)) {
            $errors['approver_per_template'][] = 'policy_wizard.error.approver_per_template_invalid';
            $approvers = [];
        }
        $normalisedApprovers = [];
        foreach ($approvers as $templateKey => $userId) {
            if (!is_string($templateKey) || $templateKey === '') {
                continue;
            }
            // Reject role-string inputs explicitly so the form-audit
            // mismatch (string ROLE_CISO vs int 42) is surfaced.
            if (is_string($userId) && !is_numeric($userId) && $userId !== '') {
                $errors['approver_per_template'][] = 'policy_wizard.error.approver_must_be_user_id';
                continue;
            }
            if (!is_numeric($userId)) {
                continue;
            }
            $userId = (int) $userId;
            if ($userId <= 0) {
                continue;
            }
            $normalisedApprovers[$templateKey] = $userId;
        }

        // Default tenant-wide fallback approver (user-id INT). When set
        // it is used for any policy template that has no per-template
        // override.
        $defaultApproverUserId = $input['default_approver_user_id'] ?? null;
        if (is_string($defaultApproverUserId) && !is_numeric($defaultApproverUserId) && $defaultApproverUserId !== '') {
            $errors['default_approver_user_id'][] = 'policy_wizard.error.approver_must_be_user_id';
            $defaultApproverUserId = null;
        } elseif (is_numeric($defaultApproverUserId)) {
            $defaultApproverUserId = (int) $defaultApproverUserId;
            if ($defaultApproverUserId <= 0) {
                $defaultApproverUserId = null;
            }
        } else {
            $defaultApproverUserId = null;
        }

        // Auto-publish — forced false. Architecture §6 Step 6 / §11.5.
        $autoPublish = false;

        // Alva-Hint trigger on next-due review (T-30d). Stored as a
        // boolean indicating "schedule the hint" — defaults true.
        $alvaHintOnReview = (bool) ($input['alva_hint_on_review'] ?? true);

        $normalised = [
            'default_review_interval_months' => $defaultInterval,
            'per_policy_overrides' => $normalisedOverrides,
            'approver_per_template' => $normalisedApprovers,
            'default_approver_user_id' => $defaultApproverUserId,
            'auto_publish' => $autoPublish,
            'alva_hint_on_review' => $alvaHintOnReview,
        ];

        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }
}
