<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — Risk form: residual score exceeds initial.
 *
 * Fires when the user enters a residual (probability x impact) product
 * STRICTLY greater than the initial (probability x impact) product.
 * Residual risk is, by definition, the risk REMAINING after treatment —
 * it cannot increase from treatment unless the user has confused the
 * residual/initial axes. ISO 27005 Cl. 8.4 ("Risk treatment") defines
 * residual as "the risk remaining after risk treatment" — i.e. always
 * <= initial.
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class RiskResidualExceedsInitialInlineRule implements AlvaHintFormRuleInterface
{
    public function key(): string
    {
        return 'risk.form.residual_exceeds_initial';
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
        $initialProbability = $this->intOrNull($payload['probability'] ?? null);
        $initialImpact = $this->intOrNull($payload['impact'] ?? null);
        $residualProbability = $this->intOrNull($payload['residualProbability'] ?? null);
        $residualImpact = $this->intOrNull($payload['residualImpact'] ?? null);

        if ($initialProbability === null || $initialImpact === null) {
            return false;
        }
        if ($residualProbability === null || $residualImpact === null) {
            return false;
        }
        if ($initialProbability < 1 || $initialImpact < 1
            || $residualProbability < 1 || $residualImpact < 1) {
            return false;
        }

        $initialScore = $initialProbability * $initialImpact;
        $residualScore = $residualProbability * $residualImpact;

        return $residualScore > $initialScore;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        $initialScore = ($this->intOrNull($payload['probability'] ?? null) ?? 0)
            * ($this->intOrNull($payload['impact'] ?? null) ?? 0);
        $residualScore = ($this->intOrNull($payload['residualProbability'] ?? null) ?? 0)
            * ($this->intOrNull($payload['residualImpact'] ?? null) ?? 0);

        return new AlvaFormHint(
            key: $this->key(),
            field: 'residualImpact',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.risk_residual_exceeds_initial.title',
            bodyTranslationKey: 'alva_hint.form.risk_residual_exceeds_initial.body',
            translationDomain: 'alva',
            bodyParams: [
                '%initial%' => $initialScore,
                '%residual%' => $residualScore,
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
