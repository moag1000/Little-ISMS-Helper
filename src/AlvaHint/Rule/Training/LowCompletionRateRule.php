<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Training;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\Repository\TrainingParticipationRepository;

/**
 * Tier-2 hint: ISO 27001 A.6.3 / Cl. 7.3 — mandatory trainings with
 * a structured participant list where fewer than THRESHOLD percent
 * have status=completed indicate a security-awareness gap. External
 * auditors look for demonstrable completion evidence, not just
 * assignment records.
 */
final class LowCompletionRateRule extends AbstractAlvaHintRule
{
    private const float THRESHOLD = 50.0;
    private const int MIN_PARTICIPANTS = 3;

    public function __construct(
        private readonly TrainingParticipationRepository $participationRepository,
    ) {
    }

    public function key(): string
    {
        return 'training.low_completion_rate';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['training'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Training) {
            return false;
        }

        if (!$entity->isMandatory()) {
            return false;
        }

        $participations = $this->participationRepository->findByTraining($entity);
        $total = count($participations);

        if ($total < self::MIN_PARTICIPANTS) {
            return false;
        }

        $completed = array_filter(
            $participations,
            static fn (TrainingParticipation $p): bool => $p->getStatus() === TrainingParticipation::STATUS_COMPLETED,
        );

        $rate = (count($completed) / $total) * 100;

        return $rate < self::THRESHOLD;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Training);

        $participations = $this->participationRepository->findByTraining($entity);
        $total = count($participations);
        $completed = array_filter(
            $participations,
            static fn (TrainingParticipation $p): bool => $p->getStatus() === TrainingParticipation::STATUS_COMPLETED,
        );
        $rate = $total > 0 ? (int) round((count($completed) / $total) * 100) : 0;

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'training.low_completion_rate.title',
            bodyTranslationKey: 'training.low_completion_rate.body',
            bodyTranslationParams: [
                '%rate%' => $rate,
                '%completed%' => count($completed),
                '%total%' => $total,
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Training',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'training.low_completion_rate.action',
            actionRoute: 'app_training_show',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
