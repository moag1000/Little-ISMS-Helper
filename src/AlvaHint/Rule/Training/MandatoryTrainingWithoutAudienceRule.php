<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Training;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Training;
use App\Entity\User;
use App\Repository\TrainingParticipationRepository;

/**
 * Sprint-2 P-7 Wave-2 trigger-3: Training.mandatory = true → Pflicht-Audience.
 *
 * ISO 27001 A.6.3 Awareness — when a training is flagged mandatory,
 * the organisation must be able to identify and prove which users are
 * required to take it. The
 * {@see App\EventListener\AutoReactionTrainingAssignListener} auto-
 * assigns mandatory trainings to NEW users on user-persist, but it
 * does NOT backfill the existing user base when a manager flips
 * `mandatory = true` on an already-active Training.
 *
 * This rule closes that gap: when a Training is mandatory but has
 * no TrainingParticipation rows yet, surface a Tier-2 info hint
 * with a quick bulk-picker so the manager can assign the existing
 * tenant population without re-typing each name.
 *
 * Not module-gated (Awareness is ISO 27001 base; every tenant has it).
 * Role-gated ROLE_MANAGER (assignment is a management decision).
 */
final class MandatoryTrainingWithoutAudienceRule extends AbstractAlvaHintRule
{
    public function __construct(
        private readonly TrainingParticipationRepository $participationRepository,
    ) {
    }

    public function key(): string
    {
        return 'training.mandatory_without_audience';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        // No module gate — Awareness is ISO 27001 base requirement.
        return [];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Training) {
            return false;
        }
        if (!$entity->isMandatory()) {
            return false;
        }

        // Any participation row means audience is at least partially staged
        // — the manager has started the assignment flow and the hint is
        // moot.
        $existing = $this->participationRepository->findOneBy(['training' => $entity]);

        return $existing === null;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Training);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'training.mandatory_audience.title',
            bodyTranslationKey: 'training.mandatory_audience.body',
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Training',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'training.mandatory_audience.action',
            actionRoute: 'app_training_audience_picker',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
            version: 1,
        );
    }
}
