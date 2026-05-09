<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\BCExercise;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\BCExerciseRepository;
use DateInterval;
use DateTimeImmutable;

/**
 * Policy-Wizard W7-D — BCM Exercise-Due hint (Tier-1 overdue / Tier-2 upcoming).
 *
 * Fires on a Tenant when at least one planned {@see BCExercise} is
 * either overdue (planned date in the past, status still `planned`)
 * or due within {@see self::UPCOMING_WINDOW_DAYS} days. Overdue
 * variants escalate to Tier-1 and become non-dismissible because
 * BCM exercise cadence is an audit-graded ISO 22301 Cl. 8.6 control;
 * upcoming exercises stay Tier-2 and dismissible (snooze).
 *
 * Spec: `04-bcm-input.md` §9.6 + `07-phase4-sprint-reconciliation.md`
 * lines 309-311 (W7-D Alva-Hints catalogue, BCM exercise-due item).
 */
final class BcmExerciseDueRule extends AbstractAlvaHintRule
{
    /** Bump when the rule's threshold or condition changes. */
    public const VERSION = 1;

    /** Days into the future an exercise must lie to count as "upcoming". */
    public const UPCOMING_WINDOW_DAYS = 14;

    public function __construct(
        private readonly BCExerciseRepository $exerciseRepository,
    ) {
    }

    public function key(): string
    {
        return 'policy_wizard.bcm_exercise_due';
    }

    public function priorityTier(): int
    {
        // Tier-1 when overdue, Tier-2 when only upcoming. Reported as
        // the higher (numerically lower) tier seen across candidates.
        return 2;
    }

    /**
     * @return array<int, string>
     */
    public function requiredModules(): array
    {
        return ['policy_wizard', 'bcm'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Tenant) {
            return false;
        }
        return $this->pickRelevantExercise($entity) !== null;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Tenant);

        $picked = $this->pickRelevantExercise($entity);
        $exercise = $picked['exercise'] ?? null;
        $isOverdue = $picked['overdue'] ?? false;

        $exerciseDate = $exercise?->getExerciseDate();
        $now = new DateTimeImmutable();
        $diffDays = 0;
        if ($exerciseDate !== null) {
            // Compare on the day boundary to keep the wording stable.
            $exerciseDay = DateTimeImmutable::createFromInterface($exerciseDate)
                ->setTime(0, 0, 0);
            $today = $now->setTime(0, 0, 0);
            $diff = $today->diff($exerciseDay);
            $diffDays = (int) $diff->days;
        }

        $titleKey = $isOverdue
            ? 'alva_hint.bcm_exercise_overdue.title'
            : 'alva_hint.bcm_exercise_due.title';
        $bodyKey = $isOverdue
            ? 'alva_hint.bcm_exercise_overdue.body'
            : 'alva_hint.bcm_exercise_due.body';
        $ctaKey = $isOverdue
            ? 'alva_hint.bcm_exercise_overdue.cta_label'
            : 'alva_hint.bcm_exercise_due.cta_label';

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: $titleKey,
            bodyTranslationKey: $bodyKey,
            bodyTranslationParams: [
                '%exercise_name%' => (string) ($exercise?->getName() ?? ''),
                '%exercise_type%' => (string) ($exercise?->getExerciseType() ?? ''),
                '%days%' => (string) $diffDays,
                '%window_days%' => (string) self::UPCOMING_WINDOW_DAYS,
            ],
            translationDomain: 'alva',
            variant: $isOverdue ? 'danger' : 'warning',
            priorityTier: $isOverdue ? 1 : 2,
            dismissible: !$isOverdue,
            entityType: 'BCExercise',
            entityId: (int) ($exercise?->getId() ?? 0),
            actionLabelTranslationKey: $ctaKey,
            actionRoute: 'app_bc_exercise_show',
            actionRouteParams: ['id' => (int) ($exercise?->getId() ?? 0)],
            requiredRoles: ['ROLE_ADMIN', 'ROLE_GROUP_BCM_OFFICER'],
            mood: 'thinking',
            version: self::VERSION,
        );
    }

    /**
     * Pick the most-relevant planned exercise for the tenant. Overdue
     * exercises win over upcoming ones (Tier-1 > Tier-2). Among
     * candidates of the same kind, the earliest date wins. Returns
     * `['exercise' => BCExercise, 'overdue' => bool]` or null.
     *
     * @return array{exercise: BCExercise, overdue: bool}|null
     */
    private function pickRelevantExercise(Tenant $tenant): ?array
    {
        $today = (new DateTimeImmutable())->setTime(0, 0, 0);
        $upcomingCutoff = $today->add(new DateInterval('P' . self::UPCOMING_WINDOW_DAYS . 'D'));

        /** @var list<BCExercise> $candidates */
        $candidates = $this->exerciseRepository->findBy([
            'tenant' => $tenant,
            'status' => 'planned',
        ]);

        $earliestOverdue = null;
        $earliestUpcoming = null;

        foreach ($candidates as $exercise) {
            $date = $exercise->getExerciseDate();
            if ($date === null) {
                continue;
            }
            $day = DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);

            if ($day < $today) {
                if ($earliestOverdue === null
                    || $day < DateTimeImmutable::createFromInterface($earliestOverdue->getExerciseDate())
                        ->setTime(0, 0, 0)
                ) {
                    $earliestOverdue = $exercise;
                }
                continue;
            }

            if ($day <= $upcomingCutoff) {
                if ($earliestUpcoming === null
                    || $day < DateTimeImmutable::createFromInterface($earliestUpcoming->getExerciseDate())
                        ->setTime(0, 0, 0)
                ) {
                    $earliestUpcoming = $exercise;
                }
            }
        }

        if ($earliestOverdue !== null) {
            return ['exercise' => $earliestOverdue, 'overdue' => true];
        }
        if ($earliestUpcoming !== null) {
            return ['exercise' => $earliestUpcoming, 'overdue' => false];
        }
        return null;
    }
}
