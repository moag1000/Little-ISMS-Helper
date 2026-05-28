<?php

/**
 * Extract VDA-ISA 6 → ISO 27001:2022 anchor mapping from official ENX workbook.
 *
 * LEGAL NOTE: Extracts ONLY control-ID + ISO 27001 reference column pairs.
 * Question-text, objectives, evidence hints are NOT extracted (ENX-licensed).
 *
 * Usage:
 *   php scripts/import/extract_vda_isa_iso_mapping.php /path/to/vda_isa_6_en.xlsx
 *   php scripts/import/extract_vda_isa_iso_mapping.php /path/to/vda_isa_6_en.xlsx \
 *       --output=fixtures/library/mappings/tisax-vda-isa-6_to_iso27001-2022_v1.0.yaml
 *   php scripts/import/extract_vda_isa_iso_mapping.php /path/to/vda_isa_6_en.xlsx \
 *       --check-de --de=/path/to/vda_isa_6_de.xlsx
 *
 * VDA-ISA 6 workbook column layout (EN):
 *   A = Row_Format  B = Is_Title?  C = Control number  D = Maturity level
 *   J = Requirements (must)        K = Requirements (should)
 *   L = Additional requirements for high protection needs
 *   M = Additional requirements for very high protection needs
 *   P = Reference to other standards  (ISO 27001:2022 anchors live here)
 *
 * Re-run whenever ENX releases a new workbook version.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

// ─── CLI argument parsing ────────────────────────────────────────────────────

$args    = array_slice($argv, 1);
$xlsx    = null;
$output  = null;
$checkDe = false;
$deXlsx  = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $output = substr($arg, 9);
    } elseif ($arg === '--check-de') {
        $checkDe = true;
    } elseif (str_starts_with($arg, '--de=')) {
        $deXlsx = substr($arg, 5);
    } elseif (!str_starts_with($arg, '-')) {
        $xlsx = $arg;
    }
}

if ($xlsx === null) {
    fwrite(STDERR, "Usage: php extract_vda_isa_iso_mapping.php <path.xlsx> [--output=out.yaml] [--check-de] [--de=path_de.xlsx]\n");
    exit(1);
}

if (!file_exists($xlsx)) {
    fwrite(STDERR, "Error: File not found: $xlsx\n");
    exit(1);
}

// ─── Sheet → category map (EN and DE variants) ──────────────────────────────
/** @var array<string, string> */
$SHEET_CATEGORY_MAP = [
    'Information Security'   => 'information_security',
    'Informationssicherheit' => 'information_security',
    'IS Controls'            => 'information_security',
    'IS Kontrollen'          => 'information_security',
    'Prototype Protection'   => 'prototype_protection',
    'Prototypenschutz'       => 'prototype_protection',
    'PP Controls'            => 'prototype_protection',
    'PP Kontrollen'          => 'prototype_protection',
    'Data Protection'        => 'data_protection',
    'Datenschutz'            => 'data_protection',
    'DP Controls'            => 'data_protection',
    'DS Kontrollen'          => 'data_protection',
];

// ─── Parse ISO 27001:2022 anchors from raw reference cell ───────────────────
/**
 * @return list<string>
 */
