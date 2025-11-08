#!/bin/bash
# Database Reset and Migration Script
# Use this to clean up and re-run migrations after fixing migration issues

set -e

echo "=========================================="
echo "Database Reset & Migration Tool"
echo "=========================================="
echo ""

# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Functions
success() { echo -e "${GREEN}✓ $1${NC}"; }
error() { echo -e "${RED}✗ $1${NC}"; }
warning() { echo -e "${YELLOW}⚠ $1${NC}"; }
info() { echo "→ $1"; }

# Check if .env.local exists
if [ ! -f ".env.local" ]; then
    error ".env.local not found!"
    echo ""
    echo "Please create .env.local first:"
    echo "  cp .env .env.local"
    echo "  echo \"APP_SECRET=\$(openssl rand -hex 32)\" >> .env.local"
    exit 1
fi

# Get database URL from .env.local
DB_URL=$(grep "^DATABASE_URL=" .env.local | head -1 | cut -d '=' -f 2- | tr -d '"')

if [ -z "$DB_URL" ]; then
    # Fall back to .env
    DB_URL=$(grep "^DATABASE_URL=" .env | head -1 | cut -d '=' -f 2- | tr -d '"')
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
elif [[ $DB_URL == mysql* ]]; then
    DB_TYPE="mysql"
elif [[ $DB_URL == postgresql* ]]; then
    DB_TYPE="postgresql"
else
    error "Unknown database type in DATABASE_URL"
    exit 1
fi

warning "This script will:"
echo "  1. Drop the existing database"
echo "  2. Create a new database"
echo "  3. Run all migrations"
echo "  4. (Optional) Load default roles & permissions"
echo "  5. (Optional) Create admin user"
echo ""

read -p "Continue? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    warning "Operation cancelled."
    exit 0
fi

echo ""
info "Step 1: Dropping existing database..."

if [ "$DB_TYPE" = "sqlite" ]; then
    if [ -f "$DB_FILE" ]; then
        rm "$DB_FILE"
        success "SQLite database deleted: $DB_FILE"
    else
        warning "SQLite database not found: $DB_FILE (will be created)"
    fi
else
    php bin/console doctrine:database:drop --force 2>/dev/null || warning "Database did not exist"
    success "Database dropped"
fi

echo ""
info "Step 2: Creating database..."
php bin/console doctrine:database:create
success "Database created"

echo ""
info "Step 3: Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction
success "Migrations completed"

echo ""
info "Step 4: Loading default roles & permissions..."
read -p "Run app:setup-permissions? (Y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    echo ""
    read -p "Create admin user? (Y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
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
        echo "  Password: ******* (hidden)"
    else
        php bin/console app:setup-permissions
        success "Roles and permissions created"
    fi
else
    warning "Skipped setup-permissions"
fi

echo ""
info "Step 5: (Optional) Load ISO 27001 Controls..."
read -p "Run isms:load-annex-a-controls? (Y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    php bin/console isms:load-annex-a-controls
    success "ISO 27001 Controls loaded"
else
    warning "Skipped loading controls"
fi

echo ""
echo "=========================================="
success "Database setup completed successfully!"
echo "=========================================="
echo ""
info "Next steps:"
echo "  1. Start the server: symfony serve"
echo "  2. Or use: php -S localhost:8000 -t public/"
echo "  3. Open: http://localhost:8000"
echo ""
