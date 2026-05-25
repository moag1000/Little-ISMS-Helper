<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\BCExercise;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\User;
use App\Enum\BCExerciseStatus;

/**
 * Tier-1 hint: ISO 22301 Cl. 8.5.3 — when an exercise's measured RTO/RPO
 * exceeds the plan target, the plan needs revision (or a documented
 * deviation justification). The classical Bake-Off question "and then?"
 * lives here. The hint fires on completed exercises whose actualRto/Rpo
 * is strictly worse than the worst plan-target across the tested plans.
 */
final class ActualExceedsPlanRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'bc_exercise.actual_exceeds_plan';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        return ['bcm'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof BCExercise) {
            return false;
        }

        if ($entity->getStatusEnum() !== BCExerciseStatus::Completed) {
            return false;
        }

        return $this->detectExceedance($entity) !== null;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof BCExercise);

        $kind = $this->detectExceedance($entity) ?? 'rto';

        return new AlvaHint(
            key: $this->key() . '.' . $kind,
            titleTranslationKey: 'bc_exercise.actual_exceeds_plan.' . $kind . '.title',
            bodyTranslationKey: 'bc_exercise.actual_exceeds_plan.' . $kind . '.body',
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 1,
            dismissible: true,
            entityType: 'BCExercise',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'bc_exercise.actual_exceeds_plan.action',
            actionRoute: 'app_bc_exercise_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'warning',
        );
    }

    /**
     * Returns 'rto' or 'rpo' if the actual value strictly exceeds at least
     * one tested plan's target, null otherwise.
     */
    private function detectExceedance(BCExercise $exercise): ?string
    {
        $actualRto = $exercise->getActualRtoAchieved();
        $actualRpo = $exercise->getActualRpoAchieved();

        $actualRtoFloat = $actualRto !== null ? (float) $actualRto : null;
        $actualRpoFloat = $actualRpo !== null ? (float) $actualRpo : null;

        foreach ($exercise->getTestedPlans() as $plan) {
            \assert($plan instanceof BusinessContinuityPlan);

            $planRto = $plan->getRto();
            if ($actualRtoFloat !== null && $planRto !== null && $actualRtoFloat > $planRto) {
                return 'rto';
            }

            $planRpo = $plan->getRpo();
            if ($actualRpoFloat !== null && $planRpo !== null && $actualRpoFloat > $planRpo) {
                return 'rpo';
            }
        }

        return null;
    }
}
