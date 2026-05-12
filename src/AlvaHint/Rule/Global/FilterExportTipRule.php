<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;

/**
 * Tier-3 tip: entity list-view has > 50 results — suggest the filtered export feature.
 *
 * When a tenant has more than 50 risks OR 50 assets, the filtered-list export
 * (F19) saves significant time over manual copy-paste. This tip surfaces on
 * list-view pages so the user sees it in context.
 *
 * Trigger  : app_risk_index, app_asset_index — > 50 items for tenant
 * Module   : core
 * Role     : ROLE_MANAGER
 * Dismiss  : filter_export_tip@v1
 */
final class FilterExportTipRule extends AbstractGlobalAlvaHintRule
{
    private const int THRESHOLD = 50;

    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly AssetRepository $assetRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.filter_export_tip';
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
            'app_risk_index',
            'app_asset_index',
            'app_supplier_index',
            'app_control_index',
            'app_incident_index',
            'app_audit_finding_index',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $riskCount = count($this->riskRepository->findByTenant($tenant));
        $assetCount = count($this->assetRepository->findByTenant($tenant));

        $maxCount = max($riskCount, $assetCount);

        if ($maxCount <= self::THRESHOLD) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.filter_export_tip.title',
            bodyTranslationKey: 'global.filter_export_tip.body',
            bodyTranslationParams: ['%count%' => (string) $maxCount],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.filter_export_tip.action',
            actionRoute: 'app_filtered_export_entity',
            actionRouteParams: ['entityType' => 'risk', 'format' => 'xlsx'],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
            version: 1,
        );
    }
}
