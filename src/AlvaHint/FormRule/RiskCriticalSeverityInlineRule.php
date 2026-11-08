<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;
use App\Risk\RiskMatrixThresholds;

/**
 * reference rule — Form-Step-Inline-Hint.
 *
 * Fires while the user is filling out the Risk form when the entered
 * (probability x impact) product reaches "critical" territory
 * (>= 20 on the 5x5 matrix — the conventional ISO 27005 cutoff for
 * "critical / treat immediately"). Surfaces an inline notice next to the
 * `impact` field reminding the user that critical-risk treatment plans
 * normally need C-level / board approval before they are accepted
 * (ISO 27001 Cl. 5.3 + 6.1.3 f — Risk-Owner with sufficient authority).
 *
 * Tier `warning` — pre-save heads-up, not a regulatory blocker.
 */
final class RiskCriticalSeverityInlineRule implements AlvaHintFormRuleInterface
{
    public function key(): string
    {
        return 'risk.form.critical_severity_needs_board_approval';
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

        return ($probability * $impact) >= RiskMatrixThresholds::criticalMin($user->getTenant());
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        $probability = $this->intOrNull($payload['probability'] ?? null) ?? 0;
        $impact = $this->intOrNull($payload['impact'] ?? null) ?? 0;

        return new AlvaFormHint(
            key: $this->key(),
            field: 'impact',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.risk_critical_severity.title',
            bodyTranslationKey: 'alva_hint.form.risk_critical_severity.body',
            translationDomain: 'alva',
            bodyParams: [
                '%score%' => $probability * $impact,
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
