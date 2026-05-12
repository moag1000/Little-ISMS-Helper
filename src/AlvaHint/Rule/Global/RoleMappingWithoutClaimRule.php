<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\IdentityProviderUserMappingRepository;

/**
 * Tier-3 efficiency hint: an IdP has had ≥10 successful logins but
 * zero active claim-to-role mappings configured.
 *
 * Without mappings, every JIT-provisioned user receives only the fallback
 * role. Configuring mappings takes ~5 minutes but prevents privilege
 * escalation noise from manual role corrections downstream.
 *
 * Trigger  : app_admin_sso_show (IdP detail page)
 * Module   : authentication
 * Role     : ROLE_ADMIN
 */
final class RoleMappingWithoutClaimRule extends AbstractGlobalAlvaHintRule
{
    private const int LOGIN_THRESHOLD = 10;

    public function __construct(
        private readonly IdentityProviderUserMappingRepository $userMappingRepo,
    ) {
    }

    public function key(): string
    {
        return 'global.role_mapping_without_claim';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['authentication'];
    }

    public function appliesToPages(): array
    {
        return ['admin_sso_show'];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $rows = $this->userMappingRepo->findProvidersWithLoginsButNoMappings($tenant, self::LOGIN_THRESHOLD);

        if (count($rows) === 0) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.role_mapping_without_claim.title',
            bodyTranslationKey: 'global.role_mapping_without_claim.body',
            bodyTranslationParams: ['%count%' => (string) count($rows)],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.role_mapping_without_claim.action',
            actionRoute: 'admin_sso_show',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_ADMIN'],
            mood: 'thinking',
            version: 1,
        );
    }
}
