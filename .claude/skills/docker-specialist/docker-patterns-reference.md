# Docker Patterns Reference

## Quick Reference

### Build Commands

| Command | Purpose |
|---------|---------|
| `docker build --target production -t app:prod .` | Production build |
| `docker build --target development -t app:dev .` | Development build |
| `docker buildx build --platform linux/amd64,linux/arm64 --push .` | Multi-arch build |
| `docker compose build --no-cache` | Rebuild without cache |

### Run Commands

| Command | Purpose |
|---------|---------|
| `docker compose up -d` | Start in background |
| `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d` | Dev mode |
| `docker compose -f docker-compose.prod.yml up -d` | Production mode |
| `docker compose logs -f app` | Follow logs |
| `docker compose exec app bash` | Enter container |
| `docker compose down -v` | Stop and remove volumes |

### Debug Commands

| Command | Purpose |
|---------|---------|
| `docker compose exec app supervisorctl status` | Check processes |
| `docker compose exec app curl http://localhost/health` | Health check |
| `docker compose exec app php bin/console cache:clear` | Clear cache |
| `docker inspect <container>` | Container details |

---

## Multi-Stage Build Pattern

### Pattern: Production → Development Inheritance

```dockerfile
# ============================================
# BASE STAGE - Shared dependencies
# ============================================
FROM php:8.4-fpm-bookworm AS base

# Install shared system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    && docker-php-ext-install zip gd pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# ============================================
# PRODUCTION STAGE
# ============================================
FROM base AS production

# Production-specific: Install additional services
RUN apt-get update && apt-get install -y \
    nginx \
    supervisor \
    mariadb-server \
    && rm -rf /var/lib/apt/lists/*

# Copy application code
COPY --chown=www-data:www-data . .

# Install production dependencies only
RUN composer install --no-dev --optimize-autoloader

# Enable OPcache for production
RUN docker-php-ext-enable opcache
COPY docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/opcache.ini

# ============================================
# DEVELOPMENT STAGE
# ============================================
FROM production AS development

# Add development tools
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Override OPcache settings for development
COPY docker/php/opcache-dev.ini /usr/local/etc/php/conf.d/opcache.ini

# Install dev dependencies
RUN composer install
```

**Key Principle:** Development inherits from production, not vice versa. This ensures production image is always clean.

---

## Docker Compose Override Pattern

### Pattern: Base + Environment Overlay

**docker-compose.yml (Base):**
```yaml
version: '3.8'

services:
  app:
    build:
      context: .
      target: production
    ports:
      - "8080:80"
    environment:
      APP_ENV: prod
    volumes:
      - app_data:/var/www/html/var
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health"]
      interval: 30s
      timeout: 10s
      retries: 3

volumes:
  app_data:
```

**docker-compose.dev.yml (Development Override):**
```yaml
services:
  app:
    build:
      target: development
    environment:
      APP_ENV: dev
      APP_DEBUG: 1
      XDEBUG_MODE: debug
    volumes:
      - .:/var/www/html:cached
      - vendor_cache:/var/www/html/vendor
    ports:
      - "9003:9003"  # Xdebug

volumes:
  vendor_cache:
```

**docker-compose.prod.yml (Production Override):**
```yaml
services:
  app:
    build:
      platforms:
        - linux/amd64
        - linux/arm64
    deploy:
      resources:
        limits:
          cpus: '4'
          memory: 4G
    restart: always
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "3"
```

**Usage:**
```bash
# Development
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Production
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

---

## Self-Contained Deployment Pattern

### Pattern: Embedded Database with Supervisor

**Architecture:**
```
┌─────────────────────────────────────┐
│           Docker Container          │
│  ┌─────────────────────────────┐   │
│  │      Supervisor (PID 1)      │   │
│  └─────────────────────────────┘   │
│         │       │       │          │
│    ┌────┴───┐ ┌─┴──┐ ┌──┴────┐    │
│    │MariaDB │ │PHP │ │ Nginx │    │
│    │  :3306 │ │FPM │ │  :80  │    │
│    └────────┘ └────┘ └───────┘    │
│                                    │
│  ┌─────────────────────────────┐   │
│  │    /var/www/html/var/       │   │
│  │    ├── mysql/  (DB data)    │   │
│  │    ├── log/    (Logs)       │   │
│  │    └── cache/  (Cache)      │   │
│  └─────────────────────────────┘   │
└─────────────────────────────────────┘
```

**Supervisor Configuration:**
```ini
[supervisord]
nodaemon=true
user=root

[program:mariadb]
command=/usr/bin/mysqld_safe
priority=1
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0

[program:php-fpm]
command=/usr/local/sbin/php-fpm -F
priority=5
autorestart=true
depends_on=mariadb
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0

[program:nginx]
command=/usr/sbin/nginx -g "daemon off;"
priority=10
autorestart=true
depends_on=php-fpm
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
```

**Data Persistence:**
- All persistent data in `/var/www/html/var/`
- Single volume mount for all data
- Credentials stored in `var/mysql_credentials.txt`
- Database files in `var/mysql/`

---

## Multi-Architecture Build Pattern

### Pattern: Buildx with QEMU

**Setup:**
```bash
# Create multi-architecture builder
docker buildx create --use --name multiarch-builder

