<?php
/**
 * VDA-ISA 6 Requirement-Level Metadata Extractor
 *
 * Reads an ENX-licensed VDA-ISA 6 workbook and extracts per-control
 * PRESENCE FLAGS for columns J/K/L/M (Must/Sollte/High/VeryHigh).
 *
 * LEGAL NOTE:
 *   Only boolean presence-flags and cell-length proxies are written.
 *   NO actual cell text is extracted — actual requirement text is
 *   ENX-licensed creative content. Customers must read the text from
 *   their own licensed workbook copy.
 *
 * Output: fixtures/library/metadata/tisax-vda-isa-6_requirement_levels_v1.0.yaml
 *
 * Usage:
 *   php scripts/import/extract_vda_isa_requirement_metadata.php /path/to/vda_isa_6_en.xlsx
 *   php scripts/import/extract_vda_isa_requirement_metadata.php /path/to/vda_isa_6_de.xlsx
 *
 * Idempotent: safe to re-run, overwrites output file.
 *
 * Column mapping (verified against real ENX ISA 6.0.3 EN + DE workbooks):
 *   Col J — Requirements (must)         / Anforderungen (muss)
 *   Col K — Requirements (should)       / Anforderungen (sollte)
 *   Col L — Additional requirements for high protection needs    / Zusatzanforderungen bei hohem Schutzbedarf
 *   Col M — Additional requirements for very high protection needs / Zusatzanforderungen bei sehr hohem Schutzbedarf
 *
 * Assessment Level derivation (factual structural logic, not licensed text):
 *   AL1 — controls where Must (J) is populated
 *   AL2 — controls where Sollte (K) is populated (implies Must)
 *   AL3 — controls where High (L) is populated (implies Must + Sollte)
 *   Very-high addendum — controls where VeryHigh (M) is populated
 */
declare(strict_types=1);

foreach ([__DIR__ . '/../../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $p) {
    if (file_exists($p)) {
        require_once $p;
        break;
    }
}

use PhpOffice\PhpSpreadsheet\IOFactory;

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php " . $argv[0] . " <path/to/vda_isa_6.xlsx> [--output=<path>]\n");
    fwrite(STDERR, "Example: php " . $argv[0] . " /path/to/isa6-en.xlsx\n");
    exit(1);
}

$xlsxPath = $argv[1];

// Parse --output option
$outputPath = null;
for ($i = 2; $i < $argc; $i++) {
    if (str_starts_with($argv[$i], '--output=')) {
        $outputPath = substr($argv[$i], 9);
    }
}

if ($outputPath === null) {
    // Default output path relative to project root (2 levels up from scripts/import/)
    $outputPath = __DIR__ . '/../../fixtures/library/metadata/tisax-vda-isa-6_requirement_levels_v1.0.yaml';
}

if (!file_exists($xlsxPath)) {
    fwrite(STDERR, "File not found: $xlsxPath\n");
    exit(1);
}

echo "Loading workbook: $xlsxPath\n";

$reader = IOFactory::createReaderForFile($xlsxPath);
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load($xlsxPath);

// Sheet name resolution — try preferred names (EN + DE)
$preferredSheets = [
    'Information Security',
    'Informationssicherheit',
    'Prototype Protection',
    'Prototypenschutz',
    'ISA', 'VDA-ISA', 'ISA 6',
];

$worksheet = null;
foreach ($preferredSheets as $name) {
    foreach ($spreadsheet->getSheetNames() as $sheetName) {
        if (strcasecmp($sheetName, $name) === 0) {
            $worksheet = $spreadsheet->getSheetByName($sheetName);
            echo "Using sheet: $sheetName\n";
            break 2;
        }
    }
}
if ($worksheet === null) {
    $worksheet = $spreadsheet->getActiveSheet();
    echo "Fallback to active sheet: " . $worksheet->getTitle() . "\n";
}

$highestRow = $worksheet->getHighestDataRow();
$highestCol = $worksheet->getHighestDataColumn();
echo "Sheet dimensions: row=1..{$highestRow}, col=A..{$highestCol}\n";

// ─── Header detection ────────────────────────────────────────────────────────
// Scan first 40 rows for a row that contains 'control number' / 'kontrollnummer'
// AND one of the must/muss column aliases.

$mustAliases     = ['must)', '(muss)', 'must', 'muss', 'pflicht'];
$shouldAliases   = ['should)', '(sollte)', 'should', 'sollte'];
$highAliases     = ['for high protection', 'bei hohem schutzbedarf', 'high protection', 'hoher schutzbedarf'];
$veryHighAliases = ['for very high', 'bei sehr hohem', 'very high', 'sehr hoch'];
$idAliases       = ['control number', 'kontrollnummer', 'control no', 'nr.', 'nummer', 'nr'];

$headerRow = null;
$colMap    = [];

