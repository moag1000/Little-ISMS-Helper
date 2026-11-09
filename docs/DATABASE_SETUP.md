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

After the schema exists, open `/setup`. The setup wizard creates the first organisation and admin
(`ROLE_SUPER_ADMIN`), loads the ISO 27001 controls and the chosen frameworks
([QUICKSTART.md](QUICKSTART.md)). Without the wizard:

```bash
php bin/console app:setup-permissions --admin-email=admin@example.com --admin-password='…'
php bin/console isms:load-annex-a-controls         # 93 ISO 27001 Annex A controls
php bin/console app:load-iso27001-requirements     # further loaders: php bin/console list app:load
```

## Production Deployment

For production deployments, **always use migrations**:

```bash
# Run pending migrations only
php bin/console doctrine:migrations:migrate --no-interaction

# Verify schema
php bin/console doctrine:schema:validate
```

Do not use `doctrine:schema:update` in production. Schema drift after an update is repaired with
`app:schema:reconcile` or in the browser at `/quick-fix` ([user-guide/QUICK_FIX.md](user-guide/QUICK_FIX.md)).
