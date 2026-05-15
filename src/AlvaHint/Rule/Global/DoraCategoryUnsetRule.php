<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\SupplierRepository;

/**
 * Tier-2 warning hint: DORA entity-level flags contradict tenant-level category.
 *
 * Fires when:
 *  - Tenant.doraEntityCategory === 'none' (not DORA-obligated)
 *  - BUT 1+ suppliers or assets are marked isDoraRelevant=true
 *
 * This contradiction indicates the admin probably:
 *  a) Forgot to set the tenant-level DORA category, OR
 *  b) Accidentally flagged entities on a non-DORA tenant.
 *
 * Suggests admin reviews and sets Tenant.doraEntityCategory.
 *
 * Trigger  : dashboard_ciso / admin pages
 * Module   : nis2_dora
 * Role     : ROLE_ADMIN
 * Tier     : 2 (warning, dismissible)
 *
 * Note: Entity-level isDoraRelevant flags are added by the
 * feat/dora-roi-scope-entity-flag branch. This rule returns null
 * gracefully when that column is not yet present in the database
 * (repository methods return 0 via try-catch fallback).
 */
final class DoraCategoryUnsetRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly SupplierRepository $supplierRepository,
        private readonly AssetRepository $assetRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.dora_category_unset';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['nis2_dora'];
    }

    public function appliesToPages(): array
    {
        return [
            'dashboard_ciso',
            'admin_tenant',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        // Only fire when tenant is NOT marked as DORA-obligated.
        // If already obligated, no contradiction exists.
        if ($tenant->isDoraObligated()) {
            return null;
        }

        // Check if any entity-level DORA flags exist despite tenant saying 'none'.
        $doraRelevantCount = $this->supplierRepository->countByTenantAndDoraRelevant($tenant)
            + $this->assetRepository->countByTenantAndDoraRelevant($tenant);

        if ($doraRelevantCount === 0) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.dora_category_unset.title',
            bodyTranslationKey: 'global.dora_category_unset.body',
            bodyTranslationParams: ['%count%' => (string) $doraRelevantCount],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.dora_category_unset.action',
            actionRoute: 'admin_tenant_compliance_settings_current',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_ADMIN'],
            mood: 'warning',
            version: 1,
        );
    }
}
