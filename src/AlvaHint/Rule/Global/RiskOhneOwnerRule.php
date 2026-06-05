<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RiskRepository;

/**
 * Tier-2 hint: Risks without an assigned owner.
 *
 * ISO 27001 A.5.4 / Cl. 6.1.2 require every risk to have a documented
 * accountable owner. Unowned risks cannot be tracked for treatment.
 */
final class RiskOhneOwnerRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.risk_ohne_owner';
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
        return [
            'risk_index',
            'dashboard_ciso',
            'dashboard_risk_manager',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        // Single source of truth shared with the index `focus=no_owner`
        // filter, so the hint deep-links to EXACTLY the risks it counts.
        $unowned = $this->riskRepository->findWithoutOwner($tenant);
        $count = count($unowned);

        if ($count <= 0) {
            return null;
        }

        // Deep-link to exactly what the hint counts: one → that risk,
        // several → the risk index pre-filtered to the same set.
        if ($count === 1) {
            $route = 'app_risk_show';
            $params = ['id' => $unowned[0]->getId() ?? 0];
        } else {
            $route = 'app_risk_index';
            $params = ['focus' => 'no_owner'];
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.risk_ohne_owner.title',
            bodyTranslationKey: 'global.risk_ohne_owner.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.risk_ohne_owner.action',
            actionRoute: $route,
            actionRouteParams: $params,
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
