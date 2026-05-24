<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — Document form: too-long review interval.
 *
 * Fires when the user sets `reviewIntervalMonths` above 24 months (two
 * years). ISO 27001 A.5.32 ("Independent review of information
 * security") and the published industry baseline are an annual review
 * cadence — extending past 24 months is the threshold where external
 * auditors will start asking for the risk-based justification.
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class DocumentReviewIntervalTooLongInlineRule implements AlvaHintFormRuleInterface
{
    /**
     * Above this number of months, surface the heads-up. 24 months chosen
     * as a soft threshold — anything <= 24 is acceptable with management
     * sign-off; > 24 needs an explicit audit rationale.
     */
    private const int MAX_RECOMMENDED_MONTHS = 24;

    public function key(): string
    {
        return 'document.form.review_interval_too_long';
    }

    public function entityType(): string
    {
        return 'document';
    }

    public function requiredModules(): array
    {
        return [];
    }

    public function requiredRoles(): array
    {
        return [];
    }

    public function supports(array $payload, User $user): bool
    {
        $months = $this->intOrNull($payload['reviewIntervalMonths'] ?? null);
        if ($months === null) {
            return false;
        }
        if ($months <= 0) {
            return false;
        }
        return $months > self::MAX_RECOMMENDED_MONTHS;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        $months = $this->intOrNull($payload['reviewIntervalMonths'] ?? null) ?? 0;

        return new AlvaFormHint(
            key: $this->key(),
            field: 'reviewIntervalMonths',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.document_review_interval_too_long.title',
            bodyTranslationKey: 'alva_hint.form.document_review_interval_too_long.body',
            translationDomain: 'alva',
            bodyParams: [
                '%months%' => $months,
            ],
            mood: 'warning',
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }
}
