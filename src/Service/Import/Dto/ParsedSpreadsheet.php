<?php

declare(strict_types=1);

namespace App\Service\Import\Dto;

/**
 * Value object returned by SpreadsheetParser.
 *
 * Rows are associative arrays keyed by the header string,
 * e.g. [['Name' => 'Server A', 'Type' => 'Hardware'], ...].
 */
final class ParsedSpreadsheet
{
    /**
     * @param string[]                  $headers       Ordered list of column header strings
     * @param array<int, array<string, string|int|float|bool|null>> $rows Associative rows (0-indexed)
     * @param string[]                  $warnings      Non-fatal warnings collected during parsing
     * @param string                    $sheetName     Name of the parsed sheet
     * @param int                       $totalRowCount Number of data rows (excluding the header row)
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly array $warnings,
        public readonly string $sheetName,
        public readonly int $totalRowCount,
    ) {}

    /**
     * Returns true when no data rows were found (only headers or empty file).
     */
    public function isEmpty(): bool
    {
        return $this->totalRowCount === 0;
    }

    /**
     * Returns true when at least one non-fatal warning was emitted.
     */
    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
