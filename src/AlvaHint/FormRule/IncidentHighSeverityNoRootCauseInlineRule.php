<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — Incident form: high-severity without root cause.
 *
 * Fires while the user is editing an Incident with severity `high` or
 * `critical` whose status is being moved into `resolved` or `closed`
 * while the `rootCause` text field is still empty. ISO 27001 Cl. 10.1
 * "Continual improvement" reads with Cl. 10.2 "Nonconformity & corrective
 * action" — closing a major-severity incident without a documented root
 * cause is the textbook audit observation for "ineffective corrective
 * action".
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class IncidentHighSeverityNoRootCauseInlineRule implements AlvaHintFormRuleInterface
{
    /**
     * Severity values that warrant a documented root cause. Mirrors the
     * IncidentSeverity enum's `high` + `critical` cases.
     */
    private const array HIGH_SEVERITIES = ['high', 'critical'];

    /**
     * Closing statuses that imply the investigation is wrapping up.
     * Mirrors IncidentStatus enum.
     */
    private const array CLOSING_STATUSES = ['resolved', 'closed'];

    public function key(): string
    {
        return 'incident.form.high_severity_no_root_cause';
    }

    public function entityType(): string
    {
        return 'incident';
    }

    public function requiredModules(): array
    {
        return ['incidents'];
    }

    public function requiredRoles(): array
    {
        return [];
    }

    public function supports(array $payload, User $user): bool
    {
        $severity = $payload['severity'] ?? null;
        $status = $payload['status'] ?? null;
        $rootCause = $payload['rootCause'] ?? null;

        if (!is_string($severity) || !in_array($severity, self::HIGH_SEVERITIES, true)) {
            return false;
        }
        if (!is_string($status) || !in_array($status, self::CLOSING_STATUSES, true)) {
            return false;
        }
        // rootCause empty/whitespace → fire. A space-only string is still
        // empty for ISMS purposes.
        if (is_string($rootCause) && trim($rootCause) !== '') {
            return false;
        }

        return true;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        $severity = is_string($payload['severity'] ?? null) ? $payload['severity'] : '';
        $status = is_string($payload['status'] ?? null) ? $payload['status'] : '';

        return new AlvaFormHint(
            key: $this->key(),
            field: 'rootCause',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.incident_high_severity_no_root_cause.title',
            bodyTranslationKey: 'alva_hint.form.incident_high_severity_no_root_cause.body',
            translationDomain: 'alva',
            bodyParams: [
                '%severity%' => $severity,
                '%status%' => $status,
            ],
            mood: 'warning',
        );
    }
}