for ($row = 1; $row <= min(40, $highestRow); $row++) {
    $rowData = $worksheet->rangeToArray('A' . $row . ':' . $highestCol . $row, null, true, false, false)[0];
    $lower   = array_map(static fn (mixed $v): string => strtolower((string) ($v ?? '')), $rowData);

    $hasId   = false;
    $hasMust = false;
    foreach ($lower as $cell) {
        foreach ($idAliases as $a) {
            if (str_contains($cell, $a)) {
                $hasId = true;
                break;
            }
        }
        foreach ($mustAliases as $a) {
            if (str_contains($cell, $a)) {
                $hasMust = true;
                break;
            }
        }
    }

    if ($hasId && $hasMust) {
        // Build column map
        foreach ($lower as $colIdx => $cell) {
            if ($cell === '') {
                continue;
            }
            foreach ($idAliases as $a) {
                if (str_contains($cell, $a) && !isset($colMap['controlId'])) {
                    $colMap['controlId'] = $colIdx;
                }
            }
            foreach ($mustAliases as $a) {
                if (str_contains($cell, $a) && !isset($colMap['must'])) {
                    $colMap['must'] = $colIdx;
                }
            }
            foreach ($shouldAliases as $a) {
                if (str_contains($cell, $a) && !isset($colMap['should'])) {
                    $colMap['should'] = $colIdx;
                }
            }
            foreach ($highAliases as $a) {
                if (str_contains($cell, $a) && !isset($colMap['high'])) {
                    $colMap['high'] = $colIdx;
                }
            }
            foreach ($veryHighAliases as $a) {
                if (str_contains($cell, $a) && !isset($colMap['veryHigh'])) {
                    $colMap['veryHigh'] = $colIdx;
                }
            }
        }
        $headerRow = $row;
        echo "Header found at row $row. Column map: " . json_encode($colMap) . "\n";
        break;
    }
}

if ($headerRow === null) {
    fwrite(STDERR, "ERROR: No VDA-ISA header row found in first 40 rows.\n");
    exit(1);
}

// ─── Row extraction ──────────────────────────────────────────────────────────
// LEGAL: Only boolean presence-flag + strlen (rough proxy). NO text captured.

$controls = [];
$MIN_TEXT_LEN = 5; // minimum chars to treat a cell as "has content"

for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
    $rowData = $worksheet->rangeToArray('A' . $row . ':' . $highestCol . $row, null, true, false, false)[0];

    // Skip ENX "header" row-format rows
    $rowFormat = strtolower(trim((string) ($rowData[0] ?? '')));
    if ($rowFormat === 'header') {
        continue;
    }

    $controlId = trim((string) ($rowData[$colMap['controlId'] ?? -1] ?? ''));
    if ($controlId === '' || substr_count($controlId, '.') < 2) {
        continue; // skip blanks + section headers (e.g. "1", "1.1")
    }

    if (!preg_match('/^\d+\.\d+\.\d+$/', $controlId)) {
        continue; // non-standard IDs
    }

    $getCellLen = static function (int $colIdx) use ($rowData): int {
        return strlen(trim((string) ($rowData[$colIdx] ?? '')));
    };

    $mustLen     = isset($colMap['must'])     ? $getCellLen($colMap['must'])     : 0;
    $shouldLen   = isset($colMap['should'])   ? $getCellLen($colMap['should'])   : 0;
    $highLen     = isset($colMap['high'])     ? $getCellLen($colMap['high'])     : 0;
    $veryHighLen = isset($colMap['veryHigh']) ? $getCellLen($colMap['veryHigh']) : 0;

    $hasMust     = $mustLen     >= $MIN_TEXT_LEN;
    $hasShould   = $shouldLen   >= $MIN_TEXT_LEN;
    $hasHigh     = $highLen     >= $MIN_TEXT_LEN;
    $hasVeryHigh = $veryHighLen >= $MIN_TEXT_LEN;

    // Derive Assessment Levels:
    //   AL1 is applicable when Must is present
    //   AL2 is applicable when Sollte is present (implies Must for typical IS controls)
    //   AL3 is applicable when Hoher Schutzbedarf is present
    //   Very-high applies when column M is present
    $als = [];
    if ($hasMust) {
        $als[] = 'AL1';
    }
    if ($hasShould) {
        $als[] = 'AL2';
    }
    if ($hasHigh) {
        $als[] = 'AL3';
    }

    $protectionAddenda = [];
    if ($hasHigh) {
        $protectionAddenda[] = 'high';
    }
    if ($hasVeryHigh) {
        $protectionAddenda[] = 'very_high';
    }

    $controls[] = [
        'controlId'   => $controlId,
        'must'        => $hasMust,
        'should'      => $hasShould,
        'high'        => $hasHigh,
        'veryHigh'    => $hasVeryHigh,
        'als'         => $als,
        'addenda'     => $protectionAddenda,
        // Cell-length proxies (never the actual text — only approximate length)
        'mustLen'     => $mustLen,
        'shouldLen'   => $shouldLen,
        'highLen'     => $highLen,
        'veryHighLen' => $veryHighLen,
    ];
}

