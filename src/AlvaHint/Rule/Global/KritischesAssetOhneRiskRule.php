<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tier-1 hint: Assets with high/sehr_hoch CIA protection need but no linked Risk.
 *
 * DSGVO Art. 32 + ISO 27001 Cl. 6.1.2 require that high-CIA assets feed
 * the risk assessment. Missing links = undocumented risk exposure.
 */
final class KritischesAssetOhneRiskRule extends AbstractGlobalAlvaHintRule
{
    private const int CIA_HIGH_THRESHOLD = 4;

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function key(): string
    {
        return 'global.kritisches_asset_ohne_risk';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['assets', 'risks'];
    }

    public function appliesToPages(): array
    {
        return [
            'asset_index',
            'risk_index',
            'dashboard_ciso',
            'dashboard_risk_manager',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        // Count high-CIA assets without any risk entry
        $count = (int) $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Asset a
             WHERE a.tenant = :tenant
             AND (a.confidentialityValue >= :t OR a.integrityValue >= :t OR a.availabilityValue >= :t)
             AND a.id NOT IN (
                 SELECT IDENTITY(r.asset) FROM App\Entity\Risk r
                 WHERE r.asset IS NOT NULL AND r.tenant = :tenant
             )',
        )
            ->setParameter('tenant', $tenant)
            ->setParameter('t', self::CIA_HIGH_THRESHOLD)
            ->getSingleScalarResult();

        if ($count <= 0) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.asset_ohne_risk.title',
            bodyTranslationKey: 'global.asset_ohne_risk.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.asset_ohne_risk.action',
            actionRoute: 'app_risk_new',
            actionRouteParams: [],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'warning',
        );
    }
}
