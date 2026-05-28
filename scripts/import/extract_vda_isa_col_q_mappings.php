<?php
/**
 * Extract BSI IT-Grundschutz and NIST SP 800-53r5 anchor mappings from
 * VDA-ISA 6 column Q ("Verweisung auf Implementierungsanleitung").
 *
 * Anchor-ID formats:
 *  BSI IT-Grundschutz: XYZ.N.N (e.g. ISMS.1, ORP.4, NET.1.1, SYS.3.2.1, DER.2.3)
 *  NIST SP800-53r5:    XX-N, XX-N(N) (e.g. AC-1, IR-4(13), CM-8, SA-21)
 *  BSI 200-2 sections: N, N.N, N.N.N (e.g. 3.2, 4.1, 10.1.4) -- NOT extracted (ambiguous with VDA-ISA IDs)
 */
declare(strict_types=1);

require_once '/Users/michaelbanda/Nextcloud/www/Little-ISMS-Helper/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

if ($argc < 3) { fwrite(STDERR, "Usage: php $argv[0] <xlsx> <output-dir>\n"); exit(1); }
$xlsxPath = $argv[1]; $outputDir = rtrim($argv[2],'/');
if (!file_exists($xlsxPath)) { fwrite(STDERR, "File not found: $xlsxPath\n"); exit(1); }
if (!is_dir($outputDir)) { fwrite(STDERR, "Dir not found: $outputDir\n"); exit(1); }

echo "Loading: $xlsxPath\n";
$spreadsheet = IOFactory::load($xlsxPath);

// Patterns for anchor IDs in column Q
// BSI IT-Grundschutz Compendium: 3-4 uppercase letters + dots + digits  (e.g. ISMS.1, ORP.4, NET.1.2, SYS.3.2.1)
$bsiGsPattern = '/^[A-Z]{2,5}\.\d+(\.\d+)*$/';
// NIST SP800-53r5: 2 uppercase letters, hyphen, digits, optional (N) enhancement (e.g. AC-1, IR-4(13), PE-13(2))
$nistSp853Pattern = '/^[A-Z]{2,3}-\d+(\(\d+\))?$/';

$bsiData = [];  // [ctrl => [anchor, ...]]
$nistData = []; // [ctrl => [anchor, ...]]

$ISsheetNames = ['Informationssicherheit', 'Information Security'];

foreach ($spreadsheet->getSheetNames() as $sn) {
    $isIS = in_array($sn, $ISsheetNames, true);
    if (!$isIS) continue;

    $ws = $spreadsheet->getSheetByName($sn);
    $hr = $ws->getHighestRow();
    echo "  Sheet: $sn ($hr rows)\n";

    for ($row = 3; $row <= $hr; $row++) {
        // Column C = control ID
        $ctrl = str_replace("\xc2\xa0", ' ', trim((string)$ws->getCell('C'.$row)->getValue()));
        // Column Q = Verweisung auf Implementierungsanleitung
        $qVal = str_replace("\xc2\xa0", ' ', trim((string)$ws->getCell('Q'.$row)->getValue()));

        if (!$ctrl || !$qVal) continue;
        if (!preg_match('/^\d+\.\d+(\.\d+)?$/', $ctrl)) continue; // must be a control ID

        // Parse column Q: split by newlines to get sections
        $lines = preg_split('/\r?\n/', $qVal);
        $currentSection = null;
        foreach ($lines as $line) {
            $line = str_replace("\xc2\xa0", ' ', trim($line));
            if ($line === '') continue;

            // Detect section headers
            if (str_starts_with($line, 'BSI-Standard 200-2:')) {
                $currentSection = 'bsi200'; // numeric sections, skip
                $rest = ltrim(substr($line, strlen('BSI-Standard 200-2:')));
                // skip these — numeric-only IDs ambiguous with VDA-ISA ctrl numbers
                continue;
            }
            if (str_starts_with($line, 'BSI IT-Grundschutz-Compendium:')) {
                $currentSection = 'bsiGs';
                $rest = ltrim(substr($line, strlen('BSI IT-Grundschutz-Compendium:')));
                if ($rest) {
                    foreach (preg_split('/,\s*/', $rest) as $part) {
                        $part = trim($part);
                        if ($part && preg_match($bsiGsPattern, $part)) {
                            $bsiData[$ctrl][] = $part;
                        }
                    }
                }
                continue;
            }
            if (str_starts_with($line, 'NIST SP800-53r5:') || str_starts_with($line, 'NIST SP 800-53r5:')) {
                $currentSection = 'nistSp';
                $prefix = str_starts_with($line, 'NIST SP800-53r5:') ? 'NIST SP800-53r5:' : 'NIST SP 800-53r5:';
                $rest = ltrim(substr($line, strlen($prefix)));
                if ($rest) {
                    foreach (preg_split('/,\s*/', $rest) as $part) {
                        $part = trim($part);
                        if ($part && preg_match($nistSp853Pattern, $part)) {
                            $nistData[$ctrl][] = $part;
                        }
                    }
                }
                continue;
            }

            // Continuation lines (no section header)
            if ($currentSection === 'bsiGs') {
                foreach (preg_split('/,\s*/', $line) as $part) {
                    $part = trim($part);
                    if ($part && preg_match($bsiGsPattern, $part)) {
                        $bsiData[$ctrl][] = $part;
                    }
                }
            } elseif ($currentSection === 'nistSp') {
                foreach (preg_split('/,\s*/', $line) as $part) {
                    $part = trim($part);
                    if ($part && preg_match($nistSp853Pattern, $part)) {
                        $nistData[$ctrl][] = $part;
                    }
                }
            }
        }
    }
    break; // first matching sheet
}

