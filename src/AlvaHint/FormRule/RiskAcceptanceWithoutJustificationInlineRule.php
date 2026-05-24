<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — Risk form: acceptance without justification.
 *
 * Fires when the user picks `accept` as the treatment strategy but
 * leaves `acceptanceJustification` blank. ISO 27001 Cl. 8.3 + Cl. 6.1.3
 * f require the risk acceptance decision to be "made by the risk
 * owners" with a documented rationale — auditors specifically look for
 * the written justification when the residual risk crosses the
 * appetite threshold. Surfacing this BEFORE save reduces the audit
 * finding "risk accepted without justification" (one of the most-cited
 * ISO 27001 NCs).
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class RiskAcceptanceWithoutJustificationInlineRule implements AlvaHintFormRuleInterface
{
    /**
     * Mirrors {@see \App\Enum\TreatmentStrategy::Accept->value}. Copied
     * as a literal so the rule stays unit-testable without booting the
     * Enum class autoloader path.
     */
    private const string ACCEPT_STRATEGY = 'accept';

    public function key(): string
    {
        return 'risk.form.acceptance_without_justification';
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
        $strategy = $payload['treatmentStrategy'] ?? null;
        if ($strategy !== self::ACCEPT_STRATEGY) {
            return false;
        }

        $justification = $payload['acceptanceJustification'] ?? null;
        if (is_string($justification) && trim($justification) !== '') {
            return false;
        }

        return true;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'acceptanceJustification',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.risk_acceptance_without_justification.title',
            bodyTranslationKey: 'alva_hint.form.risk_acceptance_without_justification.body',
            translationDomain: 'alva',
            mood: 'warning',
        );
    }
}
