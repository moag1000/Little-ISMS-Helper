<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Bsi2004ExerciseLogRepository;

/**
 * Tier-2 hint: at least one BSI-200-4 Übungs-Logbuch improvement action is overdue.
 *
 * BSI-Standard 200-4 §9.4 requires improvement actions from exercises to be
 * tracked and completed on schedule. Overdue actions represent a compliance gap
 * and may be flagged in external audits.
 *
 * Trigger  : bcm_exercise_log_index, improvement_action past due_date + not completed
 * Module   : bcm
 * Role     : ROLE_MANAGER
 */
class Bsi2004ImprovementActionOverdueRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly Bsi2004ExerciseLogRepository $logRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.bsi_2004_improvement_action_overdue';
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
        return ['bcm_exercise_log_index', 'bcm_exercise_log_show'];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $overdueLogs = $this->logRepository->findImprovementActionsOverdue($tenant);

        if ($overdueLogs === []) {
            return null;
        }

        $overdueCount = 0;
        foreach ($overdueLogs as $log) {
            $overdueCount += count($log->getOverdueImprovementActions());
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'alva.improvement_action_overdue.title',
            bodyTranslationKey: 'alva.improvement_action_overdue.body',
            bodyTranslationParams: [
                '%count%'     => (string) $overdueCount,
                '%log_count%' => (string) count($overdueLogs),
            ],
            translationDomain: 'bsi_200_4_exercise',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'alva.improvement_action_overdue.action',
            actionRoute: 'bcm_exercise_log_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'worried',
            version: 1,
        );
    }
}
