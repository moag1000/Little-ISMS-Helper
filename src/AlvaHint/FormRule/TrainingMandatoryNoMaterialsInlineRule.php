<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — Training form: mandatory training without
 * materials.
 *
 * Fires when the user marks a Training `mandatory = true` but neither
 * uploads `materialFiles` (file upload) nor enters anything in
 * `materials` (legacy free-text description). A mandatory training
 * with no reference material cannot be self-paced, audited, or
 * delivered consistently — ISO 27001 A.6.3 expects documented
 * awareness content.
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class TrainingMandatoryNoMaterialsInlineRule implements AlvaHintFormRuleInterface
{
    public function key(): string
    {
        return 'training.form.mandatory_without_materials';
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

        $materials = $payload['materials'] ?? null;
        $materialFiles = $payload['materialFiles'] ?? null;

        if (is_string($materials) && trim($materials) !== '') {
            return false;
        }
        if (is_array($materialFiles) && $materialFiles !== []) {
            return false;
        }
        if (is_string($materialFiles) && trim($materialFiles) !== '') {
            return false;
        }

        return true;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'materials',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.training_mandatory_without_materials.title',
            bodyTranslationKey: 'alva_hint.form.training_mandatory_without_materials.body',
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
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'true';
    }
}
