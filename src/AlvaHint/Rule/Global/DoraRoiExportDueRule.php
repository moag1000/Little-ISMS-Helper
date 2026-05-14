<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Authority\DoraRegisterOfInformationRepository;
use DateTimeImmutable;

/**
 * Tier-2 warning hint: DORA Register of Information not yet submitted for current year.
 *
 * Fires when:
 *  - Module nis2_dora is active
 *  - No DoraRegisterOfInformation record exists for the current calendar year,
 *    OR the existing record has not been marked as submitted
 *
 * This is a tier-2 (dismissible) hint — it warns about a regulatory obligation
 * under DORA Art. 28 but is less urgent than the OverdueAuthorityReportRule
 * since the DORA RoI has a year-end deadline rather than a fixed-day deadline.
 *
 * Trigger  : dashboard_ciso / dashboard_compliance_manager / inbox
 * Module   : nis2_dora
 * Role     : ROLE_MANAGER
 * Tier     : 2 (warning, dismissible)
 */
final class DoraRoiExportDueRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly DoraRegisterOfInformationRepository $roiRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.dora_roi_export_due';
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
            'inbox',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $record = $this->roiRepository->findCurrentYearForTenant($tenant);

        // No record at all OR record exists but not submitted → fire hint
        if ($record !== null && $record->isSubmitted()) {
            return null;
        }

        $year = (int) (new DateTimeImmutable())->format('Y');

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.dora_roi_export_due.title',
            bodyTranslationKey: 'global.dora_roi_export_due.body',
            bodyTranslationParams: ['%year%' => (string) $year],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.dora_roi_export_due.action',
            actionRoute: 'dora_roi_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'info',
            version: 1,
        );
    }
}
