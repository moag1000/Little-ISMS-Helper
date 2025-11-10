#!/bin/bash

# Deployment-Check-Script für Little ISMS Helper auf Plesk
# Dieses Script prüft, ob alle kritischen Deployment-Schritte korrekt durchgeführt wurden

echo "======================================"
echo "Little ISMS Helper - Deployment Check"
echo "======================================"
echo ""

# Farben für Output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

ERRORS=0
WARNINGS=0

# Funktion für Checks
check_success() {
    echo -e "${GREEN}✓${NC} $1"
}

check_error() {
    echo -e "${RED}✗${NC} $1"
    ((ERRORS++))
}

check_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
    ((WARNINGS++))
}

# 1. Check: Sind wir im richtigen Verzeichnis?
echo "1. Prüfe Verzeichnis..."
if [ -f "composer.json" ] && [ -d "src" ] && [ -d "public" ]; then
    check_success "Symfony-Projektverzeichnis gefunden"
else
    check_error "Nicht im Symfony-Projektverzeichnis! Bitte zu /var/www/vhosts/.../httpdocs wechseln"
    exit 1
fi

# 2. Check: PHP Version
echo ""
echo "2. Prüfe PHP-Version..."
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

if [ "$PHP_MAJOR" -gt 8 ] || ([ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -ge 2 ]); then
    check_success "PHP $PHP_VERSION (>= 8.2 erforderlich)"
else
    check_error "PHP $PHP_VERSION ist zu alt! Mindestens 8.2 erforderlich"
fi

# 3. Check: Composer installiert
echo ""
echo "3. Prüfe Composer..."
if command -v composer &> /dev/null; then
    COMPOSER_VERSION=$(composer --version 2>/dev/null | head -n1)
    check_success "Composer gefunden: $COMPOSER_VERSION"
else
    check_warning "Composer nicht gefunden (möglicherweise als composer.phar vorhanden)"
fi

# 4. Check: vendor Verzeichnis
echo ""
echo "4. Prüfe Dependencies..."
if [ -d "vendor" ] && [ -f "vendor/autoload.php" ]; then
    check_success "Vendor-Verzeichnis existiert"
else
    check_error "vendor/ fehlt! Bitte 'composer install --no-dev --optimize-autoloader' ausführen"
fi

# 5. Check: APP_ENV
echo ""
echo "5. Prüfe Umgebungskonfiguration..."
if [ -f ".env.local" ]; then
    if grep -q "APP_ENV=prod" .env.local; then
        check_success ".env.local mit APP_ENV=prod gefunden"
    else
        check_error ".env.local existiert, aber APP_ENV ist nicht auf 'prod' gesetzt"
    fi
else
    check_error ".env.local fehlt! KRITISCH: Erstellen Sie diese Datei mit APP_ENV=prod"
fi

# 6. Check: APP_SECRET
if [ -f ".env.local" ]; then
    if grep -q "APP_SECRET=CHANGE" .env.local; then
        check_error "APP_SECRET wurde nicht geändert! Bitte sicheren Zufallsstring setzen"
    elif grep -q "APP_SECRET=" .env.local; then
        check_success "APP_SECRET ist gesetzt"
    else
        check_warning "APP_SECRET nicht in .env.local gefunden"
    fi
fi

# 7. Check: DATABASE_URL
if [ -f ".env.local" ] && grep -q "DATABASE_URL=" .env.local; then
    check_success "DATABASE_URL ist konfiguriert"
else
    check_warning "DATABASE_URL nicht in .env.local gefunden"
fi

# 8. Check: public/.htaccess
echo ""
echo "6. Prüfe Apache-Konfiguration..."
if [ -f "public/.htaccess" ]; then
    check_success "public/.htaccess existiert"
else
    check_error "public/.htaccess fehlt!"
fi

# 9. Check: public/index.php
if [ -f "public/index.php" ]; then
    check_success "public/index.php existiert"
else
    check_error "public/index.php fehlt!"
fi

