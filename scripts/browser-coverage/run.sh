#!/usr/bin/env bash
# Runs the L1 browser-smoke for every persona declared in
# tests/E2e/coverage/persona-routes.yaml, then aggregates the per-persona
# JSON results into a single HTML report.
#
# Usage:  scripts/browser-coverage/run.sh [persona...]
# Examples:
#   scripts/browser-coverage/run.sh                       # all personas
#   scripts/browser-coverage/run.sh full-sweep            # one persona
#   scripts/browser-coverage/run.sh ciso-executive dpo    # several
#
# Pre-reqs:
#   - Symfony app reachable at $E2E_BASE_URL (default http://127.0.0.1:8000)
#   - `php bin/console app:create-screenshot-user` has been run
#   - `php bin/console app:browser-coverage:export-routes` produced routes.json

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_ROOT"

# Refresh the route manifest unless the caller skipped it explicitly.
if [ "${BROWSER_COVERAGE_SKIP_EXPORT:-0}" != "1" ]; then
    echo "→ exporting routes..."
    php bin/console app:browser-coverage:export-routes
fi

# Resolve the personas list — explicit args win, otherwise read the YAML.
if [ "$#" -gt 0 ]; then
    PERSONAS=("$@")
else
    # Use Python (already a dev-dep) to extract persona keys.
    # `mapfile`/`readarray` is bash 4+; macOS ships bash 3.2. Use a
    # portable read-loop so the default (no-args) path works everywhere.
    PERSONAS=()
    while IFS= read -r _persona; do PERSONAS+=("$_persona"); done < <(python3 -c '
import yaml, sys
d = yaml.safe_load(open("tests/E2e/coverage/persona-routes.yaml"))
print("\n".join(d["personas"].keys()))
')
fi

mkdir -p var/browser-coverage/results
echo "→ personas: ${PERSONAS[*]}"

EXIT_CODE=0
for persona in "${PERSONAS[@]}"; do
    echo ""
    echo "════════ ${persona} ════════"
    BROWSER_COVERAGE_PERSONA="$persona" \
        npx playwright test tests/E2e/specs/route-smoke.spec.ts \
        --reporter=list || EXIT_CODE=$?
done

echo ""
echo "→ generating HTML report..."
node scripts/browser-coverage/generate-report.mjs

echo ""
echo "✓ Done. Report: var/browser-coverage/report.html"
exit "$EXIT_CODE"
