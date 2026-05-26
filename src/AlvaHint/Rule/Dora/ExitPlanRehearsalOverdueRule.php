<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Dora;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\DoraExitPlan;
use App\Entity\User;

/**
 * Tier-2 hint: DORA Art. 28(8) implicitly requires the exit strategy
 * to be tested. ESA guidelines treat 12 months without a rehearsal as
 * a material audit gap. Trigger fires when the linked DoraExitPlan was
 * never tested or last rehearsed more than 12 months ago.
 */
final class ExitPlanRehearsalOverdueRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'dora_exit_plan.rehearsal_overdue';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['nis2_dora'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof DoraExitPlan) {
            return false;
        }

        return $entity->isRehearsalOverdue();
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof DoraExitPlan);

        $supplier = $entity->getSupplier();
        $supplierName = $supplier?->getName() ?? '—';

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'dora_exit_plan.rehearsal_overdue.title',
            bodyTranslationKey: $entity->getTestedAt() === null
                ? 'dora_exit_plan.rehearsal_overdue.body_never'
                : 'dora_exit_plan.rehearsal_overdue.body_overdue',
            bodyTranslationParams: ['%supplier%' => $supplierName],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'DoraExitPlan',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'dora_exit_plan.rehearsal_overdue.action',
            actionRoute: 'app_dora_exit_plan_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
