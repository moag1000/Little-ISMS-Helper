<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\IncidentRepository;

/**
 * Tier-1 hint: Open GDPR-related incidents older than 24h without
 * an authority notification recorded.
 *
 * DSGVO Art. 33 mandates supervisory authority notification within 72h.
 * An open high-severity incident touching personal data > 24h old is
 * a direct early-warning signal before the deadline is missed.
 */
final class OpenIncidentOhneSlaRule extends AbstractGlobalAlvaHintRule
{
    private const int SLA_THRESHOLD_HOURS = 24;

    public function __construct(
        private readonly IncidentRepository $incidentRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.open_incident_ohne_sla';
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
            'incident_index',
            'data_breach_index',
            'dashboard_ciso',
            'dashboard_compliance_manager',
            'dashboard_auditor',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        // Single source of truth shared with the index `focus=privacy_sla`
        // filter, so the hint deep-links to EXACTLY the incidents it counts.
        $open = $this->incidentRepository->findOpenPrivacyWithoutSla($tenant, self::SLA_THRESHOLD_HOURS);
        $count = count($open);

        if ($count <= 0) {
            return null;
        }

        // Deep-link to exactly what the hint counts: one → that incident,
        // several → the incident index pre-filtered to the same set.
        if ($count === 1) {
            $route = 'app_incident_show';
            $params = ['id' => $open[0]->getId() ?? 0];
        } else {
            $route = 'app_incident_index';
            $params = ['focus' => 'privacy_sla'];
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.open_incident_ohne_sla.title',
            bodyTranslationKey: 'global.open_incident_ohne_sla.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.open_incident_ohne_sla.action',
            actionRoute: $route,
            actionRouteParams: $params,
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'alert',
        );
    }
}
