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
            // ISA 5.x dual-id layout: "ISA New" carries the 6-compatible id
            // (e.g. 1.1.1). "ISA Classic" (old numbering) is deliberately NOT an
            // alias — it sits left of "ISA New" and would win the leftmost-column
            // match, yielding ids that do not line up with the ISA-6 framework.
            'isa new',
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
        // ENX ISA 6 col E: the assessor's documented MEASURE ("Maßnahme").
        // Verbatim headers first; DP sheet uses the EN label even in the DE
        // workbook ("Description of implementation").
        'implementationDescription' => [
            'beschreibung der umsetzung',
            'description of implementation',
            'implementation description',
            'umsetzungsbeschreibung',
        ],
        // ENX ISA 6 col F: document references backing the implementation
        // ("Dokumente"). DP sheet uses the EN label ("Reference Documentation").
        'referenceDocumentation' => [
            'referenz dokumentation',
            'reference documentation',
            'referenzdokumentation',
            'verweis auf dokumentation',
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
            // Prototype Protection col K has no high/very-high split — instead a
            // single escalation tier for protection-classified vehicles. Capture
            // it as the PP "high" tier so the escalation requirement is not lost.
            'schutzbedürftig klassifizierten fahrzeugen',
            'vehicles classified', 'protection-worthy vehicles',
            // Legacy / alternate
            'additional requirements in case of high',
            'zusatzanforderungen bei hohem',
            'high protection', 'hoher schutzbedarf',
            'high', 'hoch', 'erhöhter schutzbedarf',
        ],
        // VDA-ISA 6 Information-Security col N: "Zusätzliche Anforderungen für
        // das vereinfachte Gruppen Assessment (SGA / Simplified Group
        // Assessments)" — a scope-specific tier, separate from protection needs.
        'sgaLevel' => [
            'vereinfachte gruppen assessment',
            'simplified group assessment',
            'vereinfachte gruppen',
            'simplified group',
            'sga',
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
        // ENX ISA 6 col D: "Reifegrad" (DE, IS+PP) / "Bewertung" (DE, DP) /
        // "Result" (EN). DP carries a tristate ("OK"/…) rather than 0-5.
        'maturityCurrent' => [
            'reifegrad',
            'bewertung',
            'result',
            'maturity level', 'maturity-level', 'maturitylevel',
            'level achieved', 'achieved level',
            'maturity',
        ],
    ];

    /**
     * The three VDA-ISA assessment dimensions and their content-sheet name
     * candidates (case-insensitive, first match wins). Each dimension is parsed
     * independently so a workbook contributes Information Security, Prototype
     * Protection AND Data Protection controls — not just the first sheet found.
     *
     * @var array<string, list<string>>
     */
    private const CONTENT_SHEETS = [
        'information_security' => ['Information Security', 'Informationssicherheit'],
        'prototype_protection' => ['Prototype Protection', 'Prototypenschutz'],
        'data_protection'      => ['Data Protection', 'Datenschutz'],
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

        // Version gate — the importer maps controls into the ISA-6 framework.
        // ISA 5.x is a strict subset of ISA 6 (every 5.1 control id also exists
        // in 6.x; 6.x merely adds ~17 controls incl. the revised Data-Protection
        // module), so a 5.x workbook is accepted as a PARTIAL import — its
        // controls match their ISA-6 counterparts and the 6.x-only controls
        // stay unassessed. ISA 4.x, however, is a different catalogue (ISO
        // 27001:2013 basis; "Entfernung der Kompatibilität mit ISA 4" per the
        // ISA-6 change log) and is rejected with a clear, user-facing message.
        $version = $this->detectVersion($spreadsheet);
        if ($version !== null && $this->majorVersion($version) < 5) {
            throw new ErrorException(sprintf(
                'Diese VDA-ISA-Version (%1$s) wird vom Import nicht unterstützt. '
                . 'Unterstützt werden ISA 5.x (Teilimport) und ISA 6.x '
                . '(ISA6_DE_6.0.x.xlsx / ISA6_EN_6.0.x.xlsx). '
                . 'ISA 4.x basiert auf einem anderen Control-Katalog (ISO 27001:2013) '
                . 'und ist nicht kompatibel. — '
                . 'VDA-ISA version %1$s is not supported; please upload an ISA 5.x or 6.x workbook.',
                $version,
            ));
        }

        // Parse ALL THREE content dimensions independently — a workbook
        // contributes Information Security + Prototype Protection + Data
        // Protection, each with its own restarted numbering. Previously only
        // the first matching sheet was read, silently dropping PP and DP.
        $controls       = [];
        $parsedSheets   = [];
        $firstHeaderRow = 0;
        $firstHeaderMap = [];

        // ISA 5.x Data Protection used coarse two-part control ids (9.1 … 9.4)
        // that ISA 6 refined to three-part leaves (9.1.1 … 9.4.1, one leaf per
        // section for 9.1–9.4). For a 5.x partial import we normalise those DP
        // ids to their 6.x counterpart so the four DP controls map cleanly.
        $isLegacyFive = $version !== null && $this->majorVersion($version) === 5;

        foreach (self::CONTENT_SHEETS as $dimension => $sheetNames) {
            $worksheet = $this->resolveSheetByNames($spreadsheet, $sheetNames);
            if ($worksheet === null) {
                continue; // dimension not present in this workbook
            }

            try {
                [$headerRow, $headerMap] = $this->detectHeader($worksheet);
            } catch (ErrorException $e) {
                // A present-but-headerless sheet must not abort the whole import.
                $this->logger->warning('VdaIsaWorkbookParser: skipping sheet without header', [
                    'sheet' => $worksheet->getTitle(), 'dimension' => $dimension, 'reason' => $e->getMessage(),
                ]);
                continue;
            }

            $normaliseTwoPartIds = $isLegacyFive && $dimension === 'data_protection';
            $rows = $this->extractControlRows($worksheet, $headerRow, $headerMap, $dimension, $normaliseTwoPartIds);
            foreach ($rows as $r) {
                $controls[] = $r;
            }
            $parsedSheets[] = $worksheet->getTitle();
            if ($firstHeaderRow === 0) {
                $firstHeaderRow = $headerRow;
                $firstHeaderMap = $headerMap;
            }
        }

        // Fallback for legacy / non-standard workbooks that do not use the three
        // canonical sheet names — parse a single best-guess sheet as before.
        if ($controls === []) {
            $worksheet = $this->resolveSheet($spreadsheet);
            [$firstHeaderRow, $firstHeaderMap] = $this->detectHeader($worksheet);
            $controls     = $this->extractControlRows($worksheet, $firstHeaderRow, $firstHeaderMap, 'information_security');
            $parsedSheets = [$worksheet->getTitle()];
        }

        $company = $this->extractCompany($spreadsheet);

        $this->logger->info('VdaIsaWorkbookParser: parsed workbook', [
            'file'    => basename($filePath),
            'sheets'  => $parsedSheets,
            'headers' => array_keys($firstHeaderMap),
            'rows'    => count($controls),
            'company' => $company,
        ]);

        return new ParsedWorkbookResult(
            controls: $controls,
            sheetName: implode(', ', $parsedSheets),
            headerRowIndex: $firstHeaderRow,
            detectedColumnMap: $firstHeaderMap,
            workbookCompany: $company,
            workbookVersion: $version,
        );
    }

    /**
     * Cover sheets that carry the "Version: X.Y.Z | date" field.
     */
    private const VERSION_SHEETS = ['Deckblatt', 'Cover'];

    /**
     * Read the VDA-ISA version from the cover sheet "Version:" field
     * (e.g. "Version: 6.0.2 | 2024-04-04" → "6.0.2"). Returns null when no
     * recognisable version token is found.
     */
    private function detectVersion(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): ?string
    {
        foreach (self::VERSION_SHEETS as $sheetName) {
            $ws = $this->resolveSheetByNames($spreadsheet, [$sheetName]);
            if ($ws === null) {
                continue;
            }

            $highestRow = min(30, $ws->getHighestDataRow());
            $highestCol = $ws->getHighestDataColumn();

            for ($row = 1; $row <= $highestRow; $row++) {
                $cells = $ws->rangeToArray('A' . $row . ':' . $highestCol . $row, null, true, false, false)[0] ?? [];
                foreach ($cells as $cell) {
                    $text = trim((string) ($cell ?? ''));
                    if ($text === '') {
                        continue;
                    }
                    // "Version: 6.0.2 | 2024-04-04" / "Version: 5.1 | 04/27/2022"
                    if (preg_match('/version[:\s]+(\d+\.\d+(?:\.\d+)?)/i', $text, $m) === 1) {
                        return $m[1];
                    }
                }
            }
        }

        // ISA 4.x has no "Version:" cover field but a tell-tale sheet layout:
        // parenthesised module numbers ("Data protection (24)", "Prototype
        // protection (25)") and a "Connection to 3rd parties" module dropped in
        // later versions. Recognise it so the version gate can reject it cleanly
        // instead of failing later with a cryptic "no header row" error.
        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            if (preg_match('/protection \(2[45]\)|connection to 3rd parties/i', $sheetName) === 1) {
                return '4.1';
            }
        }

        return null;
    }

    /**
     * Resolve the first worksheet whose title matches one of $names
     * (case-insensitive). Returns null when none is present.
     *
     * @param list<string> $names
     */
    private function resolveSheetByNames(
        \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet,
        array $names,
    ): ?Worksheet {
        foreach ($names as $name) {
            foreach ($spreadsheet->getSheetNames() as $sheetName) {
                if (strcasecmp($sheetName, $name) === 0) {
                    return $spreadsheet->getSheetByName($sheetName);
                }
            }
        }
        return null;
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
     * Major version number from a "X.Y.Z" string (e.g. "5.1" → 5).
     */
    private function majorVersion(string $version): int
    {
        return (int) explode('.', $version)[0];
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
     * @param string             $dimension  source-sheet dimension, stamped on
     *                                        every row (authoritative tier).
     * @param bool $normaliseTwoPartIds  when true (ISA 5.x Data Protection),
     *                                    two-part control ids (9.1) are kept and
     *                                    upgraded to their 6.x leaf form (9.1.1).
     * @return list<VdaIsaControlRow>
     */
    private function extractControlRows(
        Worksheet $worksheet,
        int $headerRowIndex,
        array $headerMap,
        string $dimension = 'information_security',
        bool $normaliseTwoPartIds = false,
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

            // Skip section/subsection IDs (bare integers or two-part IDs like
            // "1", "1.1", "8"). Real control IDs have at least 2 dots:
            // "1.1.1", "8.2.3", "9.5.1". Two-part IDs like "1.1" are subsection
            // headings, not leaf controls — EXCEPT in ISA 5.x Data Protection,
            // where the leaf controls themselves are two-part (9.1 … 9.4). The
            // ENX "Row_Format = header" section rows were already dropped above,
            // so a surviving two-part DP id here is a genuine leaf → upgrade it
            // to the 6.x form (9.1 → 9.1.1) so it maps onto the ISA-6 framework.
            $dotCount = substr_count($controlId, '.');
            if ($normaliseTwoPartIds) {
                if ($dotCount < 1) {
                    continue; // bare section number ("9")
                }
                if ($dotCount === 1) {
                    $controlId .= '.1'; // 9.1 → 9.1.1
                }
            } elseif ($dotCount < 2) {
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
            // The workbook data-validation domain is {na, n.a., 0-5} for IS/PP
            // and {na, 0-5} OR {na, OK, Nicht OK} for Data Protection. We keep
            // the verbatim value in $maturityRawValue (so the DP tristate is not
            // lost) and only mirror a numeric 0-5 into $maturityCurrent.
            $maturityCurrent  = null;
            $maturityRawValue = $this->sanitizeCellValue(trim((string) $get('maturityCurrent')));
            if ($maturityRawValue !== '' && is_numeric($maturityRawValue)) {
                $maturityInt = (int) $maturityRawValue;
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
                dimension: $dimension,
                implementationDescription: ($v = $this->sanitizeCellValue(trim((string) $get('implementationDescription')))) !== '' ? $v : null,
                referenceDocumentation: ($v = $this->sanitizeCellValue(trim((string) $get('referenceDocumentation')))) !== '' ? $v : null,
                maturityRaw: $maturityRawValue !== '' ? $maturityRawValue : null,
                sgaLevel: ($v = $this->sanitizeCellValue(trim((string) $get('sgaLevel')))) !== '' ? $v : null,
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
