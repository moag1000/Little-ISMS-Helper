<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Criticality-cascade behaviour for an Asset → Asset edge (DORA RT_05).
 *
 * Bucket-6 (DORA RoI Sprint 9, RT_05 asset-dependency-graph) — declares
 * how the criticality of the upstream asset cascades into the dependent
 * one. ESA RoI per-edge sub-elements distinguish whether an outage of
 * the upstream takes the dependent fully (cascade), partially (partial),
 * or not at all (isolated) offline.
 *
 * Used by:
 *  - `App\Service\Authority\DoraRoiXbrlExporter` — drives the per-edge
 *    `roi:B_03.03.0040` cascade-classifier element.
 *  - `App\Service\AssetDependencyService` — could be consumed in the
 *    future to scope the BSI 3.6 Maximumprinzip inheritance walk
 *    (currently all edges cascade equally).
 */
enum AssetDependencyCriticalityImpact: string
{
    /** Outage propagates fully — the dependent goes down with the upstream. */
    case Cascade = 'cascade';

    /** Outage is contained — the dependent keeps running independently. */
    case Isolated = 'isolated';

    /** Partial impact — degraded service but not full outage. */
    case Partial = 'partial';

    public function label(): string
    {
        return 'asset.dependency.criticality_impact.' . $this->value;
    }
}
