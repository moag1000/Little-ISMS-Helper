<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Notification\SlaDeadlineMonitorRepository;

/**
 * Tier-1 hint: one or more SLA deadlines have been missed.
 *
 * A missed SLA deadline is a regulatory event with direct legal exposure:
 *  - GDPR Art. 83 — up to €10M / 2% group turnover for late Art. 33 notification
 *  - DORA Art. 45 — competent authority sanctions for late ICT-incident reporting
 *  - NIS2 Art. 36 — member-state authority sanctions for late reporting
 *
 * This hint is Tier-1 (regulatory) and therefore NEVER dismissible.
 * It re-evaluates on every page load until all missed deadlines are resolved.
 */
final class MissedSlaDeadlineRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly SlaDeadlineMonitorRepository $monitorRepository,
    ) {}

    public function key(): string
    {
        return 'global.missed_sla_deadline';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        // No specific module gate — any tenant can have missed SLAs.
        // The specific modules (privacy, nis2_dora) gate the entities that
        // spawn the monitors; the hint itself has no additional gate.
        return [];
    }

    public function appliesToPages(): array
    {
        return [
            'dashboard_ciso',
            'dashboard_compliance_manager',
            'dashboard_dpo',
            'dashboard_auditor',
            'admin_notification_rule_index',
            'data_breach_index',
            'incident_index',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $count = $this->monitorRepository->countMissedForTenant($tenant);

        if ($count <= 0) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.missed_sla_deadline.title',
            bodyTranslationKey: 'global.missed_sla_deadline.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.missed_sla_deadline.action',
            actionRoute: 'admin_notification_rule_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'alert',
            version: 1,
        );
    }
}
