# 01 — Environment Setup

This guide covers three paths to a running development environment: Docker,
native PHP-FPM, and shared-hosting. Pick the one that matches your setup.

## Prerequisites Checklist

- PHP 8.4+ with extensions: `intl`, `mbstring`, `pdo_mysql`, `xml`, `curl`, `zip`, `gd`
- Composer 2.x
- MySQL 8.0+ / MariaDB 10.11+ / PostgreSQL 16+
- Node.js 20+ (for `importmap:install` asset pipeline)
- Git

---

## Path A — Docker (Recommended for New Contributors)

The project ships with three compose files:

| File | Purpose |
|---|---|
| `docker-compose.yml` | Base definition (app + db + mail) |
| `docker-compose.dev.yml` | Dev override: bind-mount, Xdebug, hot-reload |
| `docker-compose.prod.yml` | Production override: optimised build, no Xdebug |

### Start the development stack

```bash
# Start all services (app, MySQL, Mailpit for local mail capture)
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Check that all containers are healthy
docker compose ps

# Open a shell in the app container
docker compose exec app bash
```

### Inside the container: first-time bootstrap

```bash
composer install
php bin/console importmap:install
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console isms:load-annex-a-controls
php bin/console app:generate-regulatory-workflows
```

The app is now available at `http://localhost:8080` (or whichever port is mapped in
`docker-compose.yml`).

### Key Docker notes

- The dev override uses a bind-mount (`./:/var/www/html:cached`), so code changes on the
  host are reflected immediately — no container rebuild required.
- `vendor/` and `var/cache/` are named volumes to avoid performance penalties on macOS.
- Xdebug is enabled in the `development` build target. Set `XDEBUG_MODE=debug` in your
  IDE run configuration and connect to port 9003.

---

## Path B — Native PHP-FPM + Nginx (Local or CI Server)

### Nginx virtual host (minimal)

```nginx
server {
    server_name isms.local;
    root /var/www/little-isms/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }
}
```

### Symfony CLI (alternative to nginx)

```bash
symfony serve          # HTTPS on 127.0.0.1:8000 (auto-cert via Symfony CA)
symfony serve --no-tls # HTTP only
```

### First-time bootstrap (native)

```bash
cp .env .env.local
# Set DATABASE_URL, APP_SECRET, MAILER_DSN in .env.local

composer install
php bin/console importmap:install

php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction

# Load ISO 27001 Annex A controls and regulatory workflow definitions
php bin/console isms:load-annex-a-controls
php bin/console app:generate-regulatory-workflows

# Optional: load BSI IT-Grundschutz catalogue (large import, ~2 min)
php bin/console app:import-bsi-kompendium-xml path/to/kompendium.xml
```

---

## Path C — Shared Hosting (No Shell Persistent Workers)

Shared-hosting deployments cannot run long-lived processes. The application
handles this with the `InRequestJobRunner` strategy (default).

### Key differences vs dedicated hosting

| Feature | Shared Hosting | Dedicated |
|---|---|---|
| Async jobs | `fastcgi_finish_request()` detach (default) | Symfony Messenger worker |
| Queue monitoring | Not applicable | `/admin/queue-status` |
| Cache warm-up | Must run via cron or web UI | `cache:warmup` on deploy |

### `.htaccess` (Apache shared hosting)

The `public/` directory contains a pre-configured `.htaccess`. Ensure:

```
AllowOverride All
```

is set for the document root in your hosting panel.

### Setting `InRequestJobRunner` (already the default)

```dotenv
# .env.local — leave unset or set explicitly:
APP_ASYNC_JOB_RUNNER=in_request
```

For dedicated servers with a Messenger worker:

```dotenv
APP_ASYNC_JOB_RUNNER=messenger
```

---

## Database Bootstrapping

### Fresh install

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
```

### Schema-only (skip migration history, e.g. local dev reset)

```bash
php bin/console doctrine:schema:create
# WARNING: loses all data; dev only
```

### Recovery from schema drift

If columns are missing or the schema diverges from entity metadata (common
after branch switches or rebased migrations):

```bash
php bin/console app:schema:reconcile --dry-run  # preview changes
php bin/console app:schema:reconcile             # apply non-destructive changes
```

The web-based `/quick-fix` operator UI provides the same buttons without CLI
access. See `docs/user-guide/QUICK_FIX.md`.

---

## Sample / Seed Data

### Screenshot personas (realistic multi-role seed)

```bash
php bin/console app:create-screenshot-user
```

Creates demo users for each RBAC persona (ADMIN, MANAGER, AUDITOR, CISO, DPO,
RISK_MANAGER, COMPLIANCE_MANAGER). Useful for local testing of persona-gated
dashboards.

### Reference data (required for normal operation)

```bash
php bin/console isms:load-annex-a-controls          # 93 ISO 27001 Annex A controls
php bin/console app:load-bsi-grundschutz-variants   # BSI module variants
php bin/console app:generate-regulatory-workflows   # 15 regulatory workflow definitions
```

---

## Common Pitfalls

### PREPARE/EXECUTE migrations silently fail

Seventeen legacy migrations (Phase 8, `20260418*`-`20260420*`) use a
`SET @sql := IF(...); PREPARE; EXECUTE; DEALLOCATE` pattern. Doctrine records
the migration as executed but the DDL never runs. Symptoms: `Column not found`.

**Do not copy this pattern.** New migrations must use plain `ALTER TABLE` /
`CREATE TABLE IF NOT EXISTS`.

### DDL migrations need `isTransactional(): false`

MySQL implicitly commits on `ALTER TABLE` / `CREATE TABLE`, which invalidates
Doctrine's per-migration `SAVEPOINT`. Running more than one DDL migration in a
single `migrate` call fails with `SAVEPOINT DOCTRINE_X does not exist`.

Add this override to every migration that contains DDL:

```php
public function isTransactional(): bool
{
    return false;
}
```

`doctrine:migrations:diff` does **not** add this automatically. Check after
every diff-generated migration.

### PHP OPcache stale after deploy

```bash
php bin/console cache:clear
# or touch public/index.php to invalidate OPcache
```

### Missing `intl` extension

The app uses `intl` for locale-aware formatting. Ensure `extension=intl` is
enabled in `php.ini`. PHP will start without it but will fail at runtime on
number/date formatters.