function parseIso22Anchors(string $rawRef): array
{
    if (trim($rawRef) === '') {
        return [];
    }

    // Critical: DE workbook uses U+00A0 (non-breaking space) — causes regex failure
    $raw = str_replace(["\r\n", "\xc2\xa0"], ["\n", ' '], $rawRef);

    $block = null;

    // EN variant: "ISO 27001:2022:" or "ISO27001:2022:"
    if (preg_match('/ISO\s*27001:2022:\s*([^\n]*(?:\n(?![A-Z\d])[^\n]*)*)/i', $raw, $m)) {
        $block = $m[1];
    } elseif (preg_match('/Reference to ISO\s*27001:\s*([^\n]*(?:\n(?![A-Z\d])[^\n]*)*)/i', $raw, $m)) {
        $block = $m[1];
    } elseif (preg_match('/Verweisung auf ISO\s*27001:\s*([^\n]*(?:\n(?![A-Z\d])[^\n]*)*)/i', $raw, $m)) {
        $block = $m[1];
    }

    if ($block === null) {
        return [];
    }

    // Strip trailing framework references
    $block = (string) preg_replace('/\n?(ISO\s*27002|ISO\s*27017|ISA\/IEC|NIST|BSI|IEC\s*62443)[:\s].*/is', '', $block);

    $anchors = [];
    $parts   = preg_split('/[\s,;\/]+/', $block) ?: [];

    foreach ($parts as $part) {
        $part = trim((string) $part, " \t\n\r\0\x0B.,;");

        if ($part === '' || $part === '-') {
            continue;
        }

        // Skip 4-part ISO 27001:2013-era refs like A.9.2.3
        if (preg_match('/^A\.\d+\.\d+\.\d+$/', $part)) {
            continue;
        }

        // Skip stray 3-part numerics like 3.1.10
        if (preg_match('/^\d+\.\d+\.\d+$/', $part)) {
            continue;
        }

        // Standard anchor: A.5.1 or A.8.34
        if (preg_match('/^A\.(\d+)\.(\d+)$/', $part)) {
            $anchors[] = $part;
            continue;
        }

        // Range: A.5.24-27 → A.5.24, A.5.25, A.5.26, A.5.27
        if (preg_match('/^A\.(\d+)\.(\d+)-(\d+)$/', $part, $m)) {
            [, $section, $start, $end] = $m;
            for ($i = (int) $start; $i <= (int) $end; $i++) {
                $anchors[] = "A.$section.$i";
            }
            continue;
        }

        // Bare clause: just "5.1" → "A.5.1" (sections 5-8 only)
        if (preg_match('/^(\d+)\.(\d+)$/', $part, $m)) {
            $section = (int) $m[1];
            if ($section >= 5 && $section <= 8) {
                $anchors[] = "A.$section.$m[2]";
            }
            continue;
        }
    }

    return array_values(array_unique($anchors));
}

// ─── Load and extract from workbook ─────────────────────────────────────────
/**
 * Scan all rows in each IS/PP/DP sheet. Rows where column C matches
 * "X.Y.Z" or "ISA X.Y.Z" are control rows. Column P has the ISO ref.
 *
 * @param array<string, string> $catMap
 * @return array<string, array{source: string, targets: list<string>, category: string, maturity: list<string>, rationale: string}>
 */
