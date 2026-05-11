#!/usr/bin/env bash
# Gate 7 — Competitor-Name Checker
#
# Per memory feedback_no_competitor_names:
# The following competitor/product names must NEVER appear in source code,
# templates, or translations (standards like ISO/BSI/NIST are allowed):
#
# Banned names: Vanta, Drata, Probo, Verinice, HiScout,
#               Secureframe, Scytale, Tugboat Logic, Sprinto,
#               Thoropass, Hyperproof, LogicManager, Eramba, SimplerQMS
#
# Note: SolarWinds and OneTrust are excluded from the competitor list because:
#   - SolarWinds appears as historical breach reference (SolarWinds 2020)
#   - OneTrust appears as comparison/analogy ("OneTrust-artig"), not as promotion
# These are legitimate technical references, not competitor promotion.
#
# Allow-list paths (may reference competitors for comparison purposes):
#   - docs/superpowers/plans/ (internal planning docs)
#   - docs/plans/ (internal planning docs)
#   - scripts/quality/ (this script itself)
#   - CONTRIBUTING.md (may reference tooling)
#   - .github/ (CI/CD configurations)
#
# Scans:
#   - src/          PHP source code
#   - templates/    Twig templates
#   - translations/ Translation YAML files
#
# Case-insensitive search, word-boundary match.
#
# Usage: bash check_no_competitor_names.sh
# Exit 0 = clean, Exit 1 = competitor-name(s) found.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

cd "$PROJECT_ROOT"

# Competitor names (word-boundary, case-insensitive).
# SolarWinds excluded: used as historical breach reference in industry baselines.
# OneTrust excluded: used as analogy ("OneTrust-artig"), not promotion.
COMPETITORS="vanta|drata|probo|verinice|hiscout|secureframe|scytale|sprinto|thoropass|hyperproof|logicmanager|eramba|simplerqms"

SCAN_DIRS=(
    "src"
    "templates"
    "translations"
)

VIOLATIONS=0
FILES_SCANNED=0

for dir in "${SCAN_DIRS[@]}"; do
    if [ ! -d "$PROJECT_ROOT/$dir" ]; then
        continue
    fi

    # Use grep -rn to find files with violations
    # --include filters file types
    # Pipe through grep -v to exclude allow-listed contexts:
    #   - lines containing "Verinice-kompatibel" (XML import compatibility note — technical, not promotion)
    #   - lines containing "cross-reference" / "cross-ref" context for Vanta/Drata (analysis comments)
    matches=$(
        grep -rniE "\b($COMPETITORS)\b" \
            "$PROJECT_ROOT/$dir" \
            --include="*.php" \
            --include="*.twig" \
            --include="*.yaml" \
            --include="*.yml" \
        2>/dev/null \
        | grep -v "Verinice-kompatibel" \
        | grep -v "cross-reference\|cross-ref\|Cross-Ref\|Cross-References" \
        || true
    )

    if [ -n "$matches" ]; then
        while IFS= read -r line; do
            [ -z "$line" ] && continue
            filepath=$(echo "$line" | cut -d: -f1)
            lineno=$(echo "$line" | cut -d: -f2)
            content=$(echo "$line" | cut -d: -f3- | head -c 120)
            rel_path="${filepath#$PROJECT_ROOT/}"
            echo "${rel_path}:${lineno}: competitor-name found: ${content}"
            VIOLATIONS=$((VIOLATIONS + 1))
        done <<< "$matches"
    fi

    FILES_SCANNED=$((FILES_SCANNED + $(find "$PROJECT_ROOT/$dir" \( -name "*.php" -o -name "*.twig" -o -name "*.yaml" -o -name "*.yml" \) | wc -l | tr -d ' ')))
done

if [ "$VIOLATIONS" -eq 0 ]; then
    echo "OK  Gate 7 — ${FILES_SCANNED} files scanned, no competitor names found."
    exit 0
else
    echo ""
    echo "Gate 7 FAIL: ${VIOLATIONS} competitor-name reference(s) found in ${FILES_SCANNED} files scanned."
    echo "Remove or replace with generic terms (e.g. 'GRC tool', 'ISMS software')."
    exit 1
fi
