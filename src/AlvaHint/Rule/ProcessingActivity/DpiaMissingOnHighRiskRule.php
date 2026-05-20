<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\ProcessingActivity;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\ProcessingActivity;
use App\Entity\User;

/**
 * Tier-2 hint: GDPR Art. 5(1)(e) storage-limitation principle —
 * processing activities involving personal data without a documented
 * retention period / erasure policy are a data-protection gap.
 * Supervisory authorities routinely cite missing retention schedules
 * as a finding during inspections.
 */
final class DpiaMissingOnHighRiskRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'processing_activity.retention_missing';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['privacy'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof ProcessingActivity) {
            return false;
        }

        $retention = $entity->getRetentionPeriod();

        return $retention === null || trim($retention) === '';
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof ProcessingActivity);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'processing_activity.retention_missing.title',
            bodyTranslationKey: 'processing_activity.retention_missing.body',
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'ProcessingActivity',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'processing_activity.retention_missing.action',
            actionRoute: 'app_processing_activity_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_DPO'],
            mood: 'thinking',
        );
    }
}
