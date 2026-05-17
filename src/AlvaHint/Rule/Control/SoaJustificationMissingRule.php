<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Control;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Control;
use App\Entity\User;

/**
 * Tier-2 audit-gap hint: ISO 27001 Cl. 6.1.3 d / 8.3 b — every control
 * marked as "not applicable" in the Statement of Applicability MUST
 * carry a written justification. A missing justification is a textbook
 * Major Non-Conformity at external certification audit.
 *
 * Closes the help-vs-code gap that the Junior-ISB audit flagged as P0-01:
 * the form help-text says "mandatory" but the underlying field was
 * `required: false`. The server-side validator (ControlType::
 * validateJustificationWhenNotApplicable) blocks the actual save; this
 * hint surfaces existing legacy rows for retroactive cleanup.
 */
final class SoaJustificationMissingRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'control.soa_justification_missing';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['controls'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Control) {
            return false;
        }
        // Only fire for explicitly non-applicable controls.
        if ($entity->getApplicable() !== false) {
            return false;
        }

        return trim((string) $entity->getJustification()) === '';
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Control);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'control.soa_justification_missing.title',
            bodyTranslationKey: 'control.soa_justification_missing.body',
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: false,
            entityType: 'Control',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'control.soa_justification_missing.action',
            actionRoute: 'app_soa_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'warning',
        );
    }
}
