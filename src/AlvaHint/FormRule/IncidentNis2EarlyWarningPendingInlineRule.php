<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — Incident form: NIS2 24h early-warning pending.
 *
 * Fires when the tenant has the NIS2/DORA module on, the user enters
 * an Incident with severity `high` or `critical`, and the
 * `earlyWarningReportedAt` field is still empty. NIS2 Art. 23 (4)(a)
 * obliges essential / important entities to deliver an early-warning
 * notification to the competent authority within 24 hours of awareness
 * — the form is the natural place to surface that obligation BEFORE
 * the clock has been silently running for hours.
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class IncidentNis2EarlyWarningPendingInlineRule implements AlvaHintFormRuleInterface
{
    private const array HIGH_SEVERITIES = ['high', 'critical'];

    public function key(): string
    {
        return 'incident.form.nis2_early_warning_pending';
    }

    public function entityType(): string
    {
        return 'incident';
    }

    public function requiredModules(): array
    {
        return ['incidents', 'nis2_dora'];
    }

    public function requiredRoles(): array
    {
        return [];
    }

    public function supports(array $payload, User $user): bool
    {
        $severity = $payload['severity'] ?? null;
        if (!is_string($severity) || !in_array($severity, self::HIGH_SEVERITIES, true)) {
            return false;
        }

        // Only fire once the user actually exposed the field — keeps the
        // hint silent on the new-form before any NIS2 sub-section is
        // rendered.
        if (!array_key_exists('earlyWarningReportedAt', $payload)) {
            return false;
        }

        $value = $payload['earlyWarningReportedAt'];
        if (is_string($value) && trim($value) !== '') {
            return false;
        }

        return true;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'earlyWarningReportedAt',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.incident_nis2_early_warning_pending.title',
            bodyTranslationKey: 'alva_hint.form.incident_nis2_early_warning_pending.body',
            translationDomain: 'alva',
            mood: 'warning',
        );
    }
}
