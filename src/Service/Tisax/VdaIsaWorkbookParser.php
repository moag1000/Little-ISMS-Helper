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
 * Verified against real ENX ISA 6.0.3 (EN) and ISA 6.0.2 (DE) workbooks.
 *
 * Real ENX ISA 6 structure (verified 2026-05-28):
 * - EN workbook: 11 sheets. Active sheet = "Welcome" (metadata — NOT parsed).
 *   Content sheets: "Information Security", "Prototype Protection", "Data Protection".
 * - DE workbook: 11 sheets. Active sheet = "Willkommen".
 *   Content sheets: "Informationssicherheit", "Prototypenschutz", "Datenschutz".
 * - Header row is row 2 in each content sheet (row 1 = title banner, merged cell).
 * - EN header row 2: Row_Format | Is_Title? | Control number | Maturity level |
 *   Implementation description | Reference documentation | Findings/Assessment result |
 *   Control question | Objective | Requirements (must) | Requirements (should) |
 *   Additional requirements (high) | Additional requirements (very high) | ... |
 *   ISO 27001/ISO 27002 references | BSI reference | ...
 * - DE header row 2: Row_Format | Is_Title? | Kontrollnummer | Reifegrad |
 *   Beschreibung der Umsetzung | ... | Kontrollfrage | Ziel |
 *   Anforderungen (muss) | Anforderungen (sollte) | ...
 * - Column A ("Row_Format") = "header" for section/subsection title rows — skip these.
 * - Column A ("Row_Format") = "control" or empty for actual control rows.
 * - Control IDs follow pattern: N.N.N (e.g. 1.1.1, 8.2.3, 9.5.1).
 *   Section rows have bare integers ("1", "2") or two-part IDs ("1.1", "8.2") — skip these.
 *
 * Design decisions:
 * - Header detection by text-match, NOT column letter — VDA-ISA versions
 *   differ in layout. We scan the first MAX_HEADER_SCAN_ROWS rows for a row
 *   that contains recognisable header signals.
 * - Column mapping is best-effort: known aliases are tried in order; the
 *   first match wins. Aliases are ordered most-specific first to avoid false
 *   matches (e.g. "description" alias would match "Implementation description"
 *   before "Objective" — so "objective" is listed first and "description" removed).
 * - Section header rows (col A = "header", or ID without 2+ dots) are skipped.
 * - Validation is left to VdaIsaWorkbookValidator (single-responsibility).
 */
final class VdaIsaWorkbookParser
{
    /** Maximum header-search window. */
    private const MAX_HEADER_SCAN_ROWS = 40;

    /**
     * Column A ("Row_Format") value that marks section/subsection title rows.
     * These rows must be skipped — they contain section headings, not controls.
     */
    private const ROW_FORMAT_SECTION_HEADER = 'header';

    /**
     * Preferred sheet names (case-insensitive, first match wins).
     * Ordered by specificity — exact ENX ISA 6 sheet names first,
     * then legacy/alternative names.
     */
    private const PREFERRED_SHEETS = [
        // ENX ISA 6 EN exact sheet names
        'Information Security',
        'Prototype Protection',
        'Data Protection',
        // ENX ISA 6 DE exact sheet names
        'Informationssicherheit',
        'Prototypenschutz',
        'Datenschutz',
        // Legacy / alternative names from older ISA versions
        'ISA', 'VDA-ISA', 'ISA 6', 'ISA 6.x', 'ISA6', 'VDA ISA 6',
        'Controls', 'Anforderungen',
        'Self Assessment', 'Selbstauskunft',
        'Assessment', 'Bewertung',
    ];

