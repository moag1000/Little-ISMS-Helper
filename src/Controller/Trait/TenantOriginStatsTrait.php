<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\Tenant;

/**
 * Breaks a list of tenant-owned items down by origin relative to the current
 * tenant: own rows, rows inherited from an ancestor, rows of a subsidiary.
 *
 * Shared by the corporate-structure aware index pages (asset, incident,
 * training, ...), which previously each carried an identical private copy.
 */
trait TenantOriginStatsTrait
{
    /**
     * @param iterable<object> $items entities exposing getTenant(): ?Tenant
     *
     * @return array{own: int, inherited: int, subsidiaries: int, total: int}
     */
    private function calculateDetailedStats(iterable $items, Tenant $currentTenant): array
    {
        $ownCount = 0;
        $inheritedCount = 0;
        $subsidiariesCount = 0;

        // Get ancestors and subsidiaries for comparison
        $ancestorIds = array_map(static fn(Tenant $t) => $t->getId(), $currentTenant->getAllAncestors());
        $subsidiaryIds = array_map(static fn(Tenant $t) => $t->getId(), $currentTenant->getAllSubsidiaries());
        $currentTenantId = $currentTenant->getId();

        foreach ($items as $item) {
            $itemTenant = $item->getTenant();
            if (!$itemTenant) {
                continue;
            }

            $itemTenantId = $itemTenant->getId();
            if ($itemTenantId === $currentTenantId) {
                $ownCount++;
            } elseif (in_array($itemTenantId, $ancestorIds)) {
                $inheritedCount++;
            } elseif (in_array($itemTenantId, $subsidiaryIds)) {
                $subsidiariesCount++;
            }
        }

        return [
            'own' => $ownCount,
            'inherited' => $inheritedCount,
            'subsidiaries' => $subsidiariesCount,
            'total' => $ownCount + $inheritedCount + $subsidiariesCount,
        ];
    }
}
