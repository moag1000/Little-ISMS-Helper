<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\AuditFinding;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\AuditFinding;
use App\Entity\User;

/**
 * Tier-2 hint: ISO 27001 Cl. 10.1 — every major / critical finding
 * must have a corresponding corrective action. Hint links straight
 * into the CAPA new form pre-targeted at this finding.
 */
final class CriticalWithoutCapaRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'audit_finding.critical_without_capa';
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
        if (!$entity instanceof AuditFinding) {
            return false;
        }
        if (!in_array($entity->getSeverity(), ['critical', 'major'], true)) {
            return false;
        }
        $actions = $entity->getCorrectiveActions();

        return $actions === null || $actions->count() === 0;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof AuditFinding);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'audit_finding.no_capa.title',
            bodyTranslationKey: 'audit_finding.no_capa.body',
            bodyTranslationParams: [
                '%severity%' => $entity->getSeverity(),
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'AuditFinding',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'audit_finding.no_capa.action',
            actionRoute: 'app_corrective_action_new',
            actionRouteParams: ['findingId' => $entity->getId() ?? 0],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
