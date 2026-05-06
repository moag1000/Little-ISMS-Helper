<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\CrisisTeam;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\CrisisTeam;
use App\Entity\User;

/**
 * Tier-3 hint: BSI 200-4 Kap. 4.2 / ISO 22301 Cl. 7.2 require regular
 * crisis-team training. The entity already exposes isTrainingOverdue()
 * which encodes the cadence; this rule just turns the truth value
 * into an Alva card and points at the BC-Exercise creator.
 */
final class TrainingOverdueRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'crisis_team.training_overdue';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['bcm'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof CrisisTeam) {
            return false;
        }

        return $entity->isTrainingOverdue();
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof CrisisTeam);
        $last = $entity->getLastTrainingAt();
        $months = 0;
        if ($last instanceof \DateTimeInterface) {
            $diff = (new \DateTimeImmutable())->diff($last);
            $months = $diff->y * 12 + $diff->m;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'crisis_team.training.title',
            bodyTranslationKey: $last instanceof \DateTimeInterface
                ? 'crisis_team.training.body_with_last'
                : 'crisis_team.training.body_never',
            bodyTranslationParams: [
                '%months%' => (string) $months,
            ],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'CrisisTeam',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'crisis_team.training.action',
            actionRoute: 'app_bc_exercise_new',
            actionRouteParams: [],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