    /**
     * Header column aliases.  Key = canonical field name, value = list of
     * accepted header cell text fragments (case-insensitive substring match).
     *
     * IMPORTANT ordering rules (to avoid false-positive column mapping):
     * - Most-specific aliases first (verbatim ENX column headers at top).
     * - Never use a fragment that appears in a *different* column's header.
     * - "title" removed from titleEn — matches "Is_Title?" (col B) before "Control question" (col H).
     * - "beschreibung" removed from titleDe — matches "Beschreibung der Umsetzung" (impl. col) before "Kontrollfrage".
     * - "description" removed from description — matches "Implementation description" (col E) before "Objective" (col I).
     * - "reference" removed from iso27001Ref — matches "Reference documentation" (col F) before ISO norm col (col P).
     */
    private const HEADER_ALIASES = [
        'controlId' => [
            // ENX ISA 6 exact column headers (most specific first)
            'control number',       // EN col C: "Control number"
            'kontrollnummer',       // DE col C: "Kontrollnummer"
            // Legacy / alternate
            'control no', 'control id', 'req. no', 'req no',
            'kontroll-nr', 'kontroll-id', 'steuerungs-id',
            'nr.', 'nummer', '#', 'isa-nr', 'isa nr',
            // Short aliases last (avoid false matches like "id" in "Findings")
            'nr', 'number',
        ],
        'titleDe' => [
            // ENX ISA 6 DE exact column header
            'kontrollfrage',        // DE col H: "Kontrollfrage"
            // Other DE aliases (no "beschreibung" — matches "Beschreibung der Umsetzung")
            'frage (de)', 'anforderung', 'steuerungsfrage',
            'frage de', 'kontroll-frage', 'steuerungs-frage',
        ],
        'titleEn' => [
            // ENX ISA 6 EN exact column header
            'control question',     // EN col H: "Control question"
            // Other EN aliases (no "title" — matches "Is_Title?" in col B)
            'question (en)', 'question en', 'question',
            'requirement', 'assessment question',
            // "control" alias removed — too broad; "is_title?" col B contains "title"
        ],
        'description' => [
            // ENX ISA 6 col I: "Objective" / DE "Ziel"
            'objective', 'ziel',
            // Other aliases (no "description" — matches "Implementation description" / "Beschreibung der Umsetzung")
            'erläuterung', 'erlaeuterung',
            'further info', 'weiterführende information', 'comments', 'kommentar',
            'kontrollziel', 'steuerungsziel',
            'info',
        ],
        'mustLevel' => [
            // ENX ISA 6 col J: "Requirements\n(must)" / DE "Anforderungen\n(muss)"
            'requirements' . "\n" . '(must)', 'anforderungen' . "\n" . '(muss)',
            // Substring aliases (multiline cell text contains newline in PhpSpreadsheet)
            'must)', '(muss)',
            'must', 'pflicht', 'pflichtanforderung', 'verbindlich',
        ],
        'shouldLevel' => [
            'requirements' . "\n" . '(should)', 'anforderungen' . "\n" . '(sollte)',
            'should)', '(sollte)',
            'should', 'empfehlung', 'sollte', 'empfohlen',
        ],
        'highLevel' => [
            // ENX ISA 6 EN col L: "Additional requirements\nfor high protection needs"
            'for high protection needs',
            // ENX ISA 6 DE col L: "Zusatzanforderungen\nbei hohem Schutzbedarf"
            'bei hohem schutzbedarf',
            // Legacy / alternate
            'additional requirements in case of high',
            'zusatzanforderungen bei hohem',
            'high protection', 'hoher schutzbedarf',
            'high', 'hoch', 'erhöhter schutzbedarf',
        ],
        'veryHighLevel' => [
            // ENX ISA 6 EN col M: "Additional requirements\nfor very high protection needs"
            'for very high protection needs',
            // ENX ISA 6 DE col M: "Zusatzanforderungen\nbei sehr hohem Schutzbedarf"
            'bei sehr hohem schutzbedarf',
            // Legacy / alternate
            'additional requirements in case of very high',
            'zusatzanforderungen bei sehr hohem',
            'very high protection', 'sehr hoher schutzbedarf',
            'very high', 'sehr hoch',
        ],
        'iso27001Ref' => [
            // ENX ISA 6 EN col P: "Reference to other standards"
            'reference to other standards',
            // ENX ISA 6 DE col P: "Verweisung auf andere Normen"
            'verweisung auf andere normen',
            // Legacy / content-level fragments (for workbooks where ISO ref is in its own column)
            'iso 27001', 'iso27001', 'iso-27001', 'iso/iec 27001',
            'iso 27002', 'iso27002',
            // Note: "reference" removed — too broad, matches "Reference documentation" (col F)
            'norm ref', 'normbezug', 'standard reference', 'reference iso',
        ],
        'evidenceHint' => [
            // ENX ISA 6 EN col AB: "Possible evidence (not mandatory)"
            'possible evidence',
            // ENX ISA 6 DE col AB: "Mögliche Nachweise (nicht verbindlich)"
            'mögliche nachweise',
            // Legacy aliases
            'examples of evidence', 'beispiele für nachweise',
            'evidence', 'nachweis', 'nachweise', 'audit evidence',
            'further info',
        ],
        // Pre-filled Reifegrad column (integer 0-5) — when a user uploads an
        // already-assessed workbook, mirror the score into ComplianceRequirement
        // so the assess-page does not show 0 for every row.
        // ENX ISA 6 col D: "Reifegrad" (DE) / "Result" (EN).
        'maturityCurrent' => [
            'reifegrad',
            'result',
            'maturity level', 'maturity-level', 'maturitylevel',
            'level achieved', 'achieved level',
            'maturity',
        ],
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

        $company = $this->extractCompany($spreadsheet);

        $this->logger->info('VdaIsaWorkbookParser: parsed workbook', [
            'file'    => basename($filePath),
            'sheet'   => $worksheet->getTitle(),
            'headers' => array_keys($headerMap),
            'rows'    => count($controls),
            'company' => $company,
        ]);

        return new ParsedWorkbookResult(
            controls: $controls,
            sheetName: $worksheet->getTitle(),
            headerRowIndex: $headerRow,
            detectedColumnMap: $headerMap,
            workbookCompany: $company,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Cover sheets to scan for the organisation name, in priority order.
     * "Deckblatt" (DE) / "Cover" (EN) carry "Firma / Organisation"; the
     * "Ergebnisse"/"Results" sheet repeats it as "Firma:".
     */
    private const COMPANY_SHEETS = ['Deckblatt', 'Cover', 'Ergebnisse', 'Results'];

    /**
     * Label fragments (lowercased) that mark the company-name cell. The value
     * is read from the first non-empty cell to the RIGHT of the label cell.
     */
    private const COMPANY_LABELS = ['firma', 'organisation', 'company', 'organization'];

    /**
     * Read the organisation name from the workbook cover sheet.
     * Returns null when no recognisable label/value pair is found.
     */
    private function extractCompany(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): ?string
    {
        foreach (self::COMPANY_SHEETS as $sheetName) {
            $ws = null;
            foreach ($spreadsheet->getSheetNames() as $actual) {
                if (strcasecmp($actual, $sheetName) === 0) {
                    $ws = $spreadsheet->getSheetByName($actual);
                    break;
                }
            }
            if ($ws === null) {
                continue;
            }

            $highestRow = min(40, $ws->getHighestDataRow());
            $highestCol = $ws->getHighestDataColumn();

            for ($row = 1; $row <= $highestRow; $row++) {
                $cells = $ws->rangeToArray('A' . $row . ':' . $highestCol . $row, null, true, false, false)[0] ?? [];
                foreach ($cells as $colIdx => $cell) {
                    $text = strtolower(trim((string) ($cell ?? '')));
                    if ($text === '') {
                        continue;
                    }
                    foreach (self::COMPANY_LABELS as $label) {
                        if (!str_contains($text, $label)) {
                            continue;
                        }
                        // Found a label cell — take first non-empty cell to its right.
                        for ($j = $colIdx + 1; $j < count($cells); $j++) {
                            $value = trim((string) ($cells[$j] ?? ''));
                            if ($value !== '') {
                                return $value;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

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

        // Helpful diagnostic: list the actual sheet name + first non-empty cell of each scanned row
        $diag = [];
        for ($row = 1; $row <= $scanRows; $row++) {
            $rowData = $worksheet->rangeToArray('A' . $row . ':' . $highestCol . $row, null, true, false, false)[0] ?? [];
            $firstCells = array_slice(array_filter(array_map('strval', $rowData), static fn ($v) => trim($v) !== ''), 0, 4);
            if ($firstCells) {
                $diag[] = sprintf('row %d: [%s]', $row, implode(' | ', $firstCells));
            }
        }
        throw new ErrorException(
            sprintf(
                'VdaIsaWorkbookParser: no VDA-ISA header row found in first %d rows of sheet "%s". '
                . 'Expected an ID-column (control number, kontrollnummer, nr.) plus a question/description column '
                . '(control question, kontrollfrage, question). '
                . 'First non-empty cells per row: %s. '
                . 'Tip: ensure the sheet is "Information Security" / "Informationssicherheit" / '
                . '"Prototype Protection" / "Prototypenschutz" — current resolver picked "%s". '
                . 'Official ENX ISA 6 workbooks: https://portal.enx.com/isa6-en.xlsx (EN), '
                . 'https://portal.enx.com/isa6-de.xlsx (DE).',
                $scanRows,
                $worksheet->getTitle(),
                implode(' || ', array_slice($diag, 0, 8)),
                $worksheet->getTitle(),
            ),
        );
    }

    /**
     * Attempt to map a raw row array to canonical field names.
     * Returns null when the row doesn't look like a valid VDA-ISA header.
     *
     * Minimum signal: at least one ID-like alias AND one question-like alias
     * must be present in the row.
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

        // Build column map (first alias match wins per canonical field)
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
     * Skips:
     * - Empty rows.
     * - Section/subsection header rows (col A = "header" in ENX ISA 6 "Row_Format" column).
     * - Rows without a control ID.
     * - Rows whose control ID does not contain at least one dot (section numbers
     *   like "1", "1.1", "8" are category headings, not leaf controls).
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

            // Skip ENX ISA 6 section/subsection header rows:
            // Column A ("Row_Format") = "header" marks category title rows (e.g. "1 IS Policies")
            $rowFormatCell = strtolower(trim((string) ($rowData[0] ?? '')));
            if ($rowFormatCell === self::ROW_FORMAT_SECTION_HEADER) {
                continue;
            }

            $get = function (string $field) use ($rowData, $headerMap): ?string {
                return isset($headerMap[$field]) ? (string) ($rowData[$headerMap[$field]] ?? '') : null;
            };

            $controlId = $this->sanitizeCellValue(trim((string) $get('controlId')));
            if ($controlId === '') {
                continue; // skip rows without an ID (footnotes, blank content rows)
            }

            // Skip section/subsection IDs (bare integers or two-part IDs like "1", "1.1", "8").
            // Real control IDs have at least 2 dots: "1.1.1", "8.2.3", "9.5.1".
            // Two-part IDs like "1.1" are subsection headings, not leaf controls.
            if (substr_count($controlId, '.') < 2) {
                continue;
            }

            // Resolve best title: prefer DE question when present, fall back to EN
            $titleDe  = $this->sanitizeCellValue(trim((string) $get('titleDe')));
            $titleEn  = $this->sanitizeCellValue(trim((string) $get('titleEn')));
            $title    = $titleDe !== '' ? $titleDe : $titleEn;
            if ($title === '') {
                $title = $controlId; // last resort
            }

            // Pre-filled Reifegrad: parse the cell as int and clamp to 0-5.
            // Non-numeric or out-of-range values map to null (treat as unrated).
            $maturityCurrent = null;
            $maturityRaw     = trim((string) $get('maturityCurrent'));
            if ($maturityRaw !== '' && is_numeric($maturityRaw)) {
                $maturityInt = (int) $maturityRaw;
                if ($maturityInt >= 0 && $maturityInt <= 5) {
                    $maturityCurrent = $maturityInt;
                }
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
                maturityCurrent: $maturityCurrent,
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
