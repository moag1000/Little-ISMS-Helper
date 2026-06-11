<?php

declare(strict_types=1);

namespace App\Service\Bsi;

/**
 * Value object returned by IsoToBsiGapService::buildGap().
 *
 * @phpstan-type GapItem array{
 *   requirementId: string,
 *   baustein: string,
 *   tier: string,
 *   state: string,
 *   trust: string,
 *   delta: int,
 *   isoControl: string|null,
 *   evidence: list<string>
 * }
 * @phpstan-type BucketCounts array{erledigt: int, quick_win: int, bsi_arbeit: int, pruefen: int}
 */
final class BsiGapResult
{
    /**
     * @param list<GapItem>  $items        One entry per in-scope BSI requirement
     * @param BucketCounts   $bucketCounts Aggregated action-bucket counters
     * @param int            $total        Total number of in-scope BSI requirements
     */
    public function __construct(
        public readonly array $items,
        public readonly array $bucketCounts,
        public readonly int $total,
    ) {
    }
}
