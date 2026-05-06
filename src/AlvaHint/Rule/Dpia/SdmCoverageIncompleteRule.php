<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Dpia;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\User;

/**
 * Tier-3 hint: SDM 3.1 (Standard-Datenschutzmodell der DSK) demands
 * an assessment for all seven Gewährleistungsziele. Hint fires for
 * non-draft DPIAs whose SDM coverage is below 100% — a finding the
 * DSK reviewers regularly cite.
 */
final class SdmCoverageIncompleteRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'dpia.sdm_coverage_incomplete';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['compliance'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof DataProtectionImpactAssessment) {
            return false;
        }
        if (in_array($entity->getStatus(), ['draft', 'archived'], true)) {
            return false;
        }

        return $entity->getSdmCoveragePercent() < 100;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof DataProtectionImpactAssessment);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'dpia.sdm_incomplete.title',
            bodyTranslationKey: 'dpia.sdm_incomplete.body',
            bodyTranslationParams: [
                '%coverage%' => (string) $entity->getSdmCoveragePercent(),
            ],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'DataProtectionImpactAssessment',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'dpia.sdm_incomplete.action',
            actionRoute: 'app_dpia_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            mood: 'thinking',
        );
    }
}
