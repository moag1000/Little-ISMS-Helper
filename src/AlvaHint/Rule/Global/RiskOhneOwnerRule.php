<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tier-2 hint: Risks without an assigned owner.
 *
 * ISO 27001 A.5.4 / Cl. 6.1.2 require every risk to have a documented
 * accountable owner. Unowned risks cannot be tracked for treatment.
 */
final class RiskOhneOwnerRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly EntityManagerInterface $em,
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
        $count = (int) $this->em->createQuery(
            'SELECT COUNT(r.id) FROM App\Entity\Risk r
             WHERE r.tenant = :tenant
             AND r.riskOwner IS NULL
            ',
        )
            ->setParameter('tenant', $tenant)
            ->getSingleScalarResult();

        if ($count <= 0) {
            return null;
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
            actionRoute: 'app_risk_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
