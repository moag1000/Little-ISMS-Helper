#!/bin/bash

################################################################################
# Little ISMS Helper - License Report Generator
################################################################################
#
# Erstellt einen umfassenden Lizenzbericht für alle Abhängigkeiten:
# - PHP-Pakete (Composer)
# - JavaScript-Pakete (Symfony ImportMap)
# - Manuell eingebundene Pakete
#
# Ausgabe: docs/reports/license-report.md
#
################################################################################

set -e

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Project root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║          Little ISMS Helper - Lizenzbericht                   ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo -e "${RED}✗ Node.js ist nicht installiert!${NC}"
    echo -e "${YELLOW}  Bitte installieren Sie Node.js, um den Lizenzbericht zu erstellen.${NC}"
    exit 1
fi

echo -e "${BLUE}→${NC} Analysiere Abhängigkeiten..."
echo ""

# Run the license report script
if node bin/license-report.js; then
    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║                  ✓ Erfolgreich abgeschlossen!                 ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${BLUE}→${NC} Bericht verfügbar unter: ${GREEN}docs/reports/license-report.md${NC}"
    echo ""
    echo -e "${YELLOW}Tipp:${NC} Sie können den Bericht mit folgendem Befehl anzeigen:"
    echo -e "  ${BLUE}cat docs/reports/license-report.md${NC}"
    echo -e "  oder mit einem Markdown-Viewer öffnen"
    echo ""
else
    echo ""
    echo -e "${RED}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${RED}║                      ✗ Fehler aufgetreten!                    ║${NC}"
    echo -e "${RED}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${YELLOW}Mögliche Ursachen:${NC}"
    echo -e "  • Composer ist nicht installiert oder nicht im PATH"
    echo -e "  • composer.lock ist nicht vorhanden"
    echo -e "  • Keine Schreibrechte für docs/reports/"
    echo ""
    exit 1
fi
