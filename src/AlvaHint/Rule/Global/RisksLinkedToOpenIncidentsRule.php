<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RiskIncidentLinkRepository;
use DateTimeImmutable;

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
        $threshold = new DateTimeImmutable(sprintf('-%d days', self::DAYS_THRESHOLD));
        $links     = $this->linkRepository->findLinksToOpenIncidents($tenant);

        $staleCount = 0;
        foreach ($links as $link) {
            if ($link->getLinkedAt() < $threshold) {
                ++$staleCount;
            }
        }

        if ($staleCount === 0) {
            return null;
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
            actionRoute: 'app_risk_index',
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'concerned',
            version: 1,
        );
    }
}
