<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\CorrectiveAction;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — CorrectiveAction form: effectiveness
 * review overdue.
 *
 * Fires when the user is editing a CorrectiveAction whose
 * `effectivenessReviewDate` lies in the past while the `status` is
 * still pre-verification (anything other than `verified`,
 * `verified_effective`, `verified_ineffective`). ISO 27001 Cl. 10.1 d
 * ("review the effectiveness of any corrective action taken") makes
 * effectiveness verification an audit-critical milestone — a CAPA
 * whose review window has elapsed without verification is a textbook
 * audit Major-NC.
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class CorrectiveActionEffectivenessReviewOverdueInlineRule implements AlvaHintFormRuleInterface
{
    /**
     * Verified statuses that close the effectiveness loop. Mirrors
     * {@see CorrectiveAction::STATUS_VERIFIED}, ::STATUS_VERIFIED_EFFECTIVE,
     * and ::STATUS_VERIFIED_INEFFECTIVE.
     */
    private const array VERIFIED_STATUSES = [
        CorrectiveAction::STATUS_VERIFIED,
        CorrectiveAction::STATUS_VERIFIED_EFFECTIVE,
        CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE,
    ];

    public function key(): string
    {
        return 'corrective_action.form.effectiveness_review_overdue';
    }

    public function entityType(): string
    {
        return 'corrective_action';
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
        $status = $payload['status'] ?? null;
        if (!is_string($status) || $status === '') {
            return false;
        }
        if (in_array($status, self::VERIFIED_STATUSES, true)) {
            return false;
        }

        if (!array_key_exists('effectivenessReviewDate', $payload)) {
            return false;
        }
        $raw = $payload['effectivenessReviewDate'];
        if ($raw === null || $raw === '' || $raw === false) {
            return false;
        }

        $date = $this->parseDate($raw);
        if ($date === null) {
            return false;
        }

        return $date < new \DateTimeImmutable('today');
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'effectivenessReviewDate',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.corrective_action_effectiveness_review_overdue.title',
            bodyTranslationKey: 'alva_hint.form.corrective_action_effectiveness_review_overdue.body',
            translationDomain: 'alva',
            mood: 'warning',
        );
    }

    private function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($raw);
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
