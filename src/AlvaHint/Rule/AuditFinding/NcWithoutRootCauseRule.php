<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\AuditFinding;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\AuditFinding;
use App\Entity\User;
use DateTimeImmutable;

/**
 * S17 B4 — Tier-2 hint: ISO 27001 Cl. 10.2 b) requires the cause of every
 * Nonconformity to be evaluated. Fires when a major/minor NC is older than
 * 30 days and the root-cause summary field is still empty.
 */
final class NcWithoutRootCauseRule extends AbstractAlvaHintRule
{
    private const STALE_THRESHOLD_DAYS = 30;

    public function key(): string
    {
        return 'audit_finding.nc_without_root_cause';
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
        if (!$entity->isNonconformity()) {
            return false;
        }
        $summary = $entity->getNcRootCauseSummary();
        if ($summary !== null && trim($summary) !== '') {
            return false;
        }
        $createdAt = $entity->getCreatedAt();
        // DateTimeInterface comparison is safe across DateTime/DateTimeImmutable.
        $threshold = (new DateTimeImmutable())->modify('-' . self::STALE_THRESHOLD_DAYS . ' days');

        return $createdAt < $threshold;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof AuditFinding);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'audit_finding.nc_without_root_cause.title',
            bodyTranslationKey: 'audit_finding.nc_without_root_cause.body',
            bodyTranslationParams: [
                '%days%' => (string) self::STALE_THRESHOLD_DAYS,
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'AuditFinding',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'audit_finding.nc_without_root_cause.action',
            actionRoute: 'app_audit_finding_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
