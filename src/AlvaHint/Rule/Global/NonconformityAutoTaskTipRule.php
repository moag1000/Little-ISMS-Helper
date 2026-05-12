<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\AuditFinding;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditFindingRepository;

/**
 * Tier-3 tip: tenant has > 5 unresolved AuditFindings without linkedRequirements.
 *
 * Linking AuditFindings to ISO/BSI ComplianceRequirements triggers automatic
 * CorrectiveAction tasks for the requirement owners (F15), closing the loop
 * between NC detection and documented treatment. When > 5 findings lack
 * norm-references this rule surfaces the tip on the AuditFinding index page.
 *
 * Trigger  : app_audit_finding_index, tenant has > 5 open findings without linkedRequirements
 * Module   : audits
 * Role     : ROLE_MANAGER
 * Dismiss  : nonconformity_auto_task_tip@v1
 */
final class NonconformityAutoTaskTipRule extends AbstractGlobalAlvaHintRule
{
    private const int THRESHOLD = 5;

    public function __construct(
        private readonly AuditFindingRepository $auditFindingRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.nonconformity_auto_task_tip';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['audits'];
    }

    public function appliesToPages(): array
    {
        return ['app_audit_finding_index'];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $openFindings = $this->auditFindingRepository->findOpenByTenant($tenant);

        $unreferencedCount = 0;

        foreach ($openFindings as $finding) {
            if ($finding->getLinkedRequirements()->isEmpty()) {
                $unreferencedCount++;
            }
        }

        if ($unreferencedCount <= self::THRESHOLD) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.nonconformity_auto_task_tip.title',
            bodyTranslationKey: 'global.nonconformity_auto_task_tip.body',
            bodyTranslationParams: ['%count%' => (string) $unreferencedCount],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.nonconformity_auto_task_tip.action',
            actionRoute: 'app_audit_finding_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
            version: 1,
        );
    }
}
