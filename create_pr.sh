#!/bin/bash

# GitHub PR erstellen für Phase 5
# Verwendung: ./create_pr.sh

echo "🚀 Erstelle Pull Request für Phase 5..."
echo ""

# Wähle PR-Beschreibung
echo "Welche Beschreibung möchten Sie verwenden?"
echo "1) Vollständig (PR_PHASE5_COMPLETE.md - 190 Zeilen)"
echo "2) Kompakt (PR_PHASE5_SHORT.md - 69 Zeilen)"
echo ""
read -p "Auswahl (1 oder 2): " choice

if [ "$choice" = "1" ]; then
    BODY_FILE="PR_PHASE5_COMPLETE.md"
elif [ "$choice" = "2" ]; then
    BODY_FILE="PR_PHASE5_SHORT.md"
else
    echo "❌ Ungültige Auswahl"
    exit 1
fi

# GitHub CLI Command
gh pr create \
    --base main \
    --head claude/review-implementation-011CUtM3CCyTQqwETurnUkYo \
    --title "Phase 5 - 100% Complete: Drag & Drop Features + Final Polish" \
    --body-file "$BODY_FILE" \
    --assignee @me

echo ""
echo "✅ PR erstellt!"
