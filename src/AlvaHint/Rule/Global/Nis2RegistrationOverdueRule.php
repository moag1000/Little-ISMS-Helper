<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Authority\Nis2RegistrationProfileRepository;
use DateTimeImmutable;

/**
 * Tier-1 regulatory hint: NIS-2 BSI-Portal registration is overdue.
 *
 * Fires when the tenant has a Nis2RegistrationProfile whose nextDueAt
 * is in the past. This is a compliance obligation under BSIG § 33;
 * non-dismissible (tier-1).
 *
 * Trigger  : dashboard_ciso / dashboard_compliance_manager / inbox
 * Module   : nis2_dora
 * Role     : ROLE_MANAGER
 * Tier     : 1 (regulatory, non-dismissible)
 */
final class Nis2RegistrationOverdueRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly Nis2RegistrationProfileRepository $profileRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.nis2_registration_overdue';
    }

    public function priorityTier(): int
    {
        return 1;
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
            'inbox',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $profile = $this->profileRepository->findForTenant($tenant);

        if ($profile === null) {
            return null;
        }

        if (!$profile->isOverdue()) {
            return null;
        }

        $daysOverdue = (int) (new DateTimeImmutable())->diff($profile->getNextDueAt())->days;

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.nis2_registration_overdue.title',
            bodyTranslationKey: 'global.nis2_registration_overdue.body',
            bodyTranslationParams: ['%date%' => $profile->getNextDueAt()->format('d.m.Y')],
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Nis2RegistrationProfile',
            entityId: $profile->getId() ?? 0,
            actionLabelTranslationKey: 'global.nis2_registration_overdue.action',
            actionRoute: 'nis2_registration_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'warning',
            version: 1,
        );
    }
}
