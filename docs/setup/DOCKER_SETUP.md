# Little ISMS Helper - Docker Setup Guide

## Overview

This Docker setup provides a complete development and production environment for Little ISMS Helper with:
- ✅ PHP 8.4-FPM with all required extensions
- ✅ Nginx web server
- ✅ PostgreSQL 16 database
- ✅ MailHog for email testing
- ✅ pgAdmin for database management
- ✅ Xdebug for debugging (development only)
- ✅ Multi-stage builds (development/production)

## Prerequisites

- Docker 20.10+
- Docker Compose 2.0+
- At least 4GB RAM available for Docker

## ⚠️ Security & Datenpersistenz

**Wichtige Informationen:**
- ✅ **Datenpersistenz:** Alle Konfigurationen (`.env.local`, `config/setup_complete.lock`) und Daten (PostgreSQL) überleben Reboots
- 🔒 **Security:** Siehe [DOCKER_SECURITY.md](DOCKER_SECURITY.md) für Best Practices
- 🔐 **Produktion:** Verwenden Sie `docker-compose.prod.yml` für Production-Deployments

## Quick Start (With Deployment Wizard - Recommended) 🧙

### 1. Start Docker Environment

```bash
# Clone the repository (if not done yet)
git clone https://github.com/moag1000/Little-ISMS-Helper.git
cd Little-ISMS-Helper

# Start all services (PostgreSQL, Application, MailHog, pgAdmin)
docker-compose up -d

# Check status - wait until all services are healthy
docker-compose ps
```

### 2. Complete Setup with Deployment Wizard

**That's it!** 🎉 Open your browser and navigate to:

```
http://localhost:8000/setup
```

The **10-Step Deployment Wizard** will guide you through the complete setup:

- ✅ **Step 1**: Database Configuration - Use these settings:
  - **Database Type**: PostgreSQL
  - **Host**: `db` (Docker service name)
  - **Port**: `5432`
  - **Database Name**: `little_isms`
  - **Username**: `isms_user`
  - **Password**: `isms_password`

  The wizard will automatically test the connection and create tables!

- ✅ **Step 2**: Admin User Creation
- ✅ **Step 3**: Email Configuration - Use MailHog:
  - **Provider**: SMTP
  - **Host**: `mailhog`
  - **Port**: `1025`
  - **No authentication needed**
- ✅ **Step 4-10**: Follow the wizard (organisation info, modules, frameworks, etc.)

**No manual commands needed!** The wizard handles:
- Database migrations
- ISO 27001 Controls import
- Admin user creation
- Role/permission setup
- Everything else!

### 3. Access the Services

After completing the wizard:

- **Application**: http://localhost:8000
- **API Documentation**: http://localhost:8000/api/docs
- **MailHog (Email Testing)**: http://localhost:8025
  - All emails sent by the application appear here
  - Perfect for testing notifications
- **pgAdmin (Database GUI)**: http://localhost:5050
  - Email: `admin@isms.local`
  - Password: `admin`
  - Add server: Host=`db`, Port=`5432`, Username=`isms_user`, Password=`isms_password`

---

## Alternative: Manual Setup (Advanced Users)

If you prefer manual setup without the wizard:

### 1. Start the Environment

```bash
docker-compose up -d
docker-compose ps
```

### 2. Manual Initialization

```bash
# Enter the app container
docker-compose exec app sh

# Create .env.local with database configuration
cat > .env.local << EOF
APP_SECRET=$(openssl rand -hex 32)
DATABASE_URL="postgresql://isms_user:isms_password@db:5432/little_isms?serverVersion=16&charset=utf8"
MAILER_DSN=smtp://mailhog:1025
EOF

# Run database migrations
php bin/console doctrine:migrations:migrate --no-interaction

# Create admin user and load permissions
php bin/console app:setup-permissions --admin-email=admin@example.com --admin-password=admin123

# Load ISO 27001 Controls
php bin/console isms:load-annex-a-controls

# Exit container
exit
```

