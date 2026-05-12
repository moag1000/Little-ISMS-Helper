<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tier-3 hint: Konzern subsidiary with significantly fewer approved documents
 * than its parent Konzern tenant — signals potential policy-drift.
 *
 * A parent Konzern with N approved documents and this subsidiary with
 * fewer than (N * 0.5) approved documents suggests the subsidiary has
 * not adopted Konzern policy baseline. ISB coordination recommended.
 *
 * Only fires for tenants that HAVE a parent (Konzern) tenant.
 */
final class KonzernSettingsDriftRule extends AbstractGlobalAlvaHintRule
{
    private const float DRIFT_THRESHOLD = 0.5;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function key(): string
    {
        return 'global.konzern_settings_drift';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return [];
    }

    public function appliesToPages(): array
    {
        return [
            'dashboard_ciso',
            'inbox',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        // Only relevant for subsidiary tenants (have a parent)
        $parentTenant = $tenant->getParent();
        if ($parentTenant === null) {
            return null;
        }

        // Count approved documents in parent vs this tenant
        $parentCount = (int) $this->em->createQuery(
            'SELECT COUNT(d.id) FROM App\Entity\Document d
             WHERE d.tenant = :tenant AND d.status = :status',
        )
            ->setParameter('tenant', $parentTenant)
            ->setParameter('status', 'approved')
            ->getSingleScalarResult();

        if ($parentCount === 0) {
            return null;
        }

        $tenantCount = (int) $this->em->createQuery(
            'SELECT COUNT(d.id) FROM App\Entity\Document d
             WHERE d.tenant = :tenant AND d.status = :status',
        )
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'approved')
            ->getSingleScalarResult();

        // Drift: subsidiary has fewer than threshold ratio of parent's documents
        if ($tenantCount >= (int) round($parentCount * self::DRIFT_THRESHOLD)) {
            return null;
        }

        $driftCount = $parentCount - $tenantCount;

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.konzern_settings_drift.title',
            bodyTranslationKey: 'global.konzern_settings_drift.body',
            bodyTranslationParams: [
                '%konzern_name%' => $parentTenant->getName() ?? 'Konzern',
                '%count%' => (string) $driftCount,
            ],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.konzern_settings_drift.action',
            actionRoute: 'app_document_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_CISO'],
            mood: 'thinking',
        );
    }
}
