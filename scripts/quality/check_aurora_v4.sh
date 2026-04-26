#!/usr/bin/env bash
# Aurora v4 — quick audit checks. Run from project root.
set -e

cd "$(dirname "$0")/../.."

echo "=== Aurora v4 — Quick Audit ==="
echo ""

echo "## fa-* Component Usage (positive metrics)"
echo -n "  fa-cyber-btn:        "; grep -rcE 'class="[^"]*\bfa-cyber-btn\b' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  fa-status-pill:      "; grep -rcE 'class="[^"]*\bfa-status-pill\b' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  fa-alert:            "; grep -rcE 'class="[^"]*\bfa-alert\b' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  fa-section:          "; grep -rcE 'class="[^"]*\bfa-section\b' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  fa-kpi-card:         "; grep -rcE 'class="[^"]*\bfa-kpi-card\b' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  fa-icon--*:          "; grep -rE 'class="[^"]*\bfa-icon--' templates/ 2>/dev/null | wc -l
echo -n "  fa-aurora-surface:   "; grep -rcE 'class="[^"]*\bfa-aurora-surface\b' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  fa-empty-state:      "; grep -rcE 'class="[^"]*\bfa-empty-state\b|_fa_empty_state\.html\.twig' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  fa-rag-card:         "; grep -rcE 'class="[^"]*\bfa-rag-card\b' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  fa-data-table:       "; grep -rcE 'class="[^"]*\bfa-data-table\b' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  fa-issue-list:       "; grep -rcE 'class="[^"]*\bfa-issue-list\b' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo ""

echo "## Bootstrap-Restbestand (sollte sinken über Zeit)"
echo -n "  btn btn-*:           "; grep -rcE 'class="[^"]*\bbtn-(primary|secondary|success|warning|danger|info|outline-)' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  badge bg-*:          "; grep -rcE 'class="[^"]*\bbadge bg-' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  alert alert-*:       "; grep -rcE 'class="[^"]*\balert alert-' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  bi bi-*:             "; grep -rcE 'class="[^"]*\bbi bi-' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo ""

echo "## Anti-Patterns"
echo -n "  inline style=:       "; grep -rcE 'style="[^"]+' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo -n "  hardcoded hex in CSS:"; grep -rE '#[0-9a-fA-F]{6}\b' assets/styles/*.css 2>/dev/null \
    | grep -v "^assets/styles/fairy-aurora\.css:\|^assets/styles/alva\.css:" \
    | grep -v "^assets/styles/fairy-aurora-print\.css:\|^assets/styles/fairy-aurora-components\.css:" \
    | wc -l
echo -n "  TODO(aurora-v4):     "; grep -rc 'TODO(aurora-v4' templates/ 2>/dev/null | grep -v ":0" | wc -l
echo ""

echo "## Files"
echo "  Twig templates:       $(find templates -name '*.twig' 2>/dev/null | wc -l)"
echo "  Aurora-Macros:        $(find templates/_components -name '_fa_*.html.twig' 2>/dev/null | wc -l)"
echo "  Aurora-Icons (SVG):   $(find assets/icons -name '*.svg' 2>/dev/null | wc -l)"
echo ""
echo "Run again after each migration sprint. Lower Bootstrap-Restbestand"
echo "and higher fa-* Usage = good progress."
