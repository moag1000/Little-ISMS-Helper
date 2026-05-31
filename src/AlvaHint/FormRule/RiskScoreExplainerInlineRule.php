<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * Onboarding info-hint — Form-Step-Inline-Hint (P-19).
 *
 * A junior ISB filling the Risk form enters a probability and an impact but
 * frequently does not realise the tool derives a single risk SCORE from their
 * product, nor that the register is later prioritised by that score. The
 * persona's recurring question is literally "what do I put here / what does
 * this mean?".
 *
 * This rule fires the moment BOTH probability and impact carry a value and the
 * resulting score is BELOW the critical cutoff (>= 20 is already covered by
 * {@see RiskCriticalSeverityInlineRule}, anchored on the same `impact` field —
 * the two are mutually exclusive so they never stack). It surfaces a short,
 * jargon-light explanation of the score formula with an ISO 27005 reference.
 *
 * Tier `info` — purely explanatory, never a blocker. This is the reference
 * implementation for the info-tier onboarding-hint pattern (all 17 prior form
 * rules are `warning` heads-ups).
 */
final class RiskScoreExplainerInlineRule implements AlvaHintFormRuleInterface
{
    /**
     * Critical cutoff on the standard 5x5 matrix — value mirrored from
     * {@see RiskCriticalSeverityInlineRule::CRITICAL_THRESHOLD}. At or above
     * this score the critical warning takes over, so the explainer stands
     * down to avoid a double hint on the `impact` field.
     */
    private const int CRITICAL_THRESHOLD = 20;

    public function key(): string
    {
        return 'risk.form.score_explainer';
    }

    public function entityType(): string
    {
        return 'risk';
    }

    public function requiredModules(): array
    {
        return ['risks'];
    }

    public function requiredRoles(): array
    {
        return [];
    }

    public function supports(array $payload, User $user): bool
    {
        $probability = $this->intOrNull($payload['probability'] ?? null);
        $impact = $this->intOrNull($payload['impact'] ?? null);

        if ($probability === null || $impact === null) {
            return false;
        }
        if ($probability < 1 || $impact < 1) {
            return false;
        }

        // Critical territory is owned by RiskCriticalSeverityInlineRule.
        return ($probability * $impact) < self::CRITICAL_THRESHOLD;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        $probability = $this->intOrNull($payload['probability'] ?? null) ?? 0;
        $impact = $this->intOrNull($payload['impact'] ?? null) ?? 0;

        return new AlvaFormHint(
            key: $this->key(),
            field: 'impact',
            tier: 'info',
            titleTranslationKey: 'alva_hint.form.risk_score_explainer.title',
            bodyTranslationKey: 'alva_hint.form.risk_score_explainer.body',
            translationDomain: 'alva',
            bodyParams: [
                '%probability%' => $probability,
                '%impact%' => $impact,
                '%score%' => $probability * $impact,
            ],
            mood: 'teaching',
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
