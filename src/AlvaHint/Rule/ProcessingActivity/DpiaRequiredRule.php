<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\ProcessingActivity;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\ProcessingActivity;
use App\Entity\User;

/**
 * Cross-domain hint: GDPR Art. 35(3) — processing activities with
 * automated decision-making, large-scale special categories, or
 * self-flagged high risk require a DPIA. The PA entity already
 * encodes the predicate via requiresDPIA(); this rule turns it
 * into an actionable hint when no DPIA has been completed yet.
 */
final class DpiaRequiredRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'processing_activity.dpia_required';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['compliance'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof ProcessingActivity) {
            return false;
        }
        if ($entity->getDpiaCompleted()) {
            return false;
        }

        return $entity->requiresDPIA();
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof ProcessingActivity);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'processing_activity.dpia_required.title',
            bodyTranslationKey: 'processing_activity.dpia_required.body',
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'ProcessingActivity',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'processing_activity.dpia_required.action',
            actionRoute: 'app_dpia_new',
            actionRouteParams: [],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
