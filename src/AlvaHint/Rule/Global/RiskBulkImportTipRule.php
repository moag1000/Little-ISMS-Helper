<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RiskRepository;

/**
 * Tier-3 tip: risk register has grown beyond 30 entries — suggest the Bulk-Import wizard.
 *
 * Once a register exceeds 30 risks, manual owner and score updates become
 * tedious. The CSV/XLSX bulk-import wizard (Sprint-2 F2-W2) handles owner
 * re-assignment and inherent-score bulk-updates without touching individual
 * records. Surfacing this shortcut saves approximately 2 FTE-hours per cycle.
 *
 * Trigger  : app_risk_index, risk count for tenant > 30
 * Module   : risks
 * Role     : ROLE_MANAGER
 */
final class RiskBulkImportTipRule extends AbstractGlobalAlvaHintRule
{
    private const int THRESHOLD = 30;

    public function __construct(
        private readonly RiskRepository $riskRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.risk_bulk_import_tip';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['risks'];
    }

    public function appliesToPages(): array
    {
        return ['app_risk_index'];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $count = count($this->riskRepository->findByTenant($tenant));

        if ($count <= self::THRESHOLD) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.risk_bulk_import_tip.title',
            bodyTranslationKey: 'global.risk_bulk_import_tip.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.risk_bulk_import_tip.action',
            actionRoute: 'app_bulk_import_index',
            actionRouteParams: ['entityType' => 'risk'],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
            version: 1,
        );
    }
}