$totalControls  = count($controls);
$withMust       = count(array_filter($controls, static fn ($c) => $c['must']));
$withShould     = count(array_filter($controls, static fn ($c) => $c['should']));
$withHigh       = count(array_filter($controls, static fn ($c) => $c['high']));
$withVeryHigh   = count(array_filter($controls, static fn ($c) => $c['veryHigh']));
$al1Only        = count(array_filter($controls, static fn ($c) => $c['als'] === ['AL1']));
$al2            = count(array_filter($controls, static fn ($c) => in_array('AL2', $c['als'], true)));
$al3            = count(array_filter($controls, static fn ($c) => in_array('AL3', $c['als'], true)));

echo "\n=== Extraction Summary ===\n";
echo "  Total controls:    $totalControls\n";
echo "  With Must (J):     $withMust\n";
echo "  With Sollte (K):   $withShould\n";
echo "  With High (L):     $withHigh\n";
echo "  With VeryHigh (M): $withVeryHigh\n";
echo "  AL1 applicable:    " . count(array_filter($controls, static fn ($c) => in_array('AL1', $c['als'], true))) . "\n";
echo "  AL2 applicable:    $al2\n";
echo "  AL3 applicable:    $al3\n";

// ─── YAML generation ─────────────────────────────────────────────────────────

$today = date('Y-m-d');

$yaml  = "# VDA-ISA 6 Requirement-Level Metadata\n";
$yaml .= "#\n";
$yaml .= "# LEGAL NOTE: This file contains ONLY boolean presence-flags and cell-length\n";
$yaml .= "# proxies for columns J/K/L/M. NO actual requirement text is included.\n";
$yaml .= "# Actual text is ENX-licensed creative content — customers must read it\n";
$yaml .= "# from their own licensed workbook copy.\n";
$yaml .= "#\n";
$yaml .= "# Generated by: scripts/import/extract_vda_isa_requirement_metadata.php\n";
$yaml .= "# Source: ENX VDA-ISA 6 workbook\n";
$yaml .= "\n";
$yaml .= "metadata:\n";
$yaml .= "  source: 'vda-isa-tisax-v6'\n";
$yaml .= "  type: 'requirement-level-metadata'\n";
$yaml .= "  version: 1\n";
$yaml .= "  provenance:\n";
$yaml .= "    extracted_from: 'ENX VDA-ISA 6 workbook columns J/K/L/M'\n";
$yaml .= "    extraction_date: '{$today}'\n";
$yaml .= "    legal_note: |\n";
$yaml .= "      Cell EXISTENCE flags only — actual cell text is ENX-licensed\n";
$yaml .= "      and NOT included. Customers must read the text from their\n";
$yaml .= "      own licensed workbook copy.\n";
$yaml .= "  stats:\n";
$yaml .= "    total_controls: {$totalControls}\n";
$yaml .= "    with_must: {$withMust}\n";
$yaml .= "    with_should: {$withShould}\n";
$yaml .= "    with_high_protection: {$withHigh}\n";
$yaml .= "    with_very_high_protection: {$withVeryHigh}\n";
$yaml .= "    al1_applicable: " . count(array_filter($controls, static fn ($c) => in_array('AL1', $c['als'], true))) . "\n";
$yaml .= "    al2_applicable: {$al2}\n";
$yaml .= "    al3_applicable: {$al3}\n";
$yaml .= "\n";
$yaml .= "controls:\n";

foreach ($controls as $c) {
    $alList  = "['" . implode("', '", $c['als']) . "']";
    if ($c['als'] === []) {
        $alList = '[]';
    }
    $addList = count($c['addenda']) > 0
        ? "['" . implode("', '", $c['addenda']) . "']"
        : '[]';

    $yaml .= "  - controlId: '{$c['controlId']}'\n";
    $yaml .= "    levels:\n";
    $yaml .= "      must: "             . ($c['must']     ? 'true'  : 'false') . "\n";
    $yaml .= "      should: "           . ($c['should']   ? 'true'  : 'false') . "\n";
    $yaml .= "      high_protection: "  . ($c['high']     ? 'true'  : 'false') . "\n";
    $yaml .= "      very_high_protection: " . ($c['veryHigh'] ? 'true' : 'false') . "\n";
    // Cell-length proxies (NOT text — only length as a rough "coverage depth" proxy)
    $yaml .= "    cell_lengths:\n";
    $yaml .= "      must: "      . $c['mustLen']     . "\n";
    $yaml .= "      should: "    . $c['shouldLen']   . "\n";
    $yaml .= "      high: "      . $c['highLen']     . "\n";
    $yaml .= "      very_high: " . $c['veryHighLen'] . "\n";
    $yaml .= "    suggested_assessment_levels: {$alList}\n";
    $yaml .= "    protection_need_addenda: {$addList}\n";
}

// Write output
$outputDir = dirname($outputPath);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}
file_put_contents($outputPath, $yaml);

echo "\nOutput: $outputPath\n";
echo "Done. ({$totalControls} controls written)\n";
