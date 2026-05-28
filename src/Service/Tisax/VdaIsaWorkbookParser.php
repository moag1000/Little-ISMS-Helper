<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Service\Tisax\Dto\ParsedWorkbookResult;
use App\Service\Tisax\Dto\VdaIsaControlRow;
use ErrorException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\Log\LoggerInterface;

/**
 * VDA-ISA Workbook Parser
 *
 * Parses a customer-supplied (ENX-licensed) VDA-ISA Excel workbook into
 * structured {@see VdaIsaControlRow} DTOs, ready for the import wizard.
 *
 * Design decisions:
 * - Header detection by text-match, NOT column letter — VDA-ISA versions
 *   differ in layout. We scan the first 20 rows for a row that contains
 *   recognisable header signals ("Control", "Question", "Must" / "Should").
 * - Column mapping is best-effort: known aliases are tried in order; the
 *   first match wins. Unknown columns are silently ignored.
 * - Validation is left to VdaIsaWorkbookValidator (single-responsibility).
 */
final class VdaIsaWorkbookParser
{
    /** Maximum header-search window. */
    private const MAX_HEADER_SCAN_ROWS = 20;

    /** Preferred sheet names (case-insensitive, first match wins). */
    private const PREFERRED_SHEETS = ['ISA', 'VDA-ISA', 'Controls', 'Anforderungen'];

