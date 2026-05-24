<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — Training form: mandatory training without
 * defined audience.
 *
 * Fires when the user marks a Training `mandatory = true` but leaves
 * both `targetAudience` (free-text role/group descriptor) and
 * `participantUsers` (explicit user collection) empty. Mandatory
 * training without an audience cannot be auto-assigned or tracked for
 * coverage — ISO 27001 A.6.3 requires "appropriate awareness, education
 * and training" *for those whose work could affect information
 * security* (i.e. with a defined audience).
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class TrainingMandatoryNoAudienceInlineRule implements AlvaHintFormRuleInterface
{
    public function key(): string
    {
        return 'training.form.mandatory_without_audience';
    }

    public function entityType(): string
    {
        return 'training';
    }

    public function requiredModules(): array
    {
        return ['training'];
    }

    public function requiredRoles(): array
    {
        return [];
    }

    public function supports(array $payload, User $user): bool
    {
        if (!$this->isMandatory($payload)) {
            return false;
        }

        $targetAudience = $payload['targetAudience'] ?? null;
        $participants = $payload['participants'] ?? null;
        $participantUsers = $payload['participantUsers'] ?? null;

        if (is_string($targetAudience) && trim($targetAudience) !== '') {
            return false;
        }
        if (is_string($participants) && trim($participants) !== '') {
            return false;
        }
        if (is_array($participantUsers) && $participantUsers !== []) {
            return false;
        }
        if (is_string($participantUsers) && trim($participantUsers) !== '') {
            return false;
        }

        return true;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'targetAudience',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.training_mandatory_without_audience.title',
            bodyTranslationKey: 'alva_hint.form.training_mandatory_without_audience.body',
            translationDomain: 'alva',
            mood: 'warning',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isMandatory(array $payload): bool
    {
        $value = $payload['mandatory'] ?? null;
        // Training FormType renders `mandatory` as a ChoiceType with raw
        // bool-keyed choices; HTML-serialized values come through as the
        // strings "1" / "0".
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'true';
    }
}
