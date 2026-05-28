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
 * Re-run whenever ENX releases a new workbook version and verify DE=EN consistency.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

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
$SHEET_CATEGORY_MAP = [
    'IS Controls'         => 'information_security',
    'Information Security' => 'information_security',
    'PP Controls'         => 'prototype_protection',
    'Prototype Protection' => 'prototype_protection',
    'DP Controls'         => 'data_protection',
    'Data Protection'     => 'data_protection',
    'IS Kontrollen'       => 'information_security',
    'Informationssicherheit' => 'information_security',
    'PP Kontrollen'       => 'prototype_protection',
    'Prototypenschutz'    => 'prototype_protection',
    'DS Kontrollen'       => 'data_protection',
    'Datenschutz'         => 'data_protection',
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

        $maxRow      = $sheet->getHighestRow();
        $headerRow   = null;
        $colControlId = null;
        $colMust      = null;
        $colShould    = null;
        $colHigh      = null;
        $colVeryHigh  = null;
        $colIsoRef    = null;

        // Find header row (rows 1-5)
        for ($row = 1; $row <= min(5, $maxRow); $row++) {
            for ($col = 1; $col <= 20; $col++) {
                $cellVal = (string) $sheet->getCellByColumnAndRow($col, $row)->getValue();
                $lower   = strtolower(trim(str_replace("\xc2\xa0", ' ', $cellVal)));

                if (in_array($lower, ['control no.', 'no.', 'kontrollnr.', 'lfd. nr.', 'control no'], true)) {
                    $headerRow    = $row;
                    $colControlId = $col;
                }
                if (in_array($lower, ['must', 'muss'], true)) {
                    $colMust = $col;
                }
                if (in_array($lower, ['should', 'soll'], true)) {
                    $colShould = $col;
                }
                if (str_contains($lower, 'high') && !str_contains($lower, 'very')) {
                    $colHigh = $col;
                }
                if (str_contains($lower, 'very high') || str_contains($lower, 'sehr hoch')) {
                    $colVeryHigh = $col;
                }
                if (
                    str_contains($lower, 'reference to other standards')
                    || str_contains($lower, 'verweisung auf andere normen')
                    || str_contains($lower, 'iso 27001')
                    || str_contains($lower, 'referenz')
                ) {
                    $colIsoRef = $col;
                }
            }
            if ($headerRow !== null) {
                break;
            }
        }

        if ($headerRow === null || $colControlId === null) {
            continue;
        }

        for ($row = $headerRow + 1; $row <= $maxRow; $row++) {
            $controlId = trim((string) $sheet->getCellByColumnAndRow($colControlId, $row)->getValue());
            $bare      = ltrim($controlId, 'ISA ');

            if (!preg_match('/^\d+\.\d+\.\d+$/', $bare)) {
                continue;
            }

            $controlId = 'ISA ' . $bare;

            $maturity = [];
            foreach ([
                $colMust     => 'must',
                $colShould   => 'should',
                $colHigh     => 'high',
                $colVeryHigh => 'very_high',
            ] as $col => $level) {
                if ($col !== null) {
                    $v = trim((string) $sheet->getCellByColumnAndRow($col, $row)->getValue());
                    if ($v !== '' && $v !== '0') {
                        $maturity[] = $level;
                    }
                }
            }

            $targets = [];
            if ($colIsoRef !== null) {
                $rawRef  = (string) $sheet->getCellByColumnAndRow($colIsoRef, $row)->getValue();
                $targets = parseIso22Anchors($rawRef);
            }

            $results[$controlId] = [
                'source'    => $controlId,
                'targets'   => $targets,
                'category'  => $category,
                'maturity'  => $maturity ?: ['must'],
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
