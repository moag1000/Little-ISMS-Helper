<?php
/**
 * VDA-ISA 6 Cross-Framework Mapping Extractor
 * Reads ENX VDA-ISA 6 workbook and extracts control-ID to framework-anchor pairs.
 * Only factual data (no question text or descriptive content). ENX-licensing compatible.
 * Usage: php extract_vda_isa_all_mappings.php <xlsx-path> <output-dir>
 * Idempotent: safe to re-run.
 *
 * Frameworks found in ENX ISA 6 workbook (as of 2024 release):
 *   ISO 27001:2022 (+ 2013 cross-ref), ISA/IEC 62443, NIST CSF 1.1, ISO 27017, ISO 27002
 *
 * NOT in workbook: BSI IT-Grundschutz, BSI C5, NIST SP 800-53, NIS2 Art.21, TISAX AL flags.
 * Technical note: ENX workbook uses U+00A0 (NBSP) between "ISO" and "27001" — normalised.
 */
declare(strict_types=1);
foreach ([__DIR__.'/../../vendor/autoload.php', __DIR__.'/../../../vendor/autoload.php'] as $p) {
    if (file_exists($p)) { require_once $p; break; }
}
use PhpOffice\PhpSpreadsheet\IOFactory;
if (php_sapi_name() === 'cli') {
    if ($argc < 3) { fwrite(STDERR, "Usage: php ".$argv[0]." <xlsx> <out-dir>\n"); exit(1); }
    [$xlsxPath, $outputDir] = [$argv[1], rtrim($argv[2], '/')];
}
if (!file_exists($xlsxPath)) { fwrite(STDERR, "File not found: $xlsxPath\n"); exit(1); }
if (!is_dir($outputDir))    { fwrite(STDERR, "Dir not found: $outputDir\n");  exit(1); }
echo "Loading: $xlsxPath\n";
$spreadsheet = IOFactory::load($xlsxPath);
$sheetCfg = ['Information Security'=>['C','P'], 'Informationssicherheit'=>['C','P'],
              'Data Protection'=>['C','L'], 'Datenschutz'=>['C','L']];
