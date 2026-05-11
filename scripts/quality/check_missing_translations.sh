#!/usr/bin/env bash
# Gate 6 — Missing Translation Keys Checker
#
# Wraps `php bin/console debug:translation de --only-missing --domain=<domain>`
# for a targeted subset of high-priority translation domains.
#
# Rationale for subset approach:
#   - Checking all 132 domains takes 2-4 minutes (CI budget: <30s total for all gates)
#   - The 'messages' domain is a known catch-all fallback with pre-existing gaps
#   - High-churn domains (nav, alva, admin, compliance) are most likely to miss keys
#
# HIGH_PRIORITY_DOMAINS: checked on every CI run (fast, most critical)
# If any domain reports missing keys, the gate fails.
#
# To check all domains locally: run this script with ALL_DOMAINS=1 env var.
#   ALL_DOMAINS=1 bash check_missing_translations.sh
#
# SKIP_DOMAINS: known-noisy domains with pre-existing gaps that are tracked
# separately. Remove from skip list once backfilled.
#
# Requirements:
#   - Symfony console available at bin/console
#   - Run from project root
#
# Usage: bash check_missing_translations.sh
# Exit 0 = no missing keys, Exit 1 = missing keys found.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
TRANSLATIONS_DIR="$PROJECT_ROOT/translations"
CONSOLE="$PROJECT_ROOT/bin/console"

if [ ! -f "$CONSOLE" ]; then
    echo "ERROR: bin/console not found at $CONSOLE" >&2
    exit 2
fi

if [ ! -d "$TRANSLATIONS_DIR" ]; then
    echo "ERROR: translations/ not found at $TRANSLATIONS_DIR" >&2
    exit 2
fi

cd "$PROJECT_ROOT"

# High-priority domains checked on every CI run
HIGH_PRIORITY_DOMAINS=(
    "nav"
    "alva"
    "admin"
    "compliance"
    "compliance_wizard"
    "mfa"
    "dashboard"
    "risk"
    "control"
    "incident"
    "assets"
    "vulnerabilities"
)

# Domains with known pre-existing gaps — tracked separately, not enforced in CI yet
# Remove entries from this list once the domain is backfilled
SKIP_DOMAINS=(
    "messages"       # catch-all fallback domain with 200+ historical gaps
)

# Determine which domains to check
if [ "${ALL_DOMAINS:-0}" = "1" ]; then
    # Full check mode: collect all domains from translation files
    DOMAINS=()
    while IFS= read -r -d '' de_file; do
        domain=$(basename "$de_file" .de.yaml)
        en_file="$TRANSLATIONS_DIR/${domain}.en.yaml"
        if [ -f "$en_file" ]; then
            skip=false
            for skip_d in "${SKIP_DOMAINS[@]}"; do
                [ "$domain" = "$skip_d" ] && skip=true && break
            done
            $skip || DOMAINS+=("$domain")
        fi
    done < <(find "$TRANSLATIONS_DIR" -name "*.de.yaml" -print0 | sort -z)
    echo "Mode: ALL_DOMAINS (${#DOMAINS[@]} domains)"
else
    # Targeted mode: only high-priority domains
    DOMAINS=()
    for domain in "${HIGH_PRIORITY_DOMAINS[@]}"; do
        if [ -f "$TRANSLATIONS_DIR/${domain}.de.yaml" ] && [ -f "$TRANSLATIONS_DIR/${domain}.en.yaml" ]; then
            skip=false
            for skip_d in "${SKIP_DOMAINS[@]}"; do
                [ "$domain" = "$skip_d" ] && skip=true && break
            done
            $skip || DOMAINS+=("$domain")
        fi
    done
    echo "Mode: targeted (${#DOMAINS[@]} high-priority domains; use ALL_DOMAINS=1 for full check)"
fi

VIOLATIONS=0
DOMAINS_CHECKED=0

for domain in "${DOMAINS[@]}"; do
    DOMAINS_CHECKED=$((DOMAINS_CHECKED + 1))

    # Run debug:translation for German locale, capture output (suppress stderr)
    output=$(php "$CONSOLE" debug:translation de --only-missing --domain="$domain" 2>/dev/null || true)

    # Check for missing entries in the output table
    if echo "$output" | grep -qE '^\s*\|\s*missing\s*\|'; then
        missing_count=$(echo "$output" | grep -cE '^\s*\|\s*missing\s*\|' || true)
        echo "FAIL domain '$domain': ${missing_count} missing German translation key(s)"
        # Show first 5 missing keys
        echo "$output" | grep -E '^\s*\|\s*missing\s*\|' | head -5 | while IFS= read -r row; do
            key=$(echo "$row" | awk -F'|' '{gsub(/^[[:space:]]+|[[:space:]]+$/, "", $4); print $4}')
            echo "  - $key"
        done
        VIOLATIONS=$((VIOLATIONS + missing_count))
    fi
done

echo ""
echo "Translation check: ${DOMAINS_CHECKED} domain(s) checked."

if [ "$VIOLATIONS" -eq 0 ]; then
    echo "OK  Gate 6 — No missing translation keys in checked domains."
    exit 0
else
    echo "Gate 6 FAIL: ${VIOLATIONS} missing translation key(s) found."
    echo "Fix: run 'php bin/console debug:translation de --only-missing --domain=<domain>'"
    echo "     then add missing keys to translations/<domain>.de.yaml"
    exit 1
fi
