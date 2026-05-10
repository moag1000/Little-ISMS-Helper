<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tier-2 hint: BCM module active but no BC exercise completed in the last 12 months.
 *
 * ISO 22301 Cl. 8.5 requires regular testing of BCP effectiveness.
 * No exercise in 12 months = audit non-conformity.
 */
final class MissingBcExerciseRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function key(): string
    {
        return 'global.missing_bc_exercise';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['bcm'];
    }

    public function appliesToPages(): array
    {
        return [
            'bc_exercise_index',
            'bcm_index',
            'business_continuity_plan_index',
            'dashboard_ciso',
            'inbox',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $threshold = new DateTimeImmutable('-12 months');

        // Check if any BC exercise was completed in the last 12 months
        $count = (int) $this->em->createQuery(
            'SELECT COUNT(e.id) FROM App\Entity\BCExercise e
             WHERE e.tenant = :tenant
             AND e.status = :status
             AND e.exerciseDate >= :threshold',
        )
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'completed')
            ->setParameter('threshold', $threshold)
            ->getSingleScalarResult();

        if ($count > 0) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.missing_bc_exercise.title',
            bodyTranslationKey: 'global.missing_bc_exercise.body',
            bodyTranslationParams: [],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.missing_bc_exercise.action',
            actionRoute: 'app_bc_exercise_index',
            actionRouteParams: [],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
