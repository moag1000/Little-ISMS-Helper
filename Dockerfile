# syntax=docker/dockerfile:1.7
# Multi-stage Dockerfile for Little ISMS Helper
# Stage 1: Production Build
# Using Debian Trixie (13) instead of Alpine for better QEMU cross-compilation support
# PHP 8.5 via mlocati/install-php-extensions — handles the apt build-deps + cleanup
# automatically and works around the docker-php-ext-install failures on Trixie.
# Pinned digest for reproducible builds — update via:
#   docker buildx imagetools inspect php:8.5-fpm-trixie | grep '^Digest:'
FROM php:8.5-fpm-trixie@sha256:7d1586e8949f50449c2ca173aad9dac624a5cabb9a01780f9aa2ca8347a09af2 AS production

# OCI Image Labels (https://github.com/opencontainers/image-spec/blob/main/annotations.md)
LABEL org.opencontainers.image.title="Little ISMS Helper"
LABEL org.opencontainers.image.description="Webbasierte ISMS-Lösung für ISO 27001:2022 Compliance, Risiko- und BCM-Management"
LABEL org.opencontainers.image.authors="Little ISMS Helper Project"
LABEL org.opencontainers.image.vendor="Little ISMS Helper Project"
LABEL org.opencontainers.image.licenses="AGPL-3.0-or-later"
LABEL org.opencontainers.image.url="https://github.com/moag1000/Little-ISMS-Helper"
LABEL org.opencontainers.image.source="https://github.com/moag1000/Little-ISMS-Helper"
LABEL org.opencontainers.image.documentation="https://github.com/moag1000/Little-ISMS-Helper/blob/main/README.md"
LABEL maintainer="Little ISMS Helper Project"

# Security: Update all packages to latest security patches.
# BuildKit cache-mounts speed up rebuilds by reusing apt downloads + lists across
# builds, while keeping the cache OUT of the final image layer.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    rm -f /etc/apt/apt.conf.d/docker-clean && \
    apt-get update && apt-get upgrade -y

# Install system dependencies. The `*-dev` libraries that PHP-extension builds
# need (libpng-dev, libicu-dev, libfreetype6-dev, libjpeg-dev, libxml2-dev,
# libonig-dev, libzip-dev) are no longer listed here — install-php-extensions
# pulls them on demand and removes them again after the extension is built.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    mariadb-server \
    mariadb-client \
    nginx \
    supervisor \
    python3-pip \
    curl \
    gosu

# Install PHP extensions via mlocati/install-php-extensions:
#  - handles all apt build-deps internally and removes them after compile
#  - works around docker-php-ext-install failures on Debian Trixie + PHP 8.5
#  - opcache/mbstring/xml ship with php:8.5-fpm-trixie; only build the rest.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
    pdo_mysql \
    mysqli \
    intl \
    zip \
    gd \
    soap

# Install Composer (pinned to major version 2 for stability)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first for better caching
COPY composer.json symfony.lock ./
# Note: composer.lock intentionally omitted - will be generated during install

# Install dependencies (production) WITHOUT running scripts (bin/console doesn't exist yet)
# This will generate a fresh composer.lock with the latest compatible versions.
# BuildKit cache-mount reuses Composer's package cache across builds.
RUN --mount=type=cache,target=/root/.composer/cache,sharing=locked \
    composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-scripts --verbose

# Copy application files
COPY . .

# Set environment variables for production
ENV APP_ENV=prod
ENV APP_DEBUG=0
# Default database credentials (can be overridden at runtime via -e flags)
ENV MYSQL_DATABASE=isms
ENV MYSQL_USER=isms
# MYSQL_PASSWORD should be set at runtime for security - if not set, auto-generated
# NOTE: DATABASE_URL is NOT set here - it will be configured by init-mysql.sh in .env.local
# This allows the auto-generated password to be used correctly
# Dummy app secret for build-time (Setup Wizard will generate secure secret)
# hadolint ignore=DL3044
# Note: This is NOT a real secret - it's a build-time placeholder that will be
# replaced by the Setup Wizard with a cryptographically secure secret on first run.
# Symfony requires APP_SECRET for cache:warmup during build.
ENV APP_SECRET="build-time-placeholder-not-a-real-secret"

# Configure PHP for production BEFORE running scripts
# - Use production php.ini
# - Increase memory limit for Symfony cache:clear and CLI operations
# - Set max_execution_time for long-running operations (migrations, imports)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    echo "memory_limit=512M" > "$PHP_INI_DIR/conf.d/memory-limit.ini" && \
    echo "max_execution_time=300" > "$PHP_INI_DIR/conf.d/execution-time.ini"