    /**
     * Header column aliases.  Key = canonical field name, value = list of
     * accepted header cell text fragments (case-insensitive substring match).
     */
    private const HEADER_ALIASES = [
        'controlId' => ['control no', 'control number', 'req. no', 'nr.', 'id', 'number'],
        'titleDe'   => ['frage (de)', 'anforderung', 'kontrollfrage', 'frage de'],
        'titleEn'   => ['question (en)', 'control question', 'question en', 'question'],
        'description' => ['objective', 'ziel', 'info', 'description', 'erläuterung'],
        'mustLevel'   => ['must', 'pflicht'],
        'shouldLevel' => ['should', 'empfehlung'],
        'highLevel'   => ['high protection', 'high'],
        'veryHighLevel' => ['very high', 'sehr hoch'],
        'iso27001Ref'   => ['iso 27001', 'iso27001', 'iso-27001', 'norm ref', 'reference'],
        'evidenceHint'  => ['evidence', 'nachweis', 'nachweise', 'audit evidence'],
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Parse the workbook at `$filePath` and return a result DTO.
     *
     * @throws ErrorException on unreadable file or missing ISA sheet
     */
    public function parse(string $filePath): ParsedWorkbookResult
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new ErrorException(sprintf('VdaIsaWorkbookParser: cannot read "%s"', $filePath));
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        $worksheet = $this->resolveSheet($spreadsheet);

        [$headerRow, $headerMap] = $this->detectHeader($worksheet);

        $controls = $this->extractControlRows($worksheet, $headerRow, $headerMap);

        $this->logger->info('VdaIsaWorkbookParser: parsed workbook', [
            'file'    => basename($filePath),
            'sheet'   => $worksheet->getTitle(),
            'headers' => array_keys($headerMap),
            'rows'    => count($controls),
        ]);

        return new ParsedWorkbookResult(
            controls: $controls,
            sheetName: $worksheet->getTitle(),
            headerRowIndex: $headerRow,
            detectedColumnMap: $headerMap,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Try preferred sheet names first, fall back to active sheet.
     */
    private function resolveSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): Worksheet
    {
        foreach (self::PREFERRED_SHEETS as $name) {
            foreach ($spreadsheet->getSheetNames() as $sheetName) {
                if (strcasecmp($sheetName, $name) === 0) {
                    return $spreadsheet->getSheetByName($sheetName);
                }
            }
        }
        return $spreadsheet->getActiveSheet();
    }

    /**
     * Scan the first MAX_HEADER_SCAN_ROWS rows for a row that looks like a
     * VDA-ISA header, then build a [canonicalField => colIndex] map.
     *
     * @return array{int, array<string, int>}
     * @throws ErrorException when no suitable header row is found
     */
    private function detectHeader(Worksheet $worksheet): array
    {
        $highestRow = $worksheet->getHighestDataRow();
        $highestCol = $worksheet->getHighestDataColumn();
        $scanRows   = min(self::MAX_HEADER_SCAN_ROWS, $highestRow);

        for ($row = 1; $row <= $scanRows; $row++) {
            $rowData = $worksheet->rangeToArray(
                'A' . $row . ':' . $highestCol . $row,
                null, true, false, false,
            )[0];

            $headerMap = $this->tryBuildHeaderMap($rowData);
            if ($headerMap !== null) {
                return [$row, $headerMap];
            }
        }

        throw new ErrorException(
            sprintf(
                'VdaIsaWorkbookParser: no VDA-ISA header row found in first %d rows. '
                . 'Expected columns containing "Control" and "Question" or "Must"/"Should".',
                $scanRows,
            ),
        );
    }

    /**
     * Attempt to map a raw row array to canonical field names.
     * Returns null when the row doesn't look like a valid VDA-ISA header.
     *
     * @param array<mixed> $rowData
     * @return array<string, int>|null
     */
    private function tryBuildHeaderMap(array $rowData): ?array
    {
        $lower = array_map(
            static fn (mixed $v): string => strtolower((string) ($v ?? '')),
            $rowData,
        );

        // Minimum signal: at least one of the ID-like aliases AND one of
        // the title/question-like aliases must be present.
        $hasIdCol       = false;
        $hasQuestionCol = false;

        foreach ($lower as $cell) {
            if ($cell === '') {
                continue;
            }
            foreach (self::HEADER_ALIASES['controlId'] as $alias) {
                if (str_contains($cell, $alias)) {
                    $hasIdCol = true;
                    break;
                }
            }
            foreach ([...self::HEADER_ALIASES['titleEn'], ...self::HEADER_ALIASES['titleDe']] as $alias) {
                if (str_contains($cell, $alias)) {
                    $hasQuestionCol = true;
                    break;
                }
            }
        }

        if (!$hasIdCol || !$hasQuestionCol) {
            return null;
        }

        // Build column map
        $map = [];
        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            foreach ($lower as $colIdx => $cell) {
                if ($cell === '') {
                    continue;
                }
                foreach ($aliases as $alias) {
                    if (str_contains($cell, $alias)) {
                        $map[$canonical] = $colIdx;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Extract data rows starting below the header row.
     *
     * @param array<string, int> $headerMap
     * @return list<VdaIsaControlRow>
     */
    private function extractControlRows(
        Worksheet $worksheet,
        int $headerRowIndex,
        array $headerMap,
    ): array {
        $highestRow = $worksheet->getHighestDataRow();
        $highestCol = $worksheet->getHighestDataColumn();
        $controls   = [];

        for ($row = $headerRowIndex + 1; $row <= $highestRow; $row++) {
            $rowData = $worksheet->rangeToArray(
                'A' . $row . ':' . $highestCol . $row,
                null, true, false, false,
            )[0];

            // Skip empty rows
            $nonEmpty = array_filter($rowData, static fn (mixed $v): bool => $v !== null && $v !== '');
            if ($nonEmpty === []) {
                continue;
            }

            $get = function (string $field) use ($rowData, $headerMap): ?string {
                return isset($headerMap[$field]) ? (string) ($rowData[$headerMap[$field]] ?? '') : null;
            };

            $controlId = $this->sanitizeCellValue(trim((string) $get('controlId')));
            if ($controlId === '') {
                continue; // skip rows without an ID (section headers, footnotes)
            }

            // Resolve best title: prefer DE when present, fall back to EN
            $titleDe  = $this->sanitizeCellValue(trim((string) $get('titleDe')));
            $titleEn  = $this->sanitizeCellValue(trim((string) $get('titleEn')));
            $title    = $titleDe !== '' ? $titleDe : $titleEn;
            if ($title === '') {
                $title = $controlId; // last resort
            }

            $controls[] = new VdaIsaControlRow(
                controlId: $controlId,
                title: $title,
                titleEn: $titleEn !== '' ? $titleEn : null,
                description: ($v = $this->sanitizeCellValue(trim((string) $get('description')))) !== '' ? $v : null,
                mustLevel: ($v = $this->sanitizeCellValue(trim((string) $get('mustLevel')))) !== '' ? $v : null,
                shouldLevel: ($v = $this->sanitizeCellValue(trim((string) $get('shouldLevel')))) !== '' ? $v : null,
                highLevel: ($v = $this->sanitizeCellValue(trim((string) $get('highLevel')))) !== '' ? $v : null,
                veryHighLevel: ($v = $this->sanitizeCellValue(trim((string) $get('veryHighLevel')))) !== '' ? $v : null,
                iso27001Ref: ($v = $this->sanitizeCellValue(trim((string) $get('iso27001Ref')))) !== '' ? $v : null,
                auditEvidenceHint: ($v = $this->sanitizeCellValue(trim((string) $get('evidenceHint')))) !== '' ? $v : null,
                rawRowIndex: $row,
            );
        }

        return $controls;
    }

    /**
     * Sanitize a cell value against DDE (Dynamic Data Exchange) injection.
     *
     * Cell values beginning with `=`, `+`, `-`, `@`, tab (`\t`), or carriage
     * return (`\r`) are treated as formula triggers by spreadsheet applications
     * (Excel, LibreOffice Calc, Google Sheets) when the data is re-exported to
     * CSV or XLSX.  Prepending a single apostrophe is the Excel-standard
     * mitigation — it renders the cell as text and disables formula evaluation.
     *
     * Reference: OWASP CSV Injection (formula injection) guidance.
     *
     * @see https://owasp.org/www-community/attacks/CSV_Injection
     */
    private function sanitizeCellValue(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (str_starts_with($value, '=')
            || str_starts_with($value, '+')
            || str_starts_with($value, '-')
            || str_starts_with($value, '@')
            || str_starts_with($value, "\t")
            || str_starts_with($value, "\r")
        ) {
            return "'" . $value;
        }

        return $value;
    }
}
