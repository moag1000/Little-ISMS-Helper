<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Asset;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Asset;
use App\Entity\User;
use App\Service\AssetDependencyService;

/**
 * Tier-2 hint: Asset has dependsOn relations whose protection need is
 * higher than its own — BSI 3.6 Maximumprinzip says we should inherit.
 *
 * Trigger: AssetDependencyService can derive a higher CIA value from
 * the dependency graph than what the asset itself currently shows.
 */
final class ProtectionInheritanceRule extends AbstractAlvaHintRule
{
    public function __construct(
        private readonly AssetDependencyService $dependencyService,
    ) {
    }

    public function key(): string
    {
        return 'asset.protection_inheritance';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['assets'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Asset) {
            return false;
        }
        if ($entity->getDependsOn()->isEmpty()) {
            return false;
        }

        $inherited = $this->dependencyService->calculateInheritedProtectionNeed($entity);

        $current = [
            'confidentiality' => (int) ($entity->getConfidentialityValue() ?? 0),
            'integrity' => (int) ($entity->getIntegrityValue() ?? 0),
            'availability' => (int) ($entity->getAvailabilityValue() ?? 0),
        ];

        foreach (['confidentiality', 'integrity', 'availability'] as $dim) {
            $inheritedValue = $inherited['inherited'][$dim] ?? $current[$dim];
            if ($inheritedValue > $current[$dim]) {
                return true;
            }
        }

        return false;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Asset);
        $inherited = $this->dependencyService->calculateInheritedProtectionNeed($entity);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'asset.protection_inheritance.title',
            bodyTranslationKey: 'asset.protection_inheritance.body',
            bodyTranslationParams: [
                '%c%' => (string) ($inherited['inherited']['confidentiality'] ?? '?'),
                '%i%' => (string) ($inherited['inherited']['integrity'] ?? '?'),
                '%a%' => (string) ($inherited['inherited']['availability'] ?? '?'),
            ],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Asset',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'asset.protection_inheritance.action',
            actionRoute: 'app_asset_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            mood: 'thinking',
        );
    }
}
