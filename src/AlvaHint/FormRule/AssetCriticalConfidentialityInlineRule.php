<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — Asset form: critical confidentiality value.
 *
 * Fires while the user is filling out the Asset form and selects the
 * maximum confidentiality value (5 on the 1-5 scale, "critical" / "very
 * high" in ISO 27001 A.5.12 classification language). Surfaces a heads-up
 * next to the `confidentialityValue` field reminding the user that
 * critical-confidentiality assets need stronger Owner-governance: MFA
 * pflicht (ISO 27001 A.5.17 + BSI ORP.4.A11) and an annual
 * re-verification cadence (A.5.15 Access-Review).
 *
 * Pre-save heads-up only (tier `warning`) — not a regulatory blocker.
 */
final class AssetCriticalConfidentialityInlineRule implements AlvaHintFormRuleInterface
{
    /**
     * Top of the 1-5 confidentiality scale = "critical / very high"
     * (canonical ISMS classification used across BSI/ISO 27001 mappings).
     */
    private const int CRITICAL_CONFIDENTIALITY = 5;

    public function key(): string
    {
        return 'asset.form.critical_confidentiality_needs_mfa';
    }

    public function entityType(): string
    {
        return 'asset';
    }

    public function requiredModules(): array
    {
        return ['assets'];
    }

    public function requiredRoles(): array
    {
        return [];
    }

    public function supports(array $payload, User $user): bool
    {
        $value = $this->intOrNull($payload['confidentialityValue'] ?? null);
        if ($value === null) {
            return false;
        }
        return $value >= self::CRITICAL_CONFIDENTIALITY;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'confidentialityValue',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.asset_critical_confidentiality.title',
            bodyTranslationKey: 'alva_hint.form.asset_critical_confidentiality.body',
            translationDomain: 'alva',
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
