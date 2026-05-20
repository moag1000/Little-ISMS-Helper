<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Asset;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\User;

/**
 * Tier-2 hint: ISO 27001 Cl. 8.2 / A.5.9 — assets linked to high
 * risks should have a confidentiality/integrity/availability
 * classification set. Without it the protection level is invisible
 * and cannot be inherited by dependent assets (BSI 3.6).
 */
final class CriticalityMissingOnHighRiskAssetRule extends AbstractAlvaHintRule
{
    private const int HIGH_RISK_THRESHOLD = 12;

    public function key(): string
    {
        return 'asset.criticality_missing_high_risk';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['assets', 'risks'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Asset) {
            return false;
        }

        // CIA classification must be absent
        if (
            $entity->getConfidentialityValue() !== null
            || $entity->getIntegrityValue() !== null
            || $entity->getAvailabilityValue() !== null
        ) {
            return false;
        }

        // Must be linked to at least one high-inherent-risk
        foreach ($entity->getRisks() as $risk) {
            if (!$risk instanceof Risk) {
                continue;
            }
            if ($risk->getInherentRiskLevel() >= self::HIGH_RISK_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Asset);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'asset.criticality_missing_high_risk.title',
            bodyTranslationKey: 'asset.criticality_missing_high_risk.body',
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Asset',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'asset.criticality_missing_high_risk.action',
            actionRoute: 'app_asset_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
