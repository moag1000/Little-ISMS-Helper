<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — Asset form: end-of-life status.
 *
 * Fires when the user moves the Asset to `retired` or `disposed` while
 * editing. Reminds the user that secure-disposal evidence is required
 * (ISO 27001 A.7.14 + BSI CON.6, "secure disposal of equipment containing
 * storage media") and that a Replacement-Plan / lessons-learned link in
 * an upstream BusinessProcess should be considered if the Asset
 * supported a critical process.
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class AssetRetiredStatusInlineRule implements AlvaHintFormRuleInterface
{
    /**
     * End-of-life status values per Asset.php Assert\Choice — these are the
     * two states that imply the asset is no longer in production use and
     * therefore require secure-disposal evidence per ISO 27001 A.7.14.
     */
    private const array END_OF_LIFE_STATUSES = ['retired', 'disposed'];

    public function key(): string
    {
        return 'asset.form.retired_needs_disposal_evidence';
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
        $status = $payload['status'] ?? null;
        if (!is_string($status) || $status === '') {
            return false;
        }
        return in_array($status, self::END_OF_LIFE_STATUSES, true);
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'status',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.asset_retired_status.title',
            bodyTranslationKey: 'alva_hint.form.asset_retired_status.body',
            translationDomain: 'alva',
            mood: 'warning',
        );
    }
}
