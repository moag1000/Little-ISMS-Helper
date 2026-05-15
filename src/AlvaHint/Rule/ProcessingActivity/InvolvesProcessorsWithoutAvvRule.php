<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\ProcessingActivity;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\ProcessingActivity;
use App\Entity\User;

/**
 * Sprint-2 P-7 Wave-2 trigger-2: ProcessingActivity.involvesProcessors = true
 * → AVV (Auftragsverarbeitungs-Vertrag) supplier picker.
 *
 * GDPR Art. 28(1)/(3) — when a processor (Auftragsverarbeiter) is
 * involved, the controller MUST have a written contract identifying
 * the processor. Today the PA carries only a free-text JSON blob
 * `$processors` and a boolean flag — there is no structured FK
 * into the Supplier register, so AVV-tracking is impossible.
 *
 * P-7 Wave-2 introduces ProcessingActivity::$processorSuppliers
 * (Many2Many → Supplier) and this rule fires when:
 *   - involvesProcessors = true AND
 *   - processorSuppliers collection is empty
 *
 * The legacy `processors` JSON blob is NOT counted as resolution —
 * it must be migrated to the FK relation for the AVV register to be
 * audit-ready (P0-15).
 *
 * Module-gated: `privacy` (Art. 28 GDPR).
 * Role-gated: ROLE_DPO (the DPO maintains the AVV register).
 */
final class InvolvesProcessorsWithoutAvvRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'processing_activity.involves_processors_without_avv';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['privacy'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof ProcessingActivity) {
            return false;
        }
        if (!$entity->getInvolvesProcessors()) {
            return false;
        }

        return $entity->getProcessorSuppliers()->isEmpty();
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof ProcessingActivity);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'processing_activity.avv_required.title',
            bodyTranslationKey: 'processing_activity.avv_required.body',
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'ProcessingActivity',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'processing_activity.avv_required.action',
            actionRoute: 'app_processing_activity_avv_picker',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_DPO'],
            mood: 'thinking',
            version: 1,
        );
    }
}
