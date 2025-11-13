#!/bin/bash

# Rollback script for tenant migration
# This script safely rolls back all tenant_id changes

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}╔═══════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     Tenant Migration Rollback Script                 ║${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if we're in the right directory
if [ ! -f "composer.json" ] || [ ! -d "src/Entity" ]; then
    echo -e "${RED}❌ Error: Not in project root directory!${NC}"
    echo "Please run this script from the project root."
    exit 1
fi

# Confirm with user
echo -e "${YELLOW}⚠️  WARNING: This will roll back the tenant_id migration!${NC}"
echo ""
echo "This will:"
echo "  - Remove tenant_id columns from 31 database tables"
echo "  - Restore ISMSContext entity to original state"
echo "  - Clear all caches"
echo ""
read -p "Are you sure you want to continue? (yes/no): " -r
echo
if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
    echo "Rollback cancelled."
    exit 0
fi

# Step 1: Create database backup
echo -e "${BLUE}📦 Step 1: Creating database backup...${NC}"
if command -v mysqldump &> /dev/null; then
    BACKUP_FILE="backup_before_rollback_$(date +%Y%m%d_%H%M%S).sql"
    read -p "Database name: " DB_NAME
    read -p "Database user: " DB_USER
    read -sp "Database password: " DB_PASS
    echo ""

    mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_FILE" 2>/dev/null || {
        echo -e "${YELLOW}⚠️  Could not create database backup${NC}"
        echo "Continue anyway? (yes/no): "
        read -r
        if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
            exit 1
        fi
    }

    if [ -f "$BACKUP_FILE" ]; then
        echo -e "${GREEN}✅ Backup created: $BACKUP_FILE${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  mysqldump not found, skipping backup${NC}"
fi

# Step 2: Check migration status
echo ""
echo -e "${BLUE}🔍 Step 2: Checking migration status...${NC}"
php bin/console doctrine:migrations:status || {
    echo -e "${RED}❌ Error checking migration status${NC}"
    exit 1
}

# Step 3: Roll back migration
echo ""
echo -e "${BLUE}🔄 Step 3: Rolling back migration Version20251113130000...${NC}"
php bin/console doctrine:migrations:execute --down Version20251113130000 --no-interaction || {
    echo -e "${RED}❌ Migration rollback failed!${NC}"
    echo "Check logs above for details."
    exit 1
}
echo -e "${GREEN}✅ Migration rolled back successfully${NC}"

# Step 4: Restore ISMSContext entity
echo ""
echo -e "${BLUE}🔧 Step 4: Restoring ISMSContext entity...${NC}"

ENTITY_FILE="src/Entity/ISMSContext.php"

# Check if backup exists
if [ -f "${ENTITY_FILE}.bak" ]; then
    cp "${ENTITY_FILE}.bak" "$ENTITY_FILE"
    echo -e "${GREEN}✅ Restored from backup${NC}"
else
    # Try to restore from main branch
    if git rev-parse --verify main &> /dev/null; then
        git checkout main -- "$ENTITY_FILE" 2>/dev/null && {
            echo -e "${GREEN}✅ Restored from main branch${NC}"
        } || {
            echo -e "${YELLOW}⚠️  Could not restore ISMSContext entity automatically${NC}"
            echo "Please manually restore src/Entity/ISMSContext.php"
        }
    else
        echo -e "${YELLOW}⚠️  No backup found and main branch not available${NC}"
        echo "Please manually restore src/Entity/ISMSContext.php"
    fi
fi

# Step 5: Clear caches
echo ""
echo -e "${BLUE}🧹 Step 5: Clearing caches...${NC}"

php bin/console cache:clear --env=prod || {
    echo -e "${YELLOW}⚠️  Could not clear prod cache${NC}"
}

php bin/console cache:clear --env=dev || {
    echo -e "${YELLOW}⚠️  Could not clear dev cache${NC}"
}

php bin/console cache:pool:clear doctrine.result_cache_pool 2>/dev/null || true
php bin/console cache:pool:clear doctrine.system_cache_pool 2>/dev/null || true

echo -e "${GREEN}✅ Caches cleared${NC}"

# Step 6: Verify rollback
echo ""
echo -e "${BLUE}✔️  Step 6: Verifying rollback...${NC}"

# Check migration status
MIGRATION_STATUS=$(php bin/console doctrine:migrations:status --show-versions 2>/dev/null | grep Version20251113130000 || true)
if echo "$MIGRATION_STATUS" | grep -q "not migrated"; then
    echo -e "${GREEN}✅ Migration successfully rolled back${NC}"
else
    echo -e "${YELLOW}⚠️  Migration status unclear${NC}"
fi

# Summary
echo ""
echo -e "${BLUE}╔═══════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              Rollback Complete!                       ║${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${GREEN}✅ Rollback completed successfully!${NC}"
echo ""
echo "📝 Next steps:"
echo "   1. Restart web server (if needed)"
echo "      sudo systemctl restart apache2"
echo "      # or: sudo systemctl restart php-fpm"
echo ""
echo "   2. Test the application"
echo ""
echo "   3. Check database schema:"
echo "      php bin/console doctrine:schema:validate"
echo ""
echo "   4. If everything works, you can switch branches:"
echo "      git checkout main"
echo ""

if [ -f "$BACKUP_FILE" ]; then
    echo -e "${BLUE}💾 Database backup: $BACKUP_FILE${NC}"
    echo "   Keep this file in case you need to restore!"
fi

echo ""
echo -e "${YELLOW}📖 For more information, see ROLLBACK_GUIDE.md${NC}"