# 10. Check: Assets installiert (KRITISCH!)
echo ""
echo "7. Prüfe Assets (CSS/JS)..."
if [ -d "public/assets" ]; then
    ASSET_COUNT=$(find public/assets -type f 2>/dev/null | wc -l)
    if [ "$ASSET_COUNT" -gt 0 ]; then
        check_success "public/assets/ existiert mit $ASSET_COUNT Dateien"
    else
        check_error "public/assets/ ist leer! Bitte 'php bin/console assets:install public' ausführen"
    fi
else
    check_error "public/assets/ fehlt! KRITISCH: Bitte 'php bin/console assets:install public' ausführen"
fi

# 11. Check: var/cache Verzeichnis
echo ""
echo "8. Prüfe Cache..."
if [ -d "var/cache" ]; then
    if [ -w "var/cache" ]; then
        check_success "var/cache ist schreibbar"
    else
        check_error "var/cache ist nicht schreibbar! Bitte 'chmod 775 var/cache' ausführen"
    fi
else
    check_warning "var/cache fehlt (wird beim ersten Aufruf erstellt)"
fi

# 12. Check: var/log Verzeichnis
if [ -d "var/log" ]; then
    if [ -w "var/log" ]; then
        check_success "var/log ist schreibbar"
    else
        check_error "var/log ist nicht schreibbar! Bitte 'chmod 775 var/log' ausführen"
    fi
else
    check_warning "var/log fehlt (wird beim ersten Aufruf erstellt)"
fi

# 13. Check: Symfony Umgebung
echo ""
echo "9. Prüfe Symfony-Konfiguration..."
if [ -x "bin/console" ]; then
    ENV_INFO=$(php bin/console about 2>/dev/null | grep "Environment" | awk '{print $2}')
    if [ "$ENV_INFO" = "prod" ]; then
        check_success "Symfony läuft im Production-Modus"
    elif [ "$ENV_INFO" = "dev" ]; then
        check_error "Symfony läuft im Development-Modus! KRITISCH: .env.local mit APP_ENV=prod erstellen"
    else
        check_warning "Konnte Symfony-Umgebung nicht prüfen (möglicherweise Cache-Problem)"
    fi
else
    check_error "bin/console ist nicht ausführbar! Bitte 'chmod +x bin/console' ausführen"
fi

# 14. Check: PHP Extensions
echo ""
echo "10. Prüfe PHP-Erweiterungen..."
REQUIRED_EXTENSIONS=("pdo" "mbstring" "xml" "intl" "zip" "opcache")
MISSING_EXTENSIONS=()

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if php -m | grep -q "^$ext$"; then
        check_success "$ext ist installiert"
    else
        check_error "$ext fehlt!"
        MISSING_EXTENSIONS+=("$ext")
    fi
done

# Zusammenfassung
echo ""
echo "======================================"
echo "Zusammenfassung"
echo "======================================"

if [ $ERRORS -eq 0 ] && [ $WARNINGS -eq 0 ]; then
    echo -e "${GREEN}✓ Alle Checks bestanden!${NC}"
    echo "Ihre Anwendung sollte korrekt deployt sein."
    exit 0
elif [ $ERRORS -eq 0 ]; then
    echo -e "${YELLOW}⚠ Deployment OK mit $WARNINGS Warnungen${NC}"
    echo "Die Anwendung sollte funktionieren, aber es gibt kleinere Probleme."
    exit 0
else
    echo -e "${RED}✗ $ERRORS kritische Fehler gefunden!${NC}"
    if [ $WARNINGS -gt 0 ]; then
        echo -e "${YELLOW}⚠ $WARNINGS Warnungen${NC}"
    fi
    echo ""
    echo "Bitte beheben Sie die Fehler und führen Sie dieses Script erneut aus."
    echo ""
    echo "Häufigste Lösungen:"
    echo "1. Assets installieren: php bin/console assets:install public && php bin/console importmap:install"
    echo "2. .env.local erstellen: cp .env.prod.example .env.local"
    echo "3. Cache leeren: php bin/console cache:clear --env=prod"
    echo "4. Dateirechte setzen: chmod -R 775 var/cache var/log"
    exit 1
fi