### 3. Access the Application

- **Application**: http://localhost:8000
- **Login**: admin@example.com / admin123 (⚠️ **Change immediately!**)

## Services

### Application (app)
- **URL**: http://localhost:8000
- **Container**: `isms-app`
- **Image**: Custom (built from Dockerfile)
- **Description**: PHP 8.4-FPM + Nginx + Symfony

### Database (db)
- **Host**: localhost:5432 (external) or `db:5432` (internal)
- **Container**: `isms-db`
- **Image**: postgres:16-alpine
- **Credentials**:
  - Database: `little_isms`
  - User: `isms_user`
  - Password: `isms_password`

### MailHog (mailhog)
- **SMTP**: localhost:1025
- **Web UI**: http://localhost:8025
- **Container**: `isms-mailhog`
- **Description**: Captures all outgoing emails for testing

### pgAdmin (pgadmin)
- **URL**: http://localhost:5050
- **Container**: `isms-pgadmin`
- **Credentials**:
  - Email: `admin@isms.local`
  - Password: `admin`

## Common Commands

### Docker Compose

```bash
# Start all services
docker-compose up -d

# Stop all services
docker-compose down

# Stop and remove volumes (⚠️ deletes database data)
docker-compose down -v

# View logs
docker-compose logs -f

# View logs for specific service
docker-compose logs -f app
docker-compose logs -f db

# Restart a service
docker-compose restart app

# Rebuild containers
docker-compose build
docker-compose up -d --build
```

### Application Commands

```bash
# Execute commands in app container
docker-compose exec app <command>

# Examples:
docker-compose exec app php bin/console cache:clear
docker-compose exec app php bin/console doctrine:migrations:status
docker-compose exec app php bin/phpunit
docker-compose exec app composer install
docker-compose exec app php bin/console debug:router
```

### Database Commands

```bash
# Access PostgreSQL shell
docker-compose exec db psql -U isms_user -d little_isms

# Create database backup
docker-compose exec db pg_dump -U isms_user little_isms > backup.sql

# Restore database
docker-compose exec -T db psql -U isms_user little_isms < backup.sql

# Reset database (⚠️ destroys all data)
docker-compose exec app php bin/console doctrine:database:drop --force
docker-compose exec app php bin/console doctrine:database:create
docker-compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

## Development Workflow

### 1. Start Development Environment

```bash
docker-compose up -d
docker-compose logs -f app
```

### 2. Make Code Changes

Edit files in your IDE - changes are synced via volume mounts:
```yaml
volumes:
  - ./:/var/www/html
```

### 3. Clear Cache if Needed

```bash
docker-compose exec app php bin/console cache:clear
```

### 4. Run Tests

```bash
docker-compose exec app php bin/phpunit
```

### 5. Debug with Xdebug

Xdebug is pre-configured for development:
- **Port**: 9003
- **IDE Key**: PHPSTORM
- **Mode**: debug,coverage

**VS Code launch.json:**
```json
{
  "name": "Listen for Xdebug (Docker)",
  "type": "php",
  "request": "launch",
  "port": 9003,
  "pathMappings": {
    "/var/www/html": "${workspaceFolder}"
  }
}
```

**PhpStorm:**
1. Settings → PHP → Debug → Port: 9003
2. Settings → PHP → Servers:
   - Name: `localhost`
   - Host: `localhost`
   - Port: `8000`
   - Path mappings: `/var/www/html` → `<project root>`

## Production Deployment

### Build for Production

```bash
# Build production image
docker build --target production -t little-isms-helper:latest .

# Or with docker-compose
docker-compose -f docker-compose.prod.yml build
```

### Production Environment Variables

Create `.env.prod`:
```env
APP_ENV=prod
APP_SECRET=your-secret-key-here
DATABASE_URL=postgresql://user:password@host:5432/database
MAILER_DSN=smtp://smtp.example.com:587
```

### Production Optimizations

The production Docker image includes:
- ✅ Composer dependencies without dev packages
- ✅ Optimized autoloader
- ✅ OPcache enabled with production settings
- ✅ No Xdebug (performance)
- ✅ PHP production configuration
- ✅ Read-only filesystem where possible

### Health Checks

```bash
# Check if services are healthy
docker-compose ps

