#!/bin/bash

################################################################################
# Little ISMS Helper - License Report Generator
################################################################################
#
# Erstellt einen umfassenden Lizenzbericht fuer alle Abhaengigkeiten:
# - PHP-Pakete (Composer)
# - JavaScript-Pakete (Symfony ImportMap)
#
# Ausgabe: docs/reports/license-report.md
#
# Benoetigt: composer (mit composer.lock im Projekt-Root)
#
################################################################################

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Navigate to project root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR/../.."
cd "$PROJECT_ROOT"

OUTPUT_FILE="docs/reports/license-report.md"

echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║          Little ISMS Helper - Lizenzbericht                   ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check prerequisites
if ! command -v composer &> /dev/null; then
    echo -e "${RED}✗ Composer ist nicht installiert!${NC}"
    exit 1
fi

if [ ! -f "composer.lock" ]; then
    echo -e "${RED}✗ composer.lock nicht gefunden!${NC}"
    exit 1
fi

mkdir -p "$(dirname "$OUTPUT_FILE")"

echo -e "${BLUE}→${NC} Analysiere PHP-Abhaengigkeiten..."

# --- Use PHP for all classification (portable, no bash 4+ needed) ---
composer licenses --format=json 2>/dev/null | php -r '
$allowed = ["MIT","BSD-2-Clause","BSD-3-Clause","Apache-2.0","ISC","Unlicense","CC0-1.0","0BSD"];
$copyleft = ["LGPL-2.0-only","LGPL-2.0-or-later","LGPL-2.1-only","LGPL-2.1-or-later","LGPL-3.0-only","LGPL-3.0-or-later","MPL-2.0"];
$notAllowed = ["GPL-2.0-only","GPL-2.0-or-later","GPL-3.0-only","GPL-3.0-or-later","AGPL-3.0-only","AGPL-3.0-or-later","SSPL-1.0","BSL-1.1","CC-BY-NC-4.0"];

$data = json_decode(file_get_contents("php://stdin"), true);
if (!$data || !isset($data["dependencies"])) { fwrite(STDERR, "No composer data\n"); exit(1); }

$counts = ["allowed"=>0,"copyleft"=>0,"not_allowed"=>0,"unknown"=>0];
$rows = ["allowed"=>[],"copyleft"=>[],"not_allowed"=>[],"unknown"=>[]];

foreach ($data["dependencies"] as $name => $info) {
    $version = $info["version"] ?? "?";
    $licenses = $info["license"] ?? ["unknown"];
    $licStr = implode(", ", $licenses);

    $cat = "unknown";
    foreach ($licenses as $l) {
        $l = trim($l);
        if (in_array($l, $allowed, true)) { $cat = "allowed"; break; }
    }
    if ($cat === "unknown") {
        foreach ($licenses as $l) {
            $l = trim($l);
            if (in_array($l, $copyleft, true)) { $cat = "copyleft"; break; }
        }
    }
    if ($cat === "unknown") {
        foreach ($licenses as $l) {
            $l = trim($l);
            if (in_array($l, $notAllowed, true)) { $cat = "not_allowed"; break; }
        }
    }

    $counts[$cat]++;
    $statusMap = ["allowed"=>"✅ Erlaubt","copyleft"=>"🔄 Copyleft","not_allowed"=>"❌ Nicht erlaubt","unknown"=>"❓ Unbekannt"];
    $rows[$cat][] = "| $name | $version | $licStr | {$statusMap[$cat]} |";
}

$total = array_sum($counts);
$compliant = $counts["allowed"] + $counts["copyleft"];

// JS packages
$jsSection = "";
if (file_exists("importmap.php")) {
    $jsCount = substr_count(file_get_contents("importmap.php"), "=>");
    $jsSection = "\n\n### JavaScript-Pakete (Symfony ImportMap)\n\n" .
        "$jsCount Pakete via Symfony ImportMap. Haupt-Lizenzen: Chart.js (MIT), Bootstrap (MIT), " .
        "Stimulus (MIT), Turbo (MIT), Tom-Select (Apache-2.0), SortableJS (MIT).";
}

$date = date("Y-m-d");
$pctAllowed = $total > 0 ? round($counts["allowed"] * 100 / $total) : 0;
$pctCopyleft = $total > 0 ? round($counts["copyleft"] * 100 / $total) : 0;
$pctNotAllowed = $total > 0 ? round($counts["not_allowed"] * 100 / $total) : 0;
$pctUnknown = $total > 0 ? round($counts["unknown"] * 100 / $total) : 0;