function loadAndExtract(string $path, array $catMap): array
{
    $spreadsheet = IOFactory::load($path);
    $results     = [];

    foreach ($spreadsheet->getAllSheets() as $sheet) {
        $title    = $sheet->getTitle();
        $category = null;

        foreach ($catMap as $pattern => $cat) {
            if (stripos($title, $pattern) !== false) {
                $category = $cat;
                break;
            }
        }

        if ($category === null) {
            continue;
        }

        $maxRow = $sheet->getHighestRow();

        // Dynamic column detection from header row (row 2)
        // Fallback to hardcoded positions if header detection fails.
        $colControlId = 3; // C
        $colMust      = 10; // J
        $colShould    = 11; // K
        $colHigh      = 12; // L
        $colVeryHigh  = 13; // M
        $colIsoRef    = 16; // P

        // Try to detect actual columns from row 2 header
        for ($c = 1; $c <= 20; $c++) {
            $hdr = strtolower(trim(str_replace("\xc2\xa0", ' ', (string) $sheet->getCell(Coordinate::stringFromColumnIndex($c) . '2')->getValue())));
            if (str_contains($hdr, 'control number') || str_contains($hdr, 'control no') || str_contains($hdr, 'kontrollnr')) {
                $colControlId = $c;
            }
            if (in_array($hdr, ['requirements\n(must)', 'must', 'muss'], true) || (str_contains($hdr, 'must') && !str_contains($hdr, 'additional'))) {
                $colMust = $c;
            }
            if (in_array($hdr, ['requirements\n(should)', 'should', 'soll'], true) || (str_contains($hdr, 'should') && !str_contains($hdr, 'additional'))) {
                $colShould = $c;
            }
            if (str_contains($hdr, 'high protection') && !str_contains($hdr, 'very high')) {
                $colHigh = $c;
            }
            if (str_contains($hdr, 'very high')) {
                $colVeryHigh = $c;
            }
            if (str_contains($hdr, 'reference to other standard') || str_contains($hdr, 'verweisung auf andere normen')) {
                $colIsoRef = $c;
            }
        }

        for ($row = 1; $row <= $maxRow; $row++) {
            $rawId = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($colControlId) . $row)->getValue());

            // Normalise: strip "ISA " prefix if present
            $bareId = ltrim($rawId, 'ISA ');
            $bareId = preg_replace('/^ISA\s+/', '', $bareId) ?? $bareId;

            // Only accept X.Y.Z pattern (3-part, all digits)
            if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $bareId)) {
                continue;
            }

            $controlId = 'ISA ' . $bareId;

            // Maturity: check if requirement text is non-empty
            $maturity = [];
            $mustText = (string) $sheet->getCell(Coordinate::stringFromColumnIndex($colMust) . $row)->getValue();
            if (trim($mustText) !== '' && !str_contains($mustText, 'intentional invisible')) {
                $maturity[] = 'must';
            }
            $shouldText = (string) $sheet->getCell(Coordinate::stringFromColumnIndex($colShould) . $row)->getValue();
            if (trim($shouldText) !== '' && !str_contains($shouldText, 'intentional invisible')) {
                $maturity[] = 'should';
            }
            $highText = (string) $sheet->getCell(Coordinate::stringFromColumnIndex($colHigh) . $row)->getValue();
            if (trim($highText) !== '' && strtolower(trim($highText)) !== 'none' && !str_contains($highText, 'intentional invisible')) {
                $maturity[] = 'high';
            }
            $vhText = (string) $sheet->getCell(Coordinate::stringFromColumnIndex($colVeryHigh) . $row)->getValue();
            if (trim($vhText) !== '' && strtolower(trim($vhText)) !== 'none' && !str_contains($vhText, 'intentional invisible')) {
                $maturity[] = 'very_high';
            }

            if (empty($maturity)) {
                $maturity = ['must'];
            }

            // ISO 27001:2022 anchors
            $rawRef  = (string) $sheet->getCell(Coordinate::stringFromColumnIndex($colIsoRef) . $row)->getValue();
            $targets = parseIso22Anchors($rawRef);

            $results[$controlId] = [
                'source'    => $controlId,
                'targets'   => $targets,
                'category'  => $category,
                'maturity'  => $maturity,
                'rationale' => 'ISO 27001:2022 anchor per ENX ISA 6 Reference column (authoritative).',
            ];
        }
    }

    return $results;
}

// ─── Main extraction ─────────────────────────────────────────────────────────
fwrite(STDERR, "Loading EN workbook: $xlsx\n");
$enMappings = loadAndExtract($xlsx, $SHEET_CATEGORY_MAP);
$totalCount = count($enMappings);
fwrite(STDERR, "Extracted $totalCount controls from EN workbook.\n");

// ─── DE consistency check ────────────────────────────────────────────────────
if ($checkDe && $deXlsx !== null) {
    if (!file_exists($deXlsx)) {
        fwrite(STDERR, "Warning: DE file not found: $deXlsx — skipping DE check\n");
    } else {
        fwrite(STDERR, "Loading DE workbook: $deXlsx\n");
        $deMappings  = loadAndExtract($deXlsx, $SHEET_CATEGORY_MAP);
        $divergences = 0;

        foreach ($enMappings as $id => $entry) {
            $enTargets = $entry['targets'];
            $deTargets = $deMappings[$id]['targets'] ?? [];
            sort($enTargets);
            sort($deTargets);

            if ($enTargets !== $deTargets) {
                fwrite(STDERR, "  DIVERGE $id: EN=" . implode(',', $enTargets) . " DE=" . implode(',', $deTargets) . "\n");
                $divergences++;
            }
        }

        fwrite(STDERR, $divergences === 0
            ? "Consistency check: PASS (DE=EN)\n"
            : "Consistency check: FAIL ($divergences divergences)\n");

        if ($divergences > 0) {
            exit(2);
        }
    }
}

