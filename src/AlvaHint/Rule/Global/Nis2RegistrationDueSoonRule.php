<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Authority\Nis2RegistrationProfileRepository;

/**
 * Tier-2 warning hint: NIS-2 BSI-Portal registration is due within 30 days.
 *
 * Fires when the tenant has a Nis2RegistrationProfile whose nextDueAt
 * is within the next 30 days but not yet overdue. Dismissible (tier-2).
 *
 * When the deadline passes and the profile becomes overdue, the tier-1
 * Nis2RegistrationOverdueRule takes over (non-dismissible).
 *
 * Trigger  : dashboard_ciso / dashboard_compliance_manager
 * Module   : nis2_dora
 * Role     : ROLE_MANAGER
 * Tier     : 2 (audit gap, dismissible)
 */
final class Nis2RegistrationDueSoonRule extends AbstractGlobalAlvaHintRule
{
    private const int DUE_SOON_DAYS = 30;

    public function __construct(
        private readonly Nis2RegistrationProfileRepository $profileRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.nis2_registration_due_soon';
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
            'dashboard_compliance_manager',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $profile = $this->profileRepository->findForTenant($tenant);

        if ($profile === null) {
            return null;
        }

        // Do not fire if overdue — Nis2RegistrationOverdueRule (tier-1) handles that
        if ($profile->isOverdue()) {
            return null;
        }

        if (!$profile->isDueSoon(self::DUE_SOON_DAYS)) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.nis2_registration_due_soon.title',
            bodyTranslationKey: 'global.nis2_registration_due_soon.body',
            bodyTranslationParams: ['%date%' => $profile->getNextDueAt()->format('d.m.Y')],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Nis2RegistrationProfile',
            entityId: $profile->getId() ?? 0,
            actionLabelTranslationKey: 'global.nis2_registration_due_soon.action',
            actionRoute: 'nis2_registration_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
            version: 1,
        );
    }
}
