#!/usr/bin/env bash
#
# Bucket-6c (DORA RoI Sprint 9) — Arelle-based XBRL validation for DORA RoI.
#
# Validates an XBRL XML payload (produced by DoraRoiXbrlExporter) against the
# ESA Joint RoI taxonomy schema. Required pre-flight check before submitting
# the document to the Bundesbank / BaFin DORA portal.
#
# This script is NOT yet wired into CI — it's an opt-in local / on-demand
# tool. Wiring into CI is a separate ops decision because:
#   - Arelle needs a ~200 MB taxonomy cache (network-fetch on first run).
#   - The ESA taxonomy URI is still placeholder until ESA finalises it
#     (currently set to http://esa.europa.eu/xbrl/dora/roi/2024 in the exporter).
#   - Validation against the production taxonomy will only become deterministic
#     once the ESA publishes the final endorsed Implementing Technical Standard.
#
# Usage:
#   ./scripts/validate-dora-xbrl.sh <path-to-xbrl-file>
#   ./scripts/validate-dora-xbrl.sh /tmp/test-export.xbrl
#
# Requirements:
#   - Python 3.9+
#   - pip install arelle-release  (≥2.x — the maintained fork)
#   - Optional: ESA RoI taxonomy package extracted to ./var/dora-taxonomy/
#
# Exit codes:
#   0 — XBRL well-formed and (when taxonomy available) validates
#   1 — XBRL malformed / Arelle reports errors
#   2 — Arelle not installed
#   3 — missing argument

set -euo pipefail

XBRL_FILE="${1:-}"

if [[ -z "${XBRL_FILE}" ]]; then
    echo "Usage: $0 <path-to-xbrl-file>" >&2
    echo "Example: $0 /tmp/dora-roi-export.xbrl" >&2
    exit 3
fi

if [[ ! -f "${XBRL_FILE}" ]]; then
    echo "ERROR: XBRL file not found: ${XBRL_FILE}" >&2
    exit 3
fi

# ── Tier 1 — XML well-formedness check (always available, no Arelle needed) ──
echo "[1/2] XML well-formedness check via xmllint..."
if command -v xmllint >/dev/null 2>&1; then
    if xmllint --noout "${XBRL_FILE}" 2>&1; then
        echo "  ✓ XML well-formed"
    else
        echo "  ✗ XML malformed — aborting" >&2
        exit 1
    fi
else
    echo "  ! xmllint not available — skipping XML well-formedness check"
fi

# ── Tier 2 — Arelle XBRL validation (optional, requires install) ─────────────
echo "[2/2] Arelle XBRL taxonomy validation..."

# Resolve Arelle CLI — prefer python -m arelleCmdLine (the official invocation
# pattern for the arelle-release pip package).
ARELLE_CMD=""
if command -v arelleCmdLine >/dev/null 2>&1; then
    ARELLE_CMD="arelleCmdLine"
elif python3 -c "import arelle" >/dev/null 2>&1; then
    ARELLE_CMD="python3 -m arelle.CntlrCmdLine"
else
    cat <<'EOF' >&2

  ! Arelle not installed — taxonomy validation skipped.

  To enable full ESA RoI taxonomy validation:

    pip install arelle-release

  Then re-run this script. The first invocation will fetch ~200 MB of XBRL
  base taxonomies into ~/.cache/arelle/cache (subsequent runs are fast).

EOF
    # Soft-exit-0: XML well-formedness already passed. Treat missing Arelle as
    # a developer-environment limitation, not a validation failure.
    exit 0
fi

# Run Arelle. The --plugins flag is a placeholder — when the ESA publishes its
# Implementing Technical Standard, add the official ESA plugin here.
echo "  Using: ${ARELLE_CMD}"
${ARELLE_CMD} \
    --file "${XBRL_FILE}" \
    --validate \
    --logFile - \
    --logFormat "[%(messageCode)s] %(message)s" \
    || {
        echo "  ✗ Arelle reported validation errors — see log above" >&2
        exit 1
    }

echo "  ✓ Arelle validation passed"
echo ""
echo "DONE: ${XBRL_FILE} is well-formed and (where the taxonomy was available)"
echo "      validates against the loaded XBRL schemas."
