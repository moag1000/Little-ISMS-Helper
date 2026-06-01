<?php

declare(strict_types=1);

namespace App\Service\Tisax\Dto;

/**
 * Result DTO from VdaIsaWorkbookParser::parse().
 *
 * @phpstan-type HeaderMap array<string, int>
 */
final readonly class ParsedWorkbookResult
{
    /**
     * @param list<VdaIsaControlRow> $controls
     * @param array<string, int>     $detectedColumnMap  canonical => colIndex
     */
    public function __construct(
        public array $controls,
        public string $sheetName,
        public int $headerRowIndex,
        public array $detectedColumnMap,
        /**
         * Company / organisation name read from the workbook cover sheet
         * ("Deckblatt" → "Firma / Organisation"). Null when not found.
         * Used to warn when the uploaded assessment belongs to a different
         * organisation than the current tenant.
         */
        public ?string $workbookCompany = null,

        /**
         * VDA-ISA version read from the cover sheet "Version:" field
         * (e.g. "6.0.2", "6.0.3"). Null when not found. Only major version 6
         * is supported by the importer; older workbooks are rejected upstream.
         */
        public ?string $workbookVersion = null,
    ) {}

    public function getControlCount(): int
    {
        return count($this->controls);
    }

    /**
     * Group controls by their TISAX tier (information_security, prototype_protection, data_protection).
     *
     * @return array<string, list<VdaIsaControlRow>>
     */
    public function groupByTier(): array
    {
        $groups = [];
        foreach ($this->controls as $control) {
            // Authoritative dimension from the source sheet — not the ID-prefix
            // guess (DP restarts numbering at 9.x, which getTier() misreads).
            $groups[$control->dimension][] = $control;
        }
        return $groups;
    }
}
