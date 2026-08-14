<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Service\Tisax\Dto\ParsedWorkbookResult;

/**
 * Decides which VDA-ISA catalogue an uploaded workbook belongs to.
 *
 * ISA 6 and ISA 2027 are both currently certifiable, so an upload must be
 * routed to the matching catalogue: without this, TisaxRequirementMapper
 * create-if-missing behaviour would append the six ISA-2027-only controls to
 * the ISA 6 framework and leave the eight dropped ISA 6 controls behind as
 * stale rows — one framework silently holding a mix of two standards.
 *
 * Detection is by control-number signature rather than the cover-sheet
 * "Version" field: that field is absent in the ISA 2027 workbook, while the
 * control numbers are always present and are what the mapper keys on.
 */
final class TisaxCatalogueVersionDetector
{
    /** Control numbers introduced by ISA 2027 (absent from ISA 6). */
    public const ISA2027_ONLY = ['6.1.3', '8.1.9', '8.1.10', '8.1.11', '8.1.12', '8.1.13'];

    /** Control numbers dropped by ISA 2027 (present in ISA 6). */
    public const ISA6_ONLY = ['1.2.4', '8.3.1', '8.3.2', '8.4.1', '8.4.2', '8.4.3', '8.5.1', '8.5.2'];

    /**
     * @param  list<string> $controlIds
     * @return array{version: string, confident: bool, isa2027Hits: list<string>, isa6Hits: list<string>}
     */
    public function detectFromControlIds(array $controlIds): array
    {
        $ids = array_flip($controlIds);

        $isa2027Hits = array_values(array_filter(
            self::ISA2027_ONLY,
            static fn (string $id): bool => isset($ids[$id]),
        ));
        $isa6Hits = array_values(array_filter(
            self::ISA6_ONLY,
            static fn (string $id): bool => isset($ids[$id]),
        ));

        // A clean workbook hits exactly one signature set. A partially filled
        // or hand-edited file can hit both or neither — then we fall back to
        // ISA 6 (the established default) and flag it as unconfident so the
        // wizard can ask instead of guessing.
        $version = match (true) {
            $isa2027Hits !== [] && $isa6Hits === [] => TisaxCatalogueProvider::VERSION_ISA2027,
            $isa6Hits !== [] && $isa2027Hits === [] => TisaxCatalogueProvider::VERSION_ISA6,
            // Both signatures present — trust the larger evidence set.
            $isa2027Hits !== [] && $isa6Hits !== [] => count($isa2027Hits) > count($isa6Hits)
                ? TisaxCatalogueProvider::VERSION_ISA2027
                : TisaxCatalogueProvider::VERSION_ISA6,
            default => TisaxCatalogueProvider::VERSION_ISA6,
        };

        return [
            'version'     => $version,
            'confident'   => ($isa2027Hits !== []) !== ($isa6Hits !== []),
            'isa2027Hits' => $isa2027Hits,
            'isa6Hits'    => $isa6Hits,
        ];
    }

    /**
     * @return array{version: string, confident: bool, isa2027Hits: list<string>, isa6Hits: list<string>}
     */
    public function detect(ParsedWorkbookResult $parsed): array
    {
        return $this->detectFromControlIds(
            array_map(static fn ($row): string => $row->controlId, $parsed->controls),
        );
    }
}
