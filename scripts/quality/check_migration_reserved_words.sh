#!/usr/bin/env bash
# Gate 3 — MariaDB 11 Reserved-Word Column Checker
#
# Detects unquoted MariaDB-11 reserved words used as column names in
# CREATE TABLE / ALTER TABLE ADD COLUMN statements inside migration files.
#
# MariaDB 11 added window-function keywords as reserved words:
#   ROW_NUMBER, RANK, DENSE_RANK, PERCENT_RANK, LEAD, LAG, WINDOW, OVER,
#   NTH_VALUE, FIRST_VALUE, LAST_VALUE, NTILE, CUME_DIST, SYSTEM_TIME
#
# A column using these names without backtick-quoting causes:
#   ERROR 1064 (42000): You have an error in your SQL syntax
#
# Detection logic:
#   - Match: RESERVED_WORD followed by column-type keyword (INT, VARCHAR, etc.)
#   - Exclude: lines where the reserved word is wrapped in backticks (`word`)
#   - Exclude: SQL comment lines (-- or *)
#   - Exclude: lines containing PREPARE / EXECUTE (dynamic SQL)
#
# Fast implementation: uses grep directly (avoids per-line bash loop).
#
# Conservative: only flags clear "WORD TYPE" patterns, not semantic analysis.
#
# Usage: bash check_migration_reserved_words.sh
# Exit 0 = clean, Exit 1 = violations found.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MIGRATIONS_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)/migrations"

if [ ! -d "$MIGRATIONS_DIR" ]; then
    echo "ERROR: migrations/ directory not found at $MIGRATIONS_DIR" >&2
    exit 2
fi

# Pattern: reserved word followed by a column type keyword
# Column types that would typically follow a column name
RESERVED_PATTERN='\b(ROW_NUMBER|RANK|DENSE_RANK|PERCENT_RANK|LEAD|LAG|WINDOW|OVER|NTH_VALUE|FIRST_VALUE|LAST_VALUE|NTILE|CUME_DIST|SYSTEM_TIME)\b[[:space:]]+(INT|BIGINT|SMALLINT|TINYINT|VARCHAR|TEXT|LONGTEXT|MEDIUMTEXT|TINYTEXT|CHAR|DATETIME|DATE|TIME|TIMESTAMP|DOUBLE|FLOAT|DECIMAL|NUMERIC|BOOLEAN|BOOL|JSON|BLOB)'

TOTAL=$(find "$MIGRATIONS_DIR" -name "Version*.php" | wc -l | tr -d ' ')

# Use grep to find potential violations, then filter out false-positives
# Step 1: grep for the pattern (case-insensitive)
# Step 2: exclude lines with backtick-quoted version
# Step 3: exclude SQL comment lines and PREPARE/EXECUTE

VIOLATIONS_OUTPUT=$(
    grep -rniE "$RESERVED_PATTERN" "$MIGRATIONS_DIR" --include="Version*.php" 2>/dev/null \
    | grep -ivE '`(ROW_NUMBER|RANK|DENSE_RANK|PERCENT_RANK|LEAD|LAG|WINDOW|OVER|NTH_VALUE|FIRST_VALUE|LAST_VALUE|NTILE|CUME_DIST|SYSTEM_TIME)`' \
    | grep -ivE '^\s*--|\s*\*\s' \
    | grep -ivE 'PREPARE|EXECUTE' \
    || true
)

if [ -z "$VIOLATIONS_OUTPUT" ]; then
    echo "OK  Gate 3 — ${TOTAL} migrations checked, no reserved-word column violations."
    exit 0
fi

VIOLATIONS_COUNT=$(echo "$VIOLATIONS_OUTPUT" | wc -l | tr -d ' ')

while IFS= read -r line; do
    # Format: path:lineno:content
    filepath=$(echo "$line" | cut -d: -f1)
    lineno=$(echo "$line" | cut -d: -f2)
    content=$(echo "$line" | cut -d: -f3- | head -c 120)
    word=$(echo "$content" | grep -ioE '\b(ROW_NUMBER|RANK|DENSE_RANK|PERCENT_RANK|LEAD|LAG|WINDOW|OVER|NTH_VALUE|FIRST_VALUE|LAST_VALUE|NTILE|CUME_DIST|SYSTEM_TIME)\b' | head -1)
    rel_path="${filepath#$(cd "$SCRIPT_DIR/../.." && pwd)/}"
    echo "${rel_path}:${lineno}: reserved-word column requires backticks: ${word}"
done <<< "$VIOLATIONS_OUTPUT"

echo ""
echo "Gate 3 FAIL: ${VIOLATIONS_COUNT} reserved-word column violation(s)."
echo "Fix: wrap column names in backticks: \`rank\` INT, \`window\` VARCHAR(255), etc."
exit 1
