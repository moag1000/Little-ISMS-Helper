<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\BCExercise;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\BCExercise;
use App\Entity\User;
use App\Enum\BCExerciseStatus;

/**
 * Tier-3 hint: ISO 22301 Cl. 8.6 — completed BC exercises without
 * any documented findings or areas-for-improvement are of limited
 * audit value. Auditors expect at least one observation per exercise.
 */
final class NoFindingsLoggedRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'bc_exercise.no_findings_logged';
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
        if (!$entity instanceof BCExercise) {
            return false;
        }

        if ($entity->getStatusEnum() !== BCExerciseStatus::Completed) {
            return false;
        }

        $findings = $entity->getFindings();
        $areasForImprovement = $entity->getAreasForImprovement();

        return ($findings === null || trim($findings) === '')
            && ($areasForImprovement === null || trim($areasForImprovement) === '');
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof BCExercise);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'bc_exercise.no_findings.title',
            bodyTranslationKey: 'bc_exercise.no_findings.body',
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'BCExercise',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'bc_exercise.no_findings.action',
            actionRoute: 'app_bc_exercise_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