// ─── Build YAML output ───────────────────────────────────────────────────────
$withTargets    = array_filter($enMappings, fn($e) => !empty($e['targets']));
$withoutTargets = array_filter($enMappings, fn($e) => empty($e['targets']));
$today          = date('Y-m-d');

$yaml  = "# -----------------------------------------------------------------\n";
$yaml .= "# AUTHORITATIVE MAPPING -- extracted from ENX VDA-ISA 6 workbook\n";
$yaml .= "# reference columns (control-ID + ISO 27001 anchor pairs only).\n";
$yaml .= "# Question-text NOT redistributed (ENX-licensed).\n";
$yaml .= "# Generated: $today. Re-run: scripts/import/extract_vda_isa_iso_mapping.php\n";
$yaml .= "# -----------------------------------------------------------------\n";
$yaml .= "schema_version: '1.1'\n";
$yaml .= "library:\n";
$yaml .= "  type: mapping\n";
$yaml .= "  id: 'tisax-vda-isa-6_to_iso27001-2022_v1.0'\n";
$yaml .= "  source_framework: 'TISAX'\n";
$yaml .= "  target_framework: 'ISO27001'\n";
$yaml .= "  version: 1\n";
$yaml .= "  effective_from: '2024-04-01'\n";
$yaml .= "  effective_until: null\n\n";
$yaml .= "  provenance:\n";
$yaml .= "    primary_source: 'VDA ISA Version 6.0 — Reference column (authoritative ENX source)'\n";
$yaml .= "    primary_source_url: 'https://www.vda.de/de/themen/sicherheit-und-standards/informationssicherheit/information-security-assessment'\n";
$yaml .= "    confidence: 'authoritative_enx_source'\n";
$yaml .= "    publisher: 'Little ISMS Helper Maintainers'\n";
$yaml .= "    extracted: '$today'\n\n";
$yaml .= "  methodology:\n";
$yaml .= "    type: 'published_official_mapping'\n";
$yaml .= "    description: |\n";
$yaml .= "      Mapping extracted from ENX VDA-ISA 6 workbook reference column.\n";
$yaml .= "      Only control-ID and ISO 27001:2022 Annex A anchors extracted.\n";
$yaml .= "      PP/DP controls have no ISO 27001 anchors by design.\n\n";
$yaml .= "  lifecycle:\n";
$yaml .= "    state: 'published'\n";
$yaml .= "    state_history:\n";
$yaml .= "      - { state: 'published', date: '$today', actor: 'maintainer' }\n\n";
$yaml .= "  stats:\n";
$yaml .= "    total_vda_isa_controls: $totalCount\n";
$yaml .= "    controls_with_iso27001_anchors: " . count($withTargets) . "\n";
$yaml .= "    controls_without_iso27001_anchors: " . count($withoutTargets) . "\n\n";
$yaml .= "mappings:\n";

foreach ($enMappings as $entry) {
    $targets = $entry['targets'];
    $maturity = $entry['maturity'];

    $targetsStr = empty($targets)
        ? '[]'
        : '[' . implode(', ', array_map(fn($t) => "'$t'", $targets)) . ']';

    $maturityStr = '[' . implode(', ', array_map(fn($m) => "'$m'", $maturity)) . ']';

    $yaml .= "  - source: '{$entry['source']}'\n";
    $yaml .= "    targets: $targetsStr\n";
    $yaml .= "    category: '{$entry['category']}'\n";
    $yaml .= "    maturity: $maturityStr\n";
    $yaml .= "    rationale: '{$entry['rationale']}'\n";
}

if ($output !== null) {
    file_put_contents($output, $yaml);
    fwrite(STDERR, "Written to: $output\n");
} else {
    echo $yaml;
}