// Deduplicate and sort
foreach ($bsiData as $ctrl => &$aa) { $aa = array_values(array_unique($aa)); sort($aa); } unset($aa);
foreach ($nistData as $ctrl => &$aa) { $aa = array_values(array_unique($aa)); sort($aa); } unset($aa);
ksort($bsiData); ksort($nistData);

// Stats
$bsiAnchors = array_sum(array_map('count', $bsiData));
$nistAnchors = array_sum(array_map('count', $nistData));
echo "\nBSI IT-Grundschutz: ".count($bsiData)." controls, $bsiAnchors anchors\n";
echo "NIST SP800-53r5:    ".count($nistData)." controls, $nistAnchors anchors\n";

// Build YAML
function buildMappingYaml(string $fw, array $ctrls, string $code, string $desc, string $note, string $colNote): string {
    $t = date('Y-m-d');
    $id = "tisax-vda-isa-6_to_{$code}_v1.0";
    $L = [
        "schema_version: '1.1'",
        'library:',
        '  type: mapping',
        "  id: '$id'",
        "  source_framework: 'TISAX'",
        "  target_framework: '$code'",
        '  version: 1',
        "  effective_from: '2024-04-01'",
        '  effective_until: null',
        '',
        '  provenance:',
        "    primary_source: 'VDA ISA Version 6.0 — {$colNote}'",
        "    primary_source_url: 'https://portal.enx.com/isa6-de.xlsx'",
        "    secondary_sources: []",
        "    publisher: 'Little ISMS Helper Maintainers'",
        "    extraction_note: 'Only control-ID to framework-anchor pairs (factual data). No question text or guidance prose. Compliant with ENX licensing.'",
        "    extraction_script: 'scripts/import/extract_vda_isa_col_q_mappings.php'",
        "    generated_at: '$t'",
        '',
        '  methodology:',
        "    type: 'published_official_mapping'",
        '    description: |',
        "      Direct extraction from ENX VDA-ISA 6 workbook column Q",
        "      'Verweisung auf Implementierungsanleitung' (Reference to Implementation Guidance).",
        "      Target: $desc. Anchor format: $note.",
        "      Control IDs from 'Informationssicherheit' sheet only (PP/DP sheets have empty col Q).",
        "      BSI IT-Grundschutz building block IDs are anchor-style factual references.",
        '',
        '  lifecycle:',
        "    state: 'published'",
        '    state_history:',
        "      - { state: 'draft',     date: '$t', actor: 'extract_vda_isa_col_q_mappings.php' }",
        "      - { state: 'published', date: '$t', actor: 'maintainer' }",
        '',
        'mappings:',
    ];
    foreach ($ctrls as $ctrlId => $aa) {
        $L[] = "  - source: 'ISA $ctrlId'";
        if (count($aa) === 1) {
            $L[] = "    targets: ['{$aa[0]}']";
        } else {
            $L[] = '    targets:';
            foreach ($aa as $a) { $L[] = "      - '$a'"; }
        }
        $L[] = "    relationship: 'related'";
        $L[] = "    confidence: 'high'";
        $L[] = "    rationale: 'ENX ISA 6 official implementation guidance cross-reference column'";
    }
    return implode("\n", $L) . "\n";
}

// Write BSI Grundschutz YAML
if (!empty($bsiData)) {
    $file = $outputDir . '/tisax-vda-isa-6_to_bsi-grundschutz_v1.0.yaml';
    file_put_contents($file, buildMappingYaml(
        'bsi-grundschutz', $bsiData, 'bsi-grundschutz',
        'BSI IT-Grundschutz-Kompendium building blocks',
        'building block ID e.g. ISMS.1, ORP.4, NET.1.1',
        "Verweisung auf Implementierungsanleitung (col Q, Informationssicherheit sheet) — BSI IT-Grundschutz-Kompendium section"
    ));
    echo "Written: $file\n";
}

// Write NIST SP800-53r5 YAML
if (!empty($nistData)) {
    $file = $outputDir . '/tisax-vda-isa-6_to_nist-sp800-53r5_v1.0.yaml';
    file_put_contents($file, buildMappingYaml(
        'nist-sp800-53r5', $nistData, 'nist-sp800-53r5',
        'NIST SP 800-53 Revision 5',
        'control ID e.g. AC-1, IR-4(13), CM-8',
        "Verweisung auf Implementierungsanleitung (col Q, Informationssicherheit sheet) — NIST SP800-53r5 section"
    ));
    echo "Written: $file\n";
}

echo "Done.\n";
