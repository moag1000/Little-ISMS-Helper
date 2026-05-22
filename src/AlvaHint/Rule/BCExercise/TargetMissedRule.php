<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\BCExercise;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\User;

// Junior-ISB-Audit-2026-05-22 K-07: ISO 22301 Cl. 8.5.3 + 9.1.1 Lessons-Learned loop

/**
 * Tier-2 hint: ISO 22301 Cl. 8.5.3 + Cl. 9.1.1 — when an exercise's
 * actualRtoAchieved exceeds the planned RTO of a tested BC plan, the
 * exercise has revealed a planning gap. The Lessons-Learned loop
 * (ISO 22301 Cl. 9.1.1) and BSI 200-4 ÜP-9 both require result analysis
 * and plan-correction follow-up. Without this hint the user receives no
 * signal that the BCMS PDCA cycle needs closing.
 *
 * The rule fires for the first tested plan (by collection order) whose
 * planned RTO is exceeded by the achieved RTO. CTA links to that plan's
 * edit page so the user can adjust the RTO target or strengthen the
 * recovery strategy.
 */
final class TargetMissedRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'bc_exercise.target_missed';
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
        if (!$entity instanceof BCExercise) {
            return false;
        }

        return $this->findMissedPlan($entity) !== null;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof BCExercise);

        $missed = $this->findMissedPlan($entity);
        // appliesTo() guarantees a missed plan exists; defensive fallback to
        // keep build() total even if invoked out of contract.
        $planId = 0;
        $planTitle = '';
        $planRto = 0;
        $actualRto = (float) ($entity->getActualRtoAchieved() ?? '0');

        if ($missed !== null) {
            [$plan, $planRto, $actualRto] = $missed;
            $planId = $plan->getId() ?? 0;
            $planTitle = (string) $plan->getName();
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'bc_exercise.target_missed.title',
            bodyTranslationKey: 'bc_exercise.target_missed.body',
            bodyTranslationParams: [
                '%planRto%' => $planRto,
                '%actualRto%' => $this->formatHours($actualRto),
                '%planTitle%' => $planTitle,
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'BCExercise',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'bc_exercise.target_missed.action',
            actionRoute: 'app_bc_plan_edit',
            actionRouteParams: ['id' => $planId],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }

    /**
     * Find the first tested plan whose planned RTO is exceeded by the
     * achieved actualRtoAchieved. Returns [plan, planRto, actualRto] or
     * null when no gap exists (or guards fail).
     *
     * @return array{0: BusinessContinuityPlan, 1: int, 2: float}|null
     */
    private function findMissedPlan(BCExercise $exercise): ?array
    {
        $actualRaw = $exercise->getActualRtoAchieved();
        if ($actualRaw === null || $actualRaw === '') {
            return null;
        }

        $actualRto = (float) $actualRaw;
        if ($actualRto <= 0.0) {
            return null;
        }

        $plans = $exercise->getTestedPlans();
        if ($plans->isEmpty()) {
            return null;
        }

        foreach ($plans as $plan) {
            if (!$plan instanceof BusinessContinuityPlan) {
                continue;
            }
            $planRto = $plan->getRto();
            if ($planRto === null || $planRto <= 0) {
                continue;
            }
            if ($actualRto > (float) $planRto) {
                return [$plan, $planRto, $actualRto];
            }
        }

        return null;
    }

    /**
     * Format a decimal RTO for display — drop trailing .00 for whole hours.
     */
    private function formatHours(float $hours): string
    {
        if (\abs($hours - \floor($hours)) < 0.005) {
            return (string) (int) $hours;
        }
        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }
}