# Verify platforms
docker buildx inspect --bootstrap
```

**Build Command:**
```bash
docker buildx build \
  --platform linux/amd64,linux/arm64 \
  --target production \
  --tag moag1000/app:latest \
  --tag moag1000/app:1.0.0 \
  --push \
  .
```

**Dockerfile Considerations:**
```dockerfile
# Use ARG for architecture-specific logic
ARG TARGETARCH

# Architecture-specific packages (if needed)
RUN if [ "$TARGETARCH" = "arm64" ]; then \
      # ARM-specific installation \
    fi
```

**GitHub Actions Integration:**
```yaml
- name: Set up QEMU
  uses: docker/setup-qemu-action@v3

- name: Set up Docker Buildx
  uses: docker/setup-buildx-action@v3

- name: Build and push
  uses: docker/build-push-action@v6
  with:
    platforms: linux/amd64,linux/arm64
    push: true
    tags: ${{ steps.meta.outputs.tags }}
    cache-from: type=gha
    cache-to: type=gha,mode=max
```

---

## Layer Caching Pattern

### Pattern: Dependency-First Installation

```dockerfile
WORKDIR /var/www/html

# 1. Copy only dependency files first (rarely change)
COPY composer.json composer.lock ./
COPY package.json package-lock.json ./

# 2. Install dependencies (cached unless lock files change)
RUN composer install --no-dev --no-scripts --no-autoloader
RUN npm ci

# 3. Copy application code (frequently changes)
COPY --chown=www-data:www-data . .

# 4. Finalize installation
RUN composer dump-autoload --optimize
RUN npm run build
```

**Cache Mount Pattern (BuildKit):**
```dockerfile
# syntax=docker/dockerfile:1

RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --optimize-autoloader

RUN --mount=type=cache,target=/root/.npm \
    npm ci
```

---

## Health Check Patterns

### Pattern: Multi-Service Health Check

**Nginx Health Endpoint:**
```nginx
location = /health {
    access_log off;
    add_header Content-Type text/plain;
    return 200 "OK";
}
```

**PHP Health Check:**
```php
// public/health.php
<?php
try {
    // Check database connection
    $pdo = new PDO($_ENV['DATABASE_URL']);

    // Check filesystem
    if (!is_writable('/var/www/html/var/cache')) {
        throw new Exception('Cache not writable');
    }

    echo 'OK';
    http_response_code(200);
} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
    http_response_code(503);
}
```

**Docker Compose Health Check:**
```yaml
healthcheck:
  test: ["CMD", "curl", "-f", "http://localhost/health"]
  interval: 30s
  timeout: 10s
  retries: 3
  start_period: 60s  # Allow time for startup
```

---

## Security Patterns

### Pattern: Non-Root Execution

```dockerfile
# Create non-root user
RUN groupadd --gid 1000 appgroup && \
    useradd --uid 1000 --gid 1000 --shell /bin/bash appuser

# Set ownership
RUN chown -R appuser:appgroup /var/www/html

# Switch to non-root user
USER appuser
```

### Pattern: Secret Management

```dockerfile
# NEVER do this:
# ENV DB_PASSWORD=secret123

# Instead, use runtime environment variables or mounted secrets
# docker-compose.yml:
# environment:
#   DB_PASSWORD: ${DB_PASSWORD}  # From .env file or shell

# Or use Docker secrets:
# secrets:
#   db_password:
#     file: ./secrets/db_password.txt
```

### Pattern: Minimal Image

```dockerfile
# Use multi-stage to exclude build tools
FROM node:22 AS frontend-builder
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.4-fpm AS production
# Copy only built assets, not node_modules
COPY --from=frontend-builder /app/public/build /var/www/html/public/build
```

---

## Troubleshooting Checklist

### Container Won't Start

- [ ] Check logs: `docker compose logs app`
- [ ] Verify Dockerfile syntax: `docker build --check .`
- [ ] Check port conflicts: `lsof -i :8080`
- [ ] Verify volume permissions
- [ ] Check environment variables

### Slow Build

- [ ] Check `.dockerignore` includes `node_modules`, `vendor`, `var`
- [ ] Order Dockerfile from stable → changing
- [ ] Use BuildKit cache mounts
- [ ] Check network (slow package downloads)

### Database Connection Issues

- [ ] Verify database is running: `supervisorctl status mariadb`
- [ ] Check credentials file exists
- [ ] Verify DATABASE_URL environment variable
- [ ] Check database logs: `tail -f /var/log/mysql/error.log`

### Permission Denied

- [ ] Check file ownership: `ls -la /var/www/html/var`
- [ ] Fix ownership: `chown -R www-data:www-data /var/www/html/var`
- [ ] Verify volume mount permissions

---

## Extension Checklist

When extending Docker configuration:

1. [ ] Update `.dockerignore` if adding new build artifacts
2. [ ] Add to appropriate compose file (base, dev, or prod)
3. [ ] Document new environment variables in `.env.docker`
4. [ ] Update Supervisor if adding new processes
5. [ ] Update health check if adding new services
6. [ ] Test on both AMD64 and ARM64
7. [ ] Run Trivy scan: `trivy image app:latest`
8. [ ] Update CI/CD if adding new build steps
