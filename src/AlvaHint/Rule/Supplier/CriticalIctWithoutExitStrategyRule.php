<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Supplier;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Supplier;
use App\Entity\User;

/**
 * Tier-2 hint: DORA Art. 28(8) requires a documented exit strategy
 * for ICT-critical third-party providers. Trigger fires when the
 * supplier is flagged ictCriticality=critical but neither
 * hasExitStrategy nor exitStrategyDocument is set.
 */
final class CriticalIctWithoutExitStrategyRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'supplier.dora_exit_strategy_missing';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['suppliers'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Supplier) {
            return false;
        }
        if ($entity->getIctCriticality() !== 'critical') {
            return false;
        }
        if ($entity->hasExitStrategy()) {
            return false;
        }

        return $entity->getExitStrategyDocument() === null;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Supplier);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'supplier.exit_strategy.title',
            bodyTranslationKey: 'supplier.exit_strategy.body',
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Supplier',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'supplier.exit_strategy.action',
            actionRoute: 'app_supplier_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