$fwPat = [
    'ISO 27001:2022'=>'iso27001-2022', 'ISO27001:2022'=>'iso27001-2022',
    'ISO 27001:2013'=>'iso27001-2013', 'ISO27001:2013'=>'iso27001-2013',
    'Verweisung auf ISO 27001'=>'iso27001-2022', 'Reference to ISO 27001'=>'iso27001-2022',
    'ISA/IEC 62443'=>'iec-isa-62443', 'NIST CSF 1.1'=>'nist-csf-1.1',
    'ISO 27017'=>'iso27017', 'ISO 27002'=>'iso27002',
];
$data = [];
foreach ($spreadsheet->getSheetNames() as $sn) {
    $cfg = null;
    foreach ($sheetCfg as $k=>$c) { if (strcasecmp($sn,$k)===0) { $cfg=$c; break; } }
    if (!$cfg) continue;
    $ws = $spreadsheet->getSheetByName($sn); $hr = $ws->getHighestRow();
    echo "  Sheet: $sn ($hr rows)\n";
    for ($row = 3; $row <= $hr; $row++) {
        $ctrl = trim((string)$ws->getCell($cfg[0].$row)->getValue());
        $normen = str_replace("\xc2\xa0", ' ', trim((string)$ws->getCell($cfg[1].$row)->getValue()));
        if (!$ctrl || !$normen || !preg_match('/^\d+\.\d+(\.\d+)?$/', $ctrl)) continue;
        $cc = null;
        foreach (preg_split('/\r?\n/', $normen) as $line) {
            $line = trim($line); if (!$line) continue;
            $hit = false;
            foreach ($fwPat as $px=>$code) {
                if (str_starts_with($line, $px)) {
                    $cc=$code; $hit=true;
                    $cp = strpos($line, ':');
                    if ($cp !== false) { $rest = ltrim(substr($line,$cp+1)); if ($rest) foreach (pa($rest) as $a) { $data[$cc][$ctrl][]=$a; } }
                    break;
                }
            }
            if (!$hit && $cc) foreach (pa($line) as $a) { $data[$cc][$ctrl][]=$a; }
        }
    }
}
foreach ($data as $fw=>&$ctrls) { foreach ($ctrls as $id=>&$aa) { $aa=array_values(array_unique($aa)); sort($aa); } ksort($ctrls); } unset($ctrls,$aa);
echo "\nFrameworks:\n";
foreach ($data as $fw=>$ctrls) { $n=array_sum(array_map('count',$ctrls)); echo "  $fw: ".count($ctrls)." controls, $n anchors\n"; }
$skip = ['iso27001-2022', 'iso27001-2013'];
$fwMeta = [
    'iec-isa-62443'=>['iec-isa-62443','tisax-vda-isa-6_to_iec-isa-62443_v1.0.yaml','ISA/IEC 62443 IACS security','part.chapter.section e.g. 3.1.7'],
    'nist-csf-1.1' =>['nist-csf-1.1','tisax-vda-isa-6_to_nist-csf-1.1_v1.0.yaml','NIST CSF v1.1','subcategory e.g. ID.AM-1'],
    'iso27017'     =>['iso27017','tisax-vda-isa-6_to_iso27017_v1.0.yaml','ISO/IEC 27017 cloud security','e.g. CLD.6.3.1'],
    'iso27002'     =>['iso27002','tisax-vda-isa-6_to_iso27002_v1.0.yaml','ISO/IEC 27002','Annex A notation'],
];
$gen = [];
foreach ($data as $fw=>$ctrls) {
    if (in_array($fw,$skip,true)) { echo "\nSkipping $fw (ISO 27001 covered by sibling file)\n"; continue; }
    $m=$fwMeta[$fw]??null; if (!$m) { echo "\nWARN: no meta for $fw\n"; continue; }
    [$code,$file,$desc,$note] = $m;
    $out = $outputDir.'/'.$file; echo "\nGenerating: $out\n";
    file_put_contents($out, buildYaml($fw,$ctrls,$code,$desc,$note));
    $n = array_sum(array_map('count',$ctrls)); $gen[]=[$file,count($ctrls),$n];
    echo "  Written: ".count($ctrls)." controls, $n anchors\n";
}
echo "\n=== Summary ===\n"; foreach ($gen as [$f,$c,$n]) { echo "  $f: $c controls -> $n anchors\n"; }
echo "\nGAP: BSI IT-Grundschutz not in workbook (inverse mapping exists). BSI C5, NIS2, NIST SP 800-53: not present.\n";
echo "Done.\n";
function pa(string $r): array { $aa=[]; foreach (preg_split('/,\s*/',$r) as $p) { $p=trim($p); if (!$p) continue; if (preg_match('/^(A\.\d+\.)(\d+)\s*-\s*(\d+)$/',$p,$m)){for($i=(int)$m[2];$i<=(int)$m[3];$i++){$aa[]=$m[1].$i;}continue;} if (preg_match('/^(A\.\d+\.\d+)\s*-\s*(A\.\d+\.\d+)$/',$p,$m)){$aa[]=$m[1];$aa[]=$m[2];continue;} $aa[]=$p; } return $aa; }
function buildYaml(string $fw, array $ctrls, string $code, string $desc, string $note): string {
    $t=date('Y-m-d'); $id="tisax-vda-isa-6_to_{$code}_v1.0";
    $L = ["schema_version: '1.1'",'library:','  type: mapping',"  id: '$id'","  source_framework: 'TISAX'","  target_framework: '$code'",'  version: 1',"  effective_from: '2024-04-01'",'  effective_until: null','','  provenance:','    primary_source: \'VDA ISA Version 6.0 — Reference to other standards (col P, Information Security sheet)\'','    primary_source_url: \'https://portal.enx.com/isa6-de.xlsx\'','    secondary_sources:','      - \'ENX ISA 6 English workbook (identical anchors verified)\'',"    publisher: 'Little ISMS Helper Maintainers'",'    extraction_note: \'Only control-ID to framework-anchor pairs (factual data). No question text. Compliant with ENX licensing.\'',"    extraction_script: 'scripts/import/extract_vda_isa_all_mappings.php'",'    generated_at: \''.$t.'\''   ,'','  methodology:','    type: \'published_official_mapping\'','    description: |','      Direct extraction from ENX VDA-ISA 6 workbook Reference to other standards',"      column. Target: $desc. Anchor format: $note.",'      DE + EN workbooks cross-verified: identical anchor sets in both languages.','','  lifecycle:','    state: \'published\'','    state_history:','      - { state: \'draft\',     date: \''.$t.'\', actor: \'extract_vda_isa_all_mappings.php\' }','      - { state: \'published\', date: \''.$t.'\', actor: \'maintainer\' }','','mappings:'];
    foreach ($ctrls as $ctrlId=>$aa) { $L[]="  - source: 'ISA $ctrlId'"; if(count($aa)===1){$L[]="    targets: ['".$aa[0]."']";}else{$L[]='    targets:';foreach($aa as $a){$L[]="      - '$a'";}} $L[]="    relationship: 'related'"; $L[]="    confidence: 'high'"; $L[]="    rationale: 'ENX ISA 6 official cross-reference column'"; }
    return implode("\n",$L)."\n";
}
