#!/usr/bin/env bash
# Gate 4 — DDL-Migration isTransactional()=false Checker
#
# Per CLAUDE.md pitfall #6 + memory feedback_migration_savepoint:
# Any migration that contains ALTER TABLE, CREATE TABLE, or DROP TABLE
# MUST override isTransactional() to return false.
#
# Without this override, running >1 DDL migration in a single
# `doctrine:migrations:migrate` call fails with:
#   "SAVEPOINT DOCTRINE_X does not exist"
# because MySQL/MariaDB DDL statements cause an implicit commit, which
# invalidates Doctrine's SAVEPOINT-based transaction management.
#
# Detection:
#   1. Grep migrations for ALTER TABLE / CREATE TABLE / DROP TABLE in up()/down()
#   2. For each matching file, verify isTransactional method returning false exists
#
# CUTOFF DATE: 20260508 (2026-05-08 — when this gate was introduced).
#   Migrations with timestamps BEFORE this date are legacy and exempt from
#   the gate. New migrations from this date onward must comply.
#   This avoids blocking CI on 47 historical migrations predating the rule.
#   Future goal: backfill legacy migrations and remove the cutoff.
#
# Usage: bash check_migration_transactional.sh
# Exit 0 = clean, Exit 1 = violations found.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MIGRATIONS_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)/migrations"

if [ ! -d "$MIGRATIONS_DIR" ]; then
    echo "ERROR: migrations/ directory not found at $MIGRATIONS_DIR" >&2
    exit 2
fi

# Only check migrations on or after this date (YYYYMMDD extracted from filename)
# Format: Version20260508XXXXXX.php → 20260508
CUTOFF_DATE="20260508"

VIOLATIONS=0
CHECKED=0
SKIPPED=0
TOTAL=0

while IFS= read -r -d '' migration_file; do
    TOTAL=$((TOTAL + 1))
    filename=$(basename "$migration_file")

    # Extract date from filename: Version20251113140643.php → 20251113
    migration_date=$(echo "$filename" | grep -oE '[0-9]{8}' | head -1 || true)

    # Skip migrations older than cutoff
    if [ -n "$migration_date" ] && [ "$migration_date" -lt "$CUTOFF_DATE" ]; then
        SKIPPED=$((SKIPPED + 1))
        continue
    fi

    CHECKED=$((CHECKED + 1))

    # Check if file contains DDL statements (ALTER/CREATE/DROP TABLE)
    if grep -qiE '\b(ALTER TABLE|CREATE TABLE|DROP TABLE)\b' "$migration_file"; then
        # Verify isTransactional override returning false is present
        if ! grep -q 'isTransactional' "$migration_file"; then
            rel_path="${migration_file#$(cd "$SCRIPT_DIR/../.." && pwd)/}"
            echo "${rel_path}: DDL-migration missing isTransactional()=false override"
            VIOLATIONS=$((VIOLATIONS + 1))
        elif ! grep -qE 'return\s+false' "$migration_file"; then
            rel_path="${migration_file#$(cd "$SCRIPT_DIR/../.." && pwd)/}"
            echo "${rel_path}: DDL-migration has isTransactional() but does not return false"
            VIOLATIONS=$((VIOLATIONS + 1))
        fi
    fi

done < <(find "$MIGRATIONS_DIR" -name "Version*.php" -print0)

if [ "$VIOLATIONS" -eq 0 ]; then
    echo "OK  Gate 4 — ${CHECKED} migrations checked (${SKIPPED} pre-${CUTOFF_DATE} skipped), all DDL-migrations have isTransactional()=false."
    exit 0
else
    echo ""
    echo "Gate 4 FAIL: ${VIOLATIONS} DDL-migration(s) missing isTransactional()=false (checked migrations >= ${CUTOFF_DATE})."
    echo "Fix: add the following method to each flagged migration class:"
    echo "  public function isTransactional(): bool { return false; }"
    exit 1
fi
