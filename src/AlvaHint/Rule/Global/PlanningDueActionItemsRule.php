<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\ActionItem;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ActionItemRepository;
use DateTimeImmutable;

/**
 * Tier-2 nudge: surface open action items whose due date falls within the
 * next 14 days so planning managers can act before deadlines are missed.
 *
 * Trigger  : planning_index, open ActionItem count for tenant due in ≤ 14 days > 0
 * Module   : resource_planning
 * Role     : ROLE_USER (any planning user benefits)
 */
final class PlanningDueActionItemsRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly ActionItemRepository $actionItemRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.planning_due_action_items';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['resource_planning'];
    }

    public function appliesToPages(): array
    {
        return ['planning_index'];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $cutoff = new DateTimeImmutable('+14 days');

        $openItems = $this->actionItemRepository->findOpenByTenant($tenant);

        $dueItems = array_filter(
            $openItems,
            static fn (ActionItem $i): bool => $i->getDueDate() !== null && $i->getDueDate() <= $cutoff,
        );

        $count = count($dueItems);

        if ($count === 0) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.planning_due_action_items.title',
            bodyTranslationKey: 'global.planning_due_action_items.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.planning_due_action_items.action',
            actionRoute: 'app_planning_action_item_index',
            actionRouteParams: ['filter' => 'due'],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_USER'],
            mood: 'thinking',
            version: 1,
        );
    }
}
