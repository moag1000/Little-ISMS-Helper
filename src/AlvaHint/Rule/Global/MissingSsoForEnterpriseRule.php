<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\IdentityProviderRepository;
use App\Repository\UserRepository;

/**
 * Tier-2 audit-gap hint: tenant has more than 20 active users but no SSO
 * provider configured.
 *
 * ISO 27001:2022 Annex A 5.17 (Authentication information) and 8.5 (Secure
 * authentication) recommend centralised identity for larger teams. Without SSO,
 * each user manages a local password, raising the risk of weak credentials and
 * making account lifecycle management error-prone.
 *
 * Trigger : admin_sso_index, user count for tenant > 20, no active IdP
 * Module  : authentication
 * Role    : ROLE_ADMIN
 */
final class MissingSsoForEnterpriseRule extends AbstractGlobalAlvaHintRule
{
    private const int THRESHOLD = 20;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly IdentityProviderRepository $idpRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.missing_sso_for_enterprise';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['authentication'];
    }

    public function appliesToPages(): array
    {
        return ['admin_sso_index', 'user_management_index'];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $userCount = $this->userRepository->countActiveByTenant($tenant);
        if ($userCount <= self::THRESHOLD) {
            return null;
        }

        $activeIdps = $this->idpRepository->findEnabledForTenant($tenant);
        if ($activeIdps !== []) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.missing_sso_for_enterprise.title',
            bodyTranslationKey: 'global.missing_sso_for_enterprise.body',
            bodyTranslationParams: ['%count%' => (string) $userCount],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.missing_sso_for_enterprise.action',
            actionRoute: 'admin_sso_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_ADMIN'],
            mood: 'thinking',
            version: 1,
        );
    }
}
