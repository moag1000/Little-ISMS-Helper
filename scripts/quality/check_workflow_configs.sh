#!/usr/bin/env bash
# Quality Gate 14: validate every workflow config loads + supports-entity exists
set -euo pipefail

cd "$(dirname "$0")/../.."

# Symfony fails container compile if any workflow config has an unknown
# supports class. Just running cache:clear catches all our errors.
php bin/console cache:clear --env=test > /dev/null 2>&1

# Run workflow:dump for each defined state-machine — catches metadata-misconfigure
count=0
for cfg in config/workflows/*.yaml; do
    [ -f "$cfg" ] || continue
    base=$(basename "$cfg" .yaml)
    name="${base}_lifecycle"
    if ! php bin/console workflow:dump "$name" --env=test > /dev/null 2>&1; then
        echo "ERROR Gate 14 — workflow:dump failed for $name (file: $cfg)"
        exit 1
    fi
    count=$((count + 1))
done

echo "OK  Gate 14 — $count workflow config(s) valid."
