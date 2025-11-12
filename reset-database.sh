#!/bin/bash
#
# DEPRECATED WRAPPER - This script has been moved
# Please use: scripts/setup/reset-database.sh
#
echo ""
echo "⚠️  DEPRECATED: This script has been moved to scripts/setup/"
echo "📍 New location: scripts/setup/reset-database.sh"
echo "🔄 Redirecting to new location..."
echo ""

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Execute the actual script
exec "${SCRIPT_DIR}/scripts/setup/reset-database.sh" "$@"
