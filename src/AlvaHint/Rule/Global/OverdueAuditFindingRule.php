<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditFindingRepository;

/**
 * Tier-2 hint: Audit findings past their due date without resolved status.
 *
 * ISO 27001 Cl. 10.1 requires timely correction of non-conformities.
 * Overdue findings with no corrective action escalate audit risk.
 */
final class OverdueAuditFindingRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly AuditFindingRepository $findingRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.overdue_audit_finding';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return [];
    }

    public function appliesToPages(): array
    {
        return [
            'audit_finding_index',
            'audit_index',
            'dashboard_ciso',
            'dashboard_auditor',
            'dashboard_compliance_manager',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $overdue = $this->findingRepository->findOverdue($tenant);

        if ($overdue === []) {
            return null;
        }

        $count = count($overdue);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.overdue_audit_finding.title',
            bodyTranslationKey: 'global.overdue_audit_finding.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.overdue_audit_finding.action',
            actionRoute: 'app_audit_finding_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_AUDITOR'],
            mood: 'warning',
        );
    }
}
