<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Dependency-type classifier for an Asset → Asset edge (DORA RT_05).
 *
 * Bucket-6 (DORA RoI Sprint 9, RT_05 asset-dependency-graph) — labels each
 * edge in the dependency graph with the nature of the relation so the ESA
 * RoI XBRL exporter can emit the per-edge sub-elements that Art. 28(3)(c)
 * mandates (which asset supports which business process, which asset
 * backs up which, etc.).
 *
 * Values are stable enum strings persisted via the
 * `asset_dependency.dependency_type` column.
 */
enum AssetDependencyType: string
{
    /** Asset cannot operate without the upstream — hard runtime dependency. */
    case Requires = 'requires';

    /** Upstream serves as backup/failover for this asset (redundancy pair). */
    case BacksUp = 'backs_up';

    /** Edges that share data with the upstream (data-flow relation). */
    case SharesData = 'shares_data';

    /** Active-active redundancy — peers carrying load together. */
    case RedundantWith = 'redundant_with';

    public function label(): string
    {
        return 'asset.dependency.type.' . $this->value;
    }
}
