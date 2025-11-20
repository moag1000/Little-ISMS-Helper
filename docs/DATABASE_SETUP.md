# Database Setup Guide

## For Fresh Installation (Production/Staging)

Use the migration-based approach to ensure all schema changes are tracked:

```bash
./setup_fresh_db.sh
```

Or manually:
```bash
php bin/console doctrine:database:drop --force --if-exists
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
```

**When to use:**
- First time setup in production
- Staging environment setup
- When you need full migration history

## For Development (Faster Setup)

Use the schema-based approach for quicker setup during development:

```bash
./setup_dev_db.sh
```

Or manually:
```bash
php bin/console doctrine:database:drop --force --if-exists
php bin/console doctrine:database:create
php bin/console doctrine:schema:create
php bin/console doctrine:migrations:version --add --all --no-interaction
```

**When to use:**
- Local development environment
- Quick database reset during development
- Testing different schema states

## Troubleshooting

### Error: "Table already exists"

This happens when:
1. Database is not empty
2. Previous migration failed mid-way
3. Manual table creation was done

**Solution:**
Always start with a completely empty database using one of the methods above.

### Error: "Migration already executed"

If you see this error, check migration status:
```bash
php bin/console doctrine:migrations:status
```

To reset migrations (⚠️ **DANGEROUS - only for fresh setup**):
```bash
php bin/console doctrine:database:drop --force
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
```

### Verify Database State

Check if schema is in sync with entities:
```bash
php bin/console doctrine:schema:validate
```

## Initial Data Setup

After database creation, you may need to:

1. **Create first Super Admin tenant and user:**
   ```bash
   php bin/console app:create-initial-tenant
   ```

2. **Load ISO 27001 controls (if applicable):**
   ```bash
   php bin/console app:import:iso27001-controls
   ```

3. **Load compliance frameworks:**
   ```bash
   php bin/console app:import:compliance-frameworks
   ```

## Production Deployment

For production deployments, **always use migrations**:

```bash
# Run pending migrations only
php bin/console doctrine:migrations:migrate --no-interaction

# Verify schema
php bin/console doctrine:schema:validate
```

**Never use `doctrine:schema:update` in production!**
