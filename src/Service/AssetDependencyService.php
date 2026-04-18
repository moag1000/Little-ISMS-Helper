<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;

/**
 * BSI 3.6: Schutzbedarfsvererbung (Maximumprinzip).
 *
 * Walks the Asset.dependsOn graph upstream and returns the highest
 * required C/I/A values across the asset itself plus everything it
 * depends on. Detects cycles and stops re-visiting.
 */
class AssetDependencyService
{
    /**
     * @return array{
     *     confidentiality: int,
     *     integrity: int,
     *     availability: int,
     *     drivenBy: array{
     *         confidentiality: ?Asset,
     *         integrity: ?Asset,
     *         availability: ?Asset
     *     }
     * }
     */
    public function calculateInheritedProtectionNeed(Asset $asset): array
    {
        $max = [
            'confidentiality' => (int) ($asset->getConfidentialityValue() ?? 0),
            'integrity' => (int) ($asset->getIntegrityValue() ?? 0),
            'availability' => (int) ($asset->getAvailabilityValue() ?? 0),
        ];
        $drivers = [
            'confidentiality' => $asset,
            'integrity' => $asset,
            'availability' => $asset,
        ];

        $visited = [$asset->getId() => true];
        $queue = $asset->getDependsOn()->toArray();

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!$current instanceof Asset || isset($visited[$current->getId()])) {
                continue;
            }
            $visited[$current->getId()] = true;

            foreach (['confidentiality', 'integrity', 'availability'] as $dim) {
                $val = (int) (match ($dim) {
                    'confidentiality' => $current->getConfidentialityValue(),
                    'integrity' => $current->getIntegrityValue(),
                    'availability' => $current->getAvailabilityValue(),
                });
                if ($val > $max[$dim]) {
                    $max[$dim] = $val;
                    $drivers[$dim] = $current;
                }
            }

            foreach ($current->getDependsOn() as $upstream) {
                if (!isset($visited[$upstream->getId()])) {
                    $queue[] = $upstream;
                }
            }
        }

        return [
            'confidentiality' => $max['confidentiality'],
            'integrity' => $max['integrity'],
            'availability' => $max['availability'],
            'drivenBy' => $drivers,
        ];
    }

    /**
     * Returns true if applying the inheritance would raise any CIA value
     * above the asset's own declared value.
     */
    public function hasInheritedIncrease(Asset $asset): bool
    {
        $result = $this->calculateInheritedProtectionNeed($asset);
        return $result['confidentiality'] > (int) ($asset->getConfidentialityValue() ?? 0)
            || $result['integrity'] > (int) ($asset->getIntegrityValue() ?? 0)
            || $result['availability'] > (int) ($asset->getAvailabilityValue() ?? 0);
    }
}
