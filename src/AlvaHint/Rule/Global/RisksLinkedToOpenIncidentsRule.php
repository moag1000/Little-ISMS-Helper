<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RiskIncidentLinkRepository;

/**
 * Tier-2 hint: one or more risks have been cross-linked to OPEN incidents
 * for more than 30 days, indicating ongoing risk pressure that should be
 * resolved so the risk register stays accurate.
 *
 * Trigger  : app_risk_index
 * Module   : risks
 * Role     : ROLE_MANAGER
 * Tier     : 2 (audit gap — open incident → unreviewed risk)
 *
 * Sprint 9B / F16 — 18th Alva-Hint criterion.
 */
final class RisksLinkedToOpenIncidentsRule extends AbstractGlobalAlvaHintRule
{
    private const int DAYS_THRESHOLD = 30;

    public function __construct(
        private readonly RiskIncidentLinkRepository $linkRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.risks_linked_to_open_incidents';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['risks'];
    }

    public function appliesToPages(): array
    {
        return ['app_risk_index'];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        // Single source of truth shared with the index `focus=incident_linked`
        // filter, so the hint deep-links to EXACTLY the risks it counts.
        $links = $this->linkRepository->findStaleLinksToOpenIncidents($tenant, self::DAYS_THRESHOLD);

        // Count DISTINCT risks — several stale links may point to one risk.
        $riskIds = [];
        foreach ($links as $link) {
            $rid = $link->getRisk()?->getId();
            if ($rid !== null) {
                $riskIds[$rid] = $rid;
            }
        }

        $staleCount = count($riskIds);
        if ($staleCount === 0) {
            return null;
        }

        // Deep-link to exactly what the hint counts: one → that risk,
        // several → the risk index pre-filtered to the same set.
        if ($staleCount === 1) {
            $route = 'app_risk_show';
            $params = ['id' => array_key_first($riskIds)];
        } else {
            $route = 'app_risk_index';
            $params = ['focus' => 'incident_linked'];
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.risks_linked_to_open_incidents.title',
            bodyTranslationKey: 'global.risks_linked_to_open_incidents.body',
            bodyTranslationParams: ['%count%' => (string) $staleCount],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.risks_linked_to_open_incidents.action',
            actionRoute: $route,
            actionRouteParams: $params,
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'concerned',
            version: 1,
        );
    }
}
