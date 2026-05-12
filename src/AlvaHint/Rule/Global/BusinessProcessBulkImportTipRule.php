<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\BusinessProcessRepository;

/**
 * Tier-3 tip: BIA list has grown beyond 15 entries — suggest the Bulk-Import wizard.
 *
 * Annual BIA owner reviews across more than 15 business processes become
 * error-prone when done record-by-record. The CSV/XLSX delta-mode import
 * (Sprint-2 F2-W2) lets owners batch-update criticality, RTO, and owner
 * fields without per-row editing, saving approximately 1.5 FTE-hours per
 * review cycle.
 *
 * Trigger  : app_business_process_index, business process count > 15
 * Module   : bcm
 * Role     : ROLE_MANAGER
 */
final class BusinessProcessBulkImportTipRule extends AbstractGlobalAlvaHintRule
{
    private const int THRESHOLD = 15;

    public function __construct(
        private readonly BusinessProcessRepository $businessProcessRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.business_process_bulk_import_tip';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['bcm'];
    }

    public function appliesToPages(): array
    {
        return ['app_business_process_index'];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $count = count($this->businessProcessRepository->findBy(['tenant' => $tenant]));

        if ($count <= self::THRESHOLD) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.business_process_bulk_import_tip.title',
            bodyTranslationKey: 'global.business_process_bulk_import_tip.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.business_process_bulk_import_tip.action',
            actionRoute: 'app_bulk_import_index',
            actionRouteParams: ['entityType' => 'business_process'],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
            version: 1,
        );
    }
}
