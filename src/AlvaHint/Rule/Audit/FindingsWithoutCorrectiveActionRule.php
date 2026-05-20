<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Audit;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\InternalAudit;
use App\Entity\User;
use App\Enum\AuditFindingStatus;

/**
 * Tier-2 hint: ISO 27001 Cl. 10.1 — internal audits with open
 * findings (status Open or InProgress) where none of the findings
 * have an assigned responsible user represent an audit-closure gap.
 * External auditors consider unassigned findings as evidence that
 * the corrective action process is not functioning.
 */
final class FindingsWithoutCorrectiveActionRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'audit.findings_without_capa';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['audits'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof InternalAudit) {
            return false;
        }

        $findings = $entity->getStructuredFindings();
        if ($findings->isEmpty()) {
            return false;
        }

        foreach ($findings as $finding) {
            $status = $finding->getStatusEnum();
            if (
                $status !== null
                && in_array($status, [AuditFindingStatus::Open, AuditFindingStatus::InProgress], true)
                && $finding->getAssignedTo() === null
                && $finding->getAssignedPerson() === null
            ) {
                return true;
            }
        }

        return false;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof InternalAudit);

        $unassigned = 0;
        foreach ($entity->getStructuredFindings() as $finding) {
            $status = $finding->getStatusEnum();
            if (
                $status !== null
                && in_array($status, [AuditFindingStatus::Open, AuditFindingStatus::InProgress], true)
                && $finding->getAssignedTo() === null
                && $finding->getAssignedPerson() === null
            ) {
                ++$unassigned;
            }
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'audit.findings_without_capa.title',
            bodyTranslationKey: 'audit.findings_without_capa.body',
            bodyTranslationParams: [
                '%count%' => $unassigned,
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'InternalAudit',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'audit.findings_without_capa.action',
            actionRoute: 'app_audit_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
