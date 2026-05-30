#!/usr/bin/env bash
# Runs the L2 scenario-smoke per persona, then aggregates the JSON
# results into a single HTML report.
#
# Usage:  scripts/browser-coverage/run-scenarios.sh [persona...]
#
# Pre-reqs identical to run.sh: app reachable at $E2E_BASE_URL,
# screenshot-user seeded. L2 does NOT need routes.json — it works
# off the YAML scenarios directly.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_ROOT"

if [ "$#" -gt 0 ]; then
    PERSONAS=("$@")
else
    # `mapfile`/`readarray` is bash 4+; macOS ships bash 3.2. Use a
    # portable read-loop so the default (no-args) path works everywhere.
    PERSONAS=()
    while IFS= read -r _persona; do PERSONAS+=("$_persona"); done < <(python3 -c '
import yaml
d = yaml.safe_load(open("tests/E2e/coverage/persona-routes.yaml"))
print("\n".join(d["personas"].keys()))
')
fi

mkdir -p var/browser-coverage/scenario-results
echo "→ personas: ${PERSONAS[*]}"

EXIT_CODE=0
for persona in "${PERSONAS[@]}"; do
    echo ""
    echo "════════ L2 ${persona} ════════"
    BROWSER_COVERAGE_PERSONA="$persona" \
    BROWSER_COVERAGE_SCENARIOS=1 \
        npx playwright test tests/E2e/specs/scenarios-smoke.spec.ts \
        --reporter=list || EXIT_CODE=$?
done

echo ""
echo "→ generating L2 HTML report..."
node scripts/browser-coverage/generate-scenario-report.mjs

echo ""
echo "✓ Done. Report: var/browser-coverage/scenario-report.html"
exit "$EXIT_CODE"