# Now run Symfony scripts (bin/console is now available, memory limit is set)
# Use a build-time DATABASE_URL that won't persist (init-mysql.sh will set the real one)
RUN DATABASE_URL="mysql://build:build@localhost/isms?serverVersion=mariadb-11.4.0" \
    composer run-script --no-dev auto-scripts || true

# Create required directories (gem. config/modules.yaml: var/cache, var/log,
# var/sessions, public/uploads) plus log mounts for supervisor/nginx
RUN mkdir -p var/cache var/log var/sessions public/uploads \
        /var/log/supervisor /var/log/nginx && \
    chmod -R 775 var/cache var/log var/sessions public/uploads && \
    chmod -R 755 /var/log/supervisor /var/log/nginx

# Set permissions: app owned by www-data, group-writable für var/ + uploads
# (775 statt 755, damit Volume-Mounts mit non-root-UIDs schreiben können)
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/var /var/www/html/public/uploads && \
    chown -R root:root /var/log/supervisor /var/log/nginx

# Configure PHP-INI fragments + PHP-FPM listen address in one consolidated RUN
# (DL3059 — fewer consecutive RUNs = fewer image layers).
#
# Session storage: PHP defaults to /var/lib/php/sessions which doesn't exist in
# this container — Symfony framework.yaml save_path overrides per-request, but
# any code path using PHP-native session_start() before the kernel would
# silently fail to persist.
#
# OPcache: production-tuned (no timestamp revalidation, big string buffer).
#
# PHP-FPM: switch to TCP 127.0.0.1:9000 (nginx upstream); unix-socket variant
# had race + ENOENT in supervisor-managed scenarios. `if/then/fi` instead of
# `&& ... || true` so non-existent zz-docker.conf is a clean no-op (SC2015).
RUN { \
        echo 'session.save_path = "/var/www/html/var/sessions"'; \
        echo 'session.gc_maxlifetime = 3600'; \
        echo 'session.cookie_httponly = 1'; \
        echo 'session.cookie_samesite = "Lax"'; \
        echo 'session.use_strict_mode = 1'; \
    } > "$PHP_INI_DIR/conf.d/session.ini" && \
    { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.revalidate_freq=0'; \
    } > "$PHP_INI_DIR/conf.d/opcache-prod.ini" && \
    sed -i 's|listen = .*|listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/www.conf && \
    if [ -f /usr/local/etc/php-fpm.d/zz-docker.conf ]; then \
        sed -i 's|listen = .*|listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/zz-docker.conf; \
    fi

# Configure MariaDB for standalone deployment
# Note: Data is stored in /var/www/html/var/mysql (part of app volume), not /var/lib/mysql
RUN mkdir -p /run/mysqld && \
    chown -R mysql:mysql /run/mysqld && \
    chmod 755 /run/mysqld

# Prevent Docker from creating automatic volume for /var/lib/mysql
# We store MySQL data in /var/www/html/var/mysql instead (part of the app volume)
RUN rm -rf /var/lib/mysql && mkdir -p /var/lib/mysql && chown mysql:mysql /var/lib/mysql

# Copy MariaDB initialization script
COPY docker/scripts/init-mysql.sh /var/www/html/docker/scripts/init-mysql.sh
RUN chmod +x /var/www/html/docker/scripts/init-mysql.sh

# Configure Nginx (Debian uses /etc/nginx/sites-enabled/)
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default && \
    ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Configure Supervisor
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Health check to verify the application is running
HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=40s \
    CMD curl -f http://localhost/health || exit 1

# Expose port
EXPOSE 80

# No VOLUME directive for /var/lib/mysql - we use /var/www/html/var/mysql instead
# Users should mount a volume to /var/www/html/var for persistent data

# Start supervisor
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# Stage 2: Development Build
FROM production AS development

# Install Xdebug via the same installer used in the production stage.
# linux-headers etc. that the old `pecl install xdebug` flow needed are no
# longer required — install-php-extensions handles all build deps + cleanup.
RUN install-php-extensions xdebug

# Configure Xdebug
RUN echo "xdebug.mode=debug,coverage" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.client_host=host.docker.internal" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.client_port=9003" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.start_with_request=yes" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini"

# Use development PHP configuration
# Note: Production stage already moved php.ini-production to php.ini
# So we just need to replace it with the development version
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Clean vendor from production stage and install all dependencies including dev.
# auto-scripts wrapped in `{ ...; }` so its tolerated failure doesn't mask a
# preceding `composer install` failure (SC2015 — `A && B || C` is not if-then-else).
RUN rm -rf vendor/ && \
    composer install --optimize-autoloader --no-interaction --no-scripts --verbose && \
    { composer run-script auto-scripts || true; }

# Replace production OPcache config with development version
RUN cat > "$PHP_INI_DIR/conf.d/opcache-prod.ini" <<'EOF'
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
EOF

CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
