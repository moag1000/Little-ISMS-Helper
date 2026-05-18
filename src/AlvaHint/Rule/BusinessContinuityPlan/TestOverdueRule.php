<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\BusinessContinuityPlan;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\BusinessContinuityPlan;
use App\Entity\User;
use App\Enum\BusinessContinuityPlanStatus;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Tier-2 hint: ISO 22301 Cl. 8.6 demands at least one BC test per
 * year. Active plans whose lastTested timestamp is null or older
 * than 12 months should trigger a follow-up exercise.
 */
final class TestOverdueRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'bc_plan.test_overdue';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['bcm'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof BusinessContinuityPlan) {
            return false;
        }
        if ($entity->getStatus() !== BusinessContinuityPlanStatus::Active->value) {
            return false;
        }

        $lastTested = $entity->getLastTested();
        if (!$lastTested instanceof DateTimeInterface) {
            return true;
        }

        $oneYearAgo = (new DateTimeImmutable())->modify('-1 year');

        return $lastTested < $oneYearAgo;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof BusinessContinuityPlan);
        $lastTested = $entity->getLastTested();
        $monthsSince = 0;
        if ($lastTested instanceof DateTimeInterface) {
            $diff = (new DateTimeImmutable())->diff($lastTested);
            $monthsSince = $diff->y * 12 + $diff->m;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'bc_plan.test_overdue.title',
            bodyTranslationKey: $lastTested instanceof DateTimeInterface
                ? 'bc_plan.test_overdue.body_with_last'
                : 'bc_plan.test_overdue.body_never',
            bodyTranslationParams: [
                '%months%' => (string) $monthsSince,
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'BusinessContinuityPlan',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'bc_plan.test_overdue.action',
            actionRoute: 'app_bc_plan_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            mood: 'thinking',
        );
    }
}