# PostgreSQL health check
docker-compose exec db pg_isready -U isms_user

# Application health check
curl http://localhost:8000/health
```

## Troubleshooting

### Issue: Port Already in Use

```bash
# Check what's using the port
lsof -i :8000
lsof -i :5432

# Change ports in docker-compose.yml
services:
  app:
    ports:
      - "8080:80"  # Changed from 8000
```

### Issue: Permission Denied

```bash
# Fix permissions
docker-compose exec app chown -R www-data:www-data /var/www/html/var
docker-compose exec app chmod -R 755 /var/www/html/var
```

### Issue: Database Connection Failed

```bash
# Check if database is running
docker-compose ps db

# Check database logs
docker-compose logs db

# Wait for database to be ready
docker-compose exec db pg_isready -U isms_user

# Test connection
docker-compose exec app php bin/console dbal:run-sql "SELECT 1"
```

### Issue: Composer Dependencies Not Found

```bash
# Reinstall dependencies
docker-compose exec app composer install
```

### Issue: Cache Issues

```bash
# Clear all caches
docker-compose exec app php bin/console cache:clear
docker-compose exec app php bin/console cache:warmup
```

## Advanced Configuration

### Custom PHP Settings

Edit `docker/php/local.ini`:
```ini
memory_limit = 1G
max_execution_time = 300
```

Restart app container:
```bash
docker-compose restart app
```

### Custom Nginx Configuration

Edit `docker/nginx/default.conf` and rebuild:
```bash
docker-compose up -d --build app
```

### Add Custom Supervisor Programs

Edit `docker/supervisor/supervisord.conf`:
```ini
[program:messenger-consume]
command=php /var/www/html/bin/console messenger:consume async --limit=10
autostart=true
autorestart=true
```

## Performance Optimization

### 1. Increase Resource Limits

**Docker Desktop:**
- Settings → Resources
- CPU: 4+ cores
- Memory: 4+ GB

### 2. Enable BuildKit

```bash
export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1
docker-compose build
```

### 3. Use Volume Mounts for Better Performance (macOS/Windows)

Already configured in `docker-compose.yml`:
```yaml
volumes:
  - ./:/var/www/html:cached
```

### 4. Prune Unused Resources

```bash
docker system prune -a --volumes
```

## Security Considerations

### Production Checklist

- [ ] Change default database credentials
- [ ] Set strong `APP_SECRET`
- [ ] Use HTTPS (add reverse proxy with SSL)
- [ ] Disable debug mode (`APP_ENV=prod`, `APP_DEBUG=0`)
- [ ] Remove MailHog and pgAdmin services
- [ ] Use Docker secrets for sensitive data
- [ ] Run containers as non-root user
- [ ] Enable Docker Content Trust
- [ ] Regular security updates

### Environment Isolation

```bash
# Development
docker-compose up -d

# Production
docker-compose -f docker-compose.prod.yml up -d
```

## Maintenance

### Update Base Images

```bash
# Pull latest images
docker-compose pull

# Rebuild
docker-compose build --no-cache

# Restart
docker-compose up -d
```

### Backup Strategy

```bash
# Automated backup script
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
docker-compose exec -T db pg_dump -U isms_user little_isms > "backup_${DATE}.sql"
gzip "backup_${DATE}.sql"
```

### Monitor Resource Usage

```bash
# Show resource usage
docker stats

# Show disk usage
docker system df
```

## Support

For issues or questions:
- Check the main [README.md](../README.md)
- Review [API_SETUP.md](API_SETUP.md) for API documentation
- Check Docker logs: `docker-compose logs`

---

**Last Updated:** 2024-11-06
**Docker Version:** 24.x+
**Docker Compose Version:** 2.x+
