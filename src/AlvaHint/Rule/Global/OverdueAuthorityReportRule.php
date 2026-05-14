<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Authority\AuthorityHubService;

/**
 * Tier-1 regulatory hint: at least one EU authority reporting obligation is overdue.
 *
 * Fires when AuthorityHubService reports ≥1 "overdue" obligation for the
 * current tenant. This hint is non-dismissible (tier-1) because it signals a
 * compliance breach — missing regulatory notifications can result in
 * significant fines under GDPR, NIS-2, or DORA.
 *
 * Trigger  : dashboard_ciso / dashboard_compliance_manager / inbox
 * Module   : eu_authority_reporting
 * Role     : ROLE_MANAGER
 * Tier     : 1 (regulatory, non-dismissible)
 */
final class OverdueAuthorityReportRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly AuthorityHubService $hubService,
    ) {
    }

    public function key(): string
    {
        return 'global.overdue_authority_report';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        return ['eu_authority_reporting'];
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
        if (!$this->hubService->hasOverdueObligation($tenant)) {
            return null;
        }

        $summary = $this->hubService->getStatusSummary($tenant);
        $overdueCount = $summary['overdue'];

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.overdue_authority_report.title',
            bodyTranslationKey: 'global.overdue_authority_report.body',
            bodyTranslationParams: ['%count%' => (string) $overdueCount],
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.overdue_authority_report.action',
            actionRoute: 'authority_hub_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'warning',
            version: 1,
        );
    }
}
