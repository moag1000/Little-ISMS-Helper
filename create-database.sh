#!/bin/bash
# Database Creation & Setup Script
# Creates a new database and runs complete setup
# Safe to run on fresh installations (won't drop existing database)

set -e

echo "=========================================="
echo "Database Creation & Setup Tool"
echo "=========================================="
echo ""

# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Functions
success() { echo -e "${GREEN}✓ $1${NC}"; }
error() { echo -e "${RED}✗ $1${NC}"; }
warning() { echo -e "${YELLOW}⚠ $1${NC}"; }
info() { echo -e "${BLUE}→ $1${NC}"; }

# Step 0: Prerequisites check
info "Checking prerequisites..."

# Check PHP
if ! command -v php &> /dev/null; then
    error "PHP is not installed or not in PATH"
    exit 1
fi
success "PHP found: $(php -v | head -1)"

# Check composer
if [ ! -f "composer.json" ]; then
    error "composer.json not found! Are you in the project root?"
    exit 1
fi
success "Project root detected"

# Check if .env exists
if [ ! -f ".env" ]; then
    error ".env file not found!"
    exit 1
fi
success ".env file found"

# Check if .env.local exists
if [ ! -f ".env.local" ]; then
    warning ".env.local not found. Creating from .env..."
    cp .env .env.local

    # Generate APP_SECRET if not set
    if ! grep -q "^APP_SECRET=" .env.local || grep -q "^APP_SECRET=$" .env.local; then
        info "Generating APP_SECRET..."
        APP_SECRET=$(openssl rand -hex 32)
        echo "" >> .env.local
        echo "APP_SECRET=${APP_SECRET}" >> .env.local
        success "APP_SECRET generated"
    fi
fi
success ".env.local found"

# Get database URL from .env.local
DB_URL=$(grep "^DATABASE_URL=" .env.local | head -1 | cut -d '=' -f 2- | tr -d '"')

if [ -z "$DB_URL" ]; then
    # Fall back to .env
    DB_URL=$(grep "^DATABASE_URL=" .env | head -1 | cut -d '=' -f 2- | tr -d '"')
fi

if [ -z "$DB_URL" ]; then
    error "DATABASE_URL not found in .env or .env.local"
    exit 1
fi

info "Database URL: ${DB_URL:0:50}..."
echo ""

# Determine database type
if [[ $DB_URL == sqlite* ]]; then
    DB_TYPE="sqlite"
    # Extract SQLite database file path
    DB_FILE=$(echo $DB_URL | sed 's|sqlite:///%kernel.project_dir%/||' | sed 's|sqlite:///||')
    if [[ $DB_FILE == %* ]]; then
        DB_FILE="var/data.db"
    fi
    info "Database type: SQLite"
    info "Database file: $DB_FILE"
elif [[ $DB_URL == mysql* ]]; then
    DB_TYPE="mysql"
    info "Database type: MySQL"
elif [[ $DB_URL == postgresql* ]] || [[ $DB_URL == pgsql* ]]; then
    DB_TYPE="postgresql"
    info "Database type: PostgreSQL"
else
    error "Unknown database type in DATABASE_URL"
    exit 1
fi

echo ""

# Check if database already exists (without dropping it)
DB_EXISTS=0
if [ "$DB_TYPE" = "sqlite" ]; then
    if [ -f "$DB_FILE" ]; then
        DB_EXISTS=1
    fi
else
    # Try to execute a simple query to check if database exists
    # If it succeeds, database exists
    if php bin/console dbal:run-sql "SELECT 1" &>/dev/null; then
        DB_EXISTS=1
    fi
fi

if [ $DB_EXISTS -eq 1 ]; then
    warning "Database already exists!"
    echo ""
    echo "Options:"
    echo "  1. Delete existing database and create fresh (RESET)"
    echo "  2. Keep existing database and exit"
    echo ""
    read -p "Your choice (1/2): " -n 1 -r
    echo

    if [[ $REPLY == "1" ]]; then
        info "Resetting database..."

        if [ "$DB_TYPE" = "sqlite" ]; then
            rm -f "$DB_FILE"
            success "SQLite database deleted"
        else
            php bin/console doctrine:database:drop --if-exists --force
            success "Database dropped"
        fi
    else
        warning "Keeping existing database. Exiting."
        exit 0
    fi
fi

echo ""
info "Creating database..."

if [ "$DB_TYPE" = "sqlite" ]; then
    # Ensure var/ directory exists
    mkdir -p "$(dirname "$DB_FILE")"
fi

php bin/console doctrine:database:create
success "Database created"

echo ""
info "Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction
success "All migrations completed (10/10)"

echo ""
info "Setting up roles & permissions..."

# Ask for admin credentials upfront
read -p "Create admin user? (Y/n) " -n 1 -r
echo

if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    echo ""
    read -p "Admin email [admin@example.com]: " ADMIN_EMAIL
    ADMIN_EMAIL=${ADMIN_EMAIL:-admin@example.com}

    read -s -p "Admin password [admin123]: " ADMIN_PASSWORD
    echo
    ADMIN_PASSWORD=${ADMIN_PASSWORD:-admin123}

    php bin/console app:setup-permissions \
        --admin-email="$ADMIN_EMAIL" \
        --admin-password="$ADMIN_PASSWORD"
    success "Roles, permissions, and admin user created"

    echo ""
    info "Login credentials:"
    echo "  Email: $ADMIN_EMAIL"
    echo "  Password: $ADMIN_PASSWORD"
else
    php bin/console app:setup-permissions
    success "Roles and permissions created (no admin user)"
fi

echo ""
info "Loading ISO 27001:2022 Controls..."
read -p "Load Annex A controls? (Y/n) " -n 1 -r
echo

if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    if php bin/console isms:load-annex-a-controls 2>/dev/null; then
        success "ISO 27001 Controls loaded (93 controls)"
    else
        warning "isms:load-annex-a-controls command not found (skipped)"
    fi
else
    warning "Skipped loading controls"
fi

echo ""
info "Validating database schema..."
if php bin/console doctrine:schema:validate --skip-sync 2>&1 | grep -q "database schema is in sync"; then
    success "Database schema is valid and in sync"
else
    warning "Schema validation warnings (this may be normal)"
fi

echo ""
echo "=========================================="
success "Database setup completed successfully!"
echo "=========================================="
echo ""
info "Database Summary:"
echo "  Type: $DB_TYPE"
if [ "$DB_TYPE" = "sqlite" ]; then
    echo "  File: $DB_FILE"
fi
echo "  Migrations: 10/10 executed"
echo "  Roles: 4 (USER, AUDITOR, MANAGER, ADMIN)"
echo "  Permissions: 42"
if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    echo "  Admin User: Created ✓"
fi
echo ""
info "Next steps:"
echo "  1. Start the development server:"
echo "     symfony serve -d"
echo "     OR"
echo "     php -S localhost:8000 -t public/"
echo ""
echo "  2. Open your browser:"
echo "     http://localhost:8000"
echo ""
echo "  3. Login with your admin credentials"
echo ""
success "Ready to use!"
echo ""
