# Little ISMS Helper - Docker Setup Guide

## Overview

| File | Purpose |
|---|---|
| `docker-compose.prod.yml` | Self-contained single container: nginx + PHP 8.5-FPM + embedded MariaDB + scheduler worker. Use this for production **and** for a quick local test. Guide: [DOCKER_PRODUCTION.md](../deployment/DOCKER_PRODUCTION.md) |
| `docker-compose.yml` | App container with an external database service, MailHog and pgAdmin |
| `docker-compose.dev.yml` | Override for `docker-compose.yml`: `development` build stage (Xdebug), source bind mount |

> **Database:** the application supports MySQL 8 / MariaDB 10.6+ only (migrations use MySQL DDL,
> restore uses `SET FOREIGN_KEY_CHECKS`; CI tests MariaDB 11). `docker-compose.yml` currently starts
> PostgreSQL 16, on which the migrations fail. Until that file is switched to MariaDB, use
> `docker-compose.prod.yml` (embedded MariaDB) or point `DATABASE_URL` at a MySQL/MariaDB server.

## Prerequisites

- Docker 24+ with the Compose plugin (`docker compose`)
- 4 GB RAM for Docker

## Quick Start (embedded MariaDB)

```bash
git clone https://github.com/moag1000/Little-ISMS-Helper.git
cd Little-ISMS-Helper
APP_PORT=8000 APP_PORT_HTTPS=8443 docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml logs -f
```

Open `http://localhost:8000/setup`. The setup wizard configures the embedded database, runs the
migrations, creates the admin, loads modules, frameworks and (optionally) sample data
([QUICKSTART.md](../QUICKSTART.md#5-setup-wizard-durchlaufen)).

The `isms_data` volume (`/var/www/html/var`) keeps the database, `var/uploads` (evidence files),
backups and the generated `APP_SECRET` across restarts. Logos and profile pictures live in
`public/uploads/` and need their own volume (see DOCKER_PRODUCTION.md).

## Development with an external MySQL/MariaDB

`docker-compose.yml` + `docker-compose.dev.yml` set `EMBEDDED_DB=none`; the app then uses
`DATABASE_URL` and does not start its own MariaDB. Set `DATABASE_URL` for the `app` service in an
override file to a MySQL/MariaDB server, e.g.
`mysql://isms:secret@db-host:3306/isms?serverVersion=mariadb-11.4.0&charset=utf8mb4`, then:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml -f docker-compose.override.yml up -d
```

Services from `docker-compose.yml`:

| Service | URL | Notes |
|---|---|---|
| app | http://localhost:8000 | `isms-app`; API docs at `/api/docs` |
| mailhog | http://localhost:8025 (SMTP `mailhog:1025`) | Catches all outgoing mail |
| pgadmin | http://localhost:5050 | Only useful with the PostgreSQL service |

## Common Commands

```bash
docker compose exec app php bin/console cache:clear
docker compose exec app php bin/console doctrine:migrations:status
docker compose exec app php bin/phpunit
docker compose exec app composer install
docker compose exec app supervisorctl status     # nginx, php-fpm, mariadb, messenger-scheduler
docker compose logs -f app
docker compose down        # keeps volumes
docker compose down -v     # deletes volumes and all data
```

## Xdebug (development stage)

Configured in the `development` stage of the `Dockerfile`: port 9003, `xdebug.mode=debug,coverage`,
`client_host=host.docker.internal`, `start_with_request=yes`.

VS Code `launch.json`:

```json
{
  "name": "Listen for Xdebug (Docker)",
  "type": "php",
  "request": "launch",
  "port": 9003,
  "pathMappings": { "/var/www/html": "${workspaceFolder}" }
}
```

PhpStorm: Settings → PHP → Servers, host `localhost`, port `8000`, path mapping
`/var/www/html` → project root.

## Health Check

```bash
curl http://localhost:8000/health
```

## Troubleshooting

| Issue | Fix |
|---|---|
| Port already in use | `APP_PORT=8080` (and `APP_PORT_HTTPS`) when starting |
| Permission denied in `var/` | `docker compose exec app chown -R www-data:www-data /var/www/html/var` |
| Database connection failed | `docker compose exec app php bin/console dbal:run-sql "SELECT 1"`; check `DATABASE_URL` |
| Schema errors after an update | `/quick-fix` ([QUICK_FIX.md](../user-guide/QUICK_FIX.md)) |

## Custom Configuration

- PHP settings: `docker/php/local.ini`
- nginx: `docker/nginx/default.conf` (rebuild with `--build`)
- Processes: `docker/supervisor/supervisord.conf`

## Further Reading

- [DOCKER_PRODUCTION.md](../deployment/DOCKER_PRODUCTION.md) — production operation, backup, reverse proxy
- [DOCKER_SECURITY.md](DOCKER_SECURITY.md) — hardening
- [DOCKER_HUB.md](DOCKER_HUB.md) — published images
- [HOSTING_WORKER.md](../user-guide/HOSTING_WORKER.md) — worker and scheduled tasks