$md = "# Little ISMS Helper - Lizenzbericht\n\n";
$md .= "**Berichtsdatum:** $date\n";
$md .= "**Lizenzkonform ($compliant/$total Pakete)**\n\n";
$md .= "## Zusammenfassung\n\n";
$md .= "| Kategorie | Anzahl | Anteil |\n|-----------|--------|--------|\n";
$md .= "| ✅ Erlaubt (MIT, BSD, Apache, ISC) | **{$counts["allowed"]}** | {$pctAllowed}% |\n";
$md .= "| 🔄 Copyleft (LGPL, MPL) | **{$counts["copyleft"]}** | {$pctCopyleft}% |\n";
$md .= "| ❌ Nicht erlaubt (GPL, AGPL, NC) | **{$counts["not_allowed"]}** | {$pctNotAllowed}% |\n";
$md .= "| ❓ Unbekannt | **{$counts["unknown"]}** | {$pctUnknown}% |\n";
$md .= "| **Gesamt PHP-Pakete** | **$total** | 100% |\n\n";
$md .= "## Bewertung\n\n";

if ($counts["not_allowed"] === 0 && $counts["unknown"] <= 5) {
    $md .= "✅ **BESTANDEN** — Alle Lizenzen sind fuer kommerzielle Nutzung geeignet.\n";
} elseif ($counts["not_allowed"] > 0) {
    $md .= "❌ **NICHT BESTANDEN** — {$counts["not_allowed"]} Paket(e) mit nicht-kommerzieller Lizenz!\n";
} else {
    $md .= "⚠️ **WARNUNG** — {$counts["unknown"]} Paket(e) mit unbekannter Lizenz erfordern Pruefung.\n";
}

if ($counts["copyleft"] > 0) {
    $md .= "\n### Copyleft-Pakete\n\nSchwache Copyleft-Lizenzen (LGPL/MPL) — als Dependency in AGPL-3.0 kompatibel.\n\n";
    $md .= "| Paket | Version | Lizenz | Status |\n|-------|---------|--------|--------|\n";
    $md .= implode("\n", $rows["copyleft"]) . "\n";
}
if ($counts["not_allowed"] > 0) {
    $md .= "\n### ❌ Nicht erlaubte Lizenzen\n\n| Paket | Version | Lizenz | Status |\n|-------|---------|--------|--------|\n";
    $md .= implode("\n", $rows["not_allowed"]) . "\n";
}
if ($counts["unknown"] > 0) {
    $md .= "\n### ❓ Unbekannte Lizenzen\n\n| Paket | Version | Lizenz | Status |\n|-------|---------|--------|--------|\n";
    $md .= implode("\n", $rows["unknown"]) . "\n";
}

$md .= "\n## Vollstaendige Paketliste\n\n### PHP-Pakete (Composer)\n\n";
$md .= "| Paket | Version | Lizenz | Status |\n|-------|---------|--------|--------|\n";
foreach (["allowed","copyleft","not_allowed","unknown"] as $c) {
    $md .= implode("\n", $rows[$c]) . "\n";
}
$md .= $jsSection;
$md .= "\n\n---\n\n*Generiert am $date mit scripts/tools/license-report.sh*\n*Projektlizenz: AGPL-3.0-or-later*\n";

file_put_contents(getenv("OUTPUT_FILE") ?: "docs/reports/license-report.md", $md);

// Output for bash
echo "TOTAL=$total\n";
echo "ALLOWED={$counts["allowed"]}\n";
echo "COPYLEFT={$counts["copyleft"]}\n";
echo "NOT_ALLOWED={$counts["not_allowed"]}\n";
echo "UNKNOWN={$counts["unknown"]}\n";
' > /tmp/license-result.txt

# Read results
eval $(cat /tmp/license-result.txt 2>/dev/null)
TOTAL_PHP=${TOTAL:-0}
ALLOWED=${ALLOWED:-0}
COPYLEFT=${COPYLEFT:-0}
NOT_ALLOWED=${NOT_ALLOWED:-0}
UNKNOWN=${UNKNOWN:-0}
rm -f /tmp/license-result.txt

echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║                  ✓ Erfolgreich abgeschlossen!                 ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  PHP-Pakete:    ${BLUE}$TOTAL_PHP${NC}"
echo -e "  Erlaubt:       ${GREEN}$ALLOWED${NC}"
echo -e "  Copyleft:      ${YELLOW}$COPYLEFT${NC}"
echo -e "  Nicht erlaubt: ${RED}$NOT_ALLOWED${NC}"
echo -e "  Unbekannt:     ${YELLOW}$UNKNOWN${NC}"
echo ""
echo -e "${BLUE}→${NC} Bericht: ${GREEN}$OUTPUT_FILE${NC}"
