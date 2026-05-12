<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DataBreachRepository;

/**
 * Tier-1 hint: Data breaches without authority notification recorded.
 *
 * DSGVO Art. 33 requires supervisory authority notification within 72h
 * of becoming aware of a breach. Breaches without authorityNotificationAt
 * set are unresolved regulatory obligations.
 */
final class MissingDataBreachProcedureRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly DataBreachRepository $dataBreachRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.missing_data_breach_procedure';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        return ['privacy'];
    }

    public function appliesToPages(): array
    {
        return [
            'data_breach_index',
            'dashboard_ciso',
            'dashboard_compliance_manager',
            'inbox',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $overdue = $this->dataBreachRepository->findAuthorityNotificationOverdue($tenant);

        if ($overdue === []) {
            return null;
        }

        $count = count($overdue);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.missing_data_breach_procedure.title',
            bodyTranslationKey: 'global.missing_data_breach_procedure.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.missing_data_breach_procedure.action',
            actionRoute: 'app_data_breach_index',
            actionRouteParams: ['filter' => 'overdue'],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'alert',
        );
    }
}
