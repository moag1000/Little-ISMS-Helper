<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ProcessingActivityRepository;
use App\Repository\UserRepository;

/**
 * Tier-1 hint: Privacy module active + processing activities exist
 * but no user holds ROLE_DPO in this tenant.
 *
 * DSGVO Art. 37 requires a Data Protection Officer for certain
 * processing types. Without an assigned DPO in the system, oversight
 * obligations cannot be met.
 */
final class MissingDpoForGdprRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly ProcessingActivityRepository $processingRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.missing_dpo_for_gdpr';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        return ['privacy'];
    }

    public function appliesToPages(): array
    {
        return [
            'processing_activity_index',
            'dpia_index',
            'dashboard_ciso',
            'dashboard_compliance_manager',
            'inbox',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        // Only fire if there are processing activities
        $activities = $this->processingRepository->findByTenant($tenant);
        if ($activities === []) {
            return null;
        }

        // Check if any user in this tenant has ROLE_DPO
        $dpoUsers = $this->userRepository->findByRoleInTenant('ROLE_DPO', $tenant);
        if ($dpoUsers !== []) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.missing_dpo_for_gdpr.title',
            bodyTranslationKey: 'global.missing_dpo_for_gdpr.body',
            bodyTranslationParams: ['%count%' => (string) count($activities)],
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.missing_dpo_for_gdpr.action',
            actionRoute: 'user_management_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_ADMIN'],
            mood: 'alert',
        );
    }
}
