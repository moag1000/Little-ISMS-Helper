# Docker Secrets Management Guide

This guide explains how to securely manage sensitive credentials in Little ISMS Helper using Docker Secrets.

## 📚 Table of Contents

- [Why Docker Secrets?](#why-docker-secrets)
- [Quick Start](#quick-start)
- [Production Setup](#production-setup)
- [Development Setup](#development-setup)
- [Managing Secrets](#managing-secrets)
- [Best Practices](#best-practices)
- [Troubleshooting](#troubleshooting)

---

## 🔒 Why Docker Secrets?

**Current Problem:**
Sensitive credentials (database passwords, APP_SECRET, etc.) are stored in `.env` files or environment variables, which can be:
- ❌ Accidentally committed to version control
- ❌ Visible in `docker inspect` output
- ❌ Exposed in process listings
- ❌ Logged in error messages

**Docker Secrets Solution:**
✅ Encrypted at rest and in transit
✅ Only available to authorized containers
✅ Mounted as files (not environment variables)
✅ Automatically rotated and versioned
✅ Auditable access logs

---

## 🚀 Quick Start

### Prerequisites

- Docker Swarm mode enabled (for production)
- Docker Compose 3.1+ (supports secrets)

### Enable Docker Swarm (Production Only)

```bash
# Initialize swarm on manager node
docker swarm init

# Verify swarm is active
docker info | grep Swarm
# Should show: Swarm: active
```

### Create Your First Secret

```bash
# Method 1: From stdin
echo "your-secret-password" | docker secret create db_password -

# Method 2: From file
echo "your-secret-password" > /tmp/db_password
docker secret create db_password /tmp/db_password
rm /tmp/db_password  # Remove temp file immediately

# Method 3: Using environment variable
printf "%s" "$DB_PASSWORD" | docker secret create db_password -
```

---

## 🏭 Production Setup

### Step 1: Create All Required Secrets

```bash
# Database credentials
printf "%s" "isms_production_db_password" | docker secret create db_password -
printf "%s" "little_isms_prod" | docker secret create db_name -
printf "%s" "isms_user" | docker secret create db_user -

# Application secrets
openssl rand -hex 32 | docker secret create app_secret -

# Mailer credentials (if using external SMTP)
printf "%s" "smtp.example.com" | docker secret create mailer_host -
printf "%s" "smtp_username" | docker secret create mailer_user -
printf "%s" "smtp_password" | docker secret create mailer_password -

# Azure OAuth (if used)
printf "%s" "your-azure-client-id" | docker secret create azure_client_id -
printf "%s" "your-azure-client-secret" | docker secret create azure_client_secret -
printf "%s" "your-azure-tenant-id" | docker secret create azure_tenant_id -

# Verify secrets were created
docker secret ls
```

### Step 2: Update docker-compose.prod.yml

Create a new file `docker-compose.prod-secrets.yml`:

```yaml
version: '3.8'

secrets:
  db_password:
    external: true
  db_name:
    external: true
  db_user:
    external: true
  app_secret:
    external: true
  mailer_host:
    external: true
  mailer_user:
    external: true
  mailer_password:
    external: true

services:
  db:
    image: postgres:16-alpine
    secrets:
      - db_password
      - db_name
      - db_user
    environment:
      POSTGRES_DB_FILE: /run/secrets/db_name
      POSTGRES_USER_FILE: /run/secrets/db_user
      POSTGRES_PASSWORD_FILE: /run/secrets/db_password
    deploy:
      placement:
        constraints:
          - node.role == manager

  app:
    secrets:
      - db_password
      - db_name
      - db_user
      - app_secret
      - mailer_host
      - mailer_user
      - mailer_password
    environment:
      # Point to secret files instead of direct values
      APP_SECRET_FILE: /run/secrets/app_secret
      DB_PASSWORD_FILE: /run/secrets/db_password
      DB_NAME_FILE: /run/secrets/db_name
      DB_USER_FILE: /run/secrets/db_user
      MAILER_HOST_FILE: /run/secrets/mailer_host
      MAILER_USER_FILE: /run/secrets/mailer_user
      MAILER_PASSWORD_FILE: /run/secrets/mailer_password
    deploy:
      replicas: 2
      update_config:
        parallelism: 1
        delay: 10s
      restart_policy:
        condition: on-failure
```

### Step 3: Update Application Code

Modify `.env` to support file-based secrets:

```bash
# .env (production)
# Docker Secrets (loaded from /run/secrets/)
APP_SECRET=${APP_SECRET:-file:///run/secrets/app_secret}
DATABASE_URL="postgresql://${DB_USER:-file:///run/secrets/db_user}:${DB_PASSWORD:-file:///run/secrets/db_password}@db:5432/${DB_NAME:-file:///run/secrets/db_name}?serverVersion=16"
MAILER_DSN="smtp://${MAILER_USER:-file:///run/secrets/mailer_user}:${MAILER_PASSWORD:-file:///run/secrets/mailer_password}@${MAILER_HOST:-file:///run/secrets/mailer_host}:587"
```

**OR** Create a custom secret loader in Symfony:

```php
// src/Service/SecretLoader.php
<?php

namespace App\Service;

class SecretLoader
{
    public static function load(string $envVar, string $secretPath): string
    {
        // Try environment variable first
        $value = $_ENV[$envVar] ?? '';

        // If not set, try loading from Docker secret file
        if (empty($value) && file_exists($secretPath)) {
            $value = trim(file_get_contents($secretPath));
        }

        return $value;
    }
}

// Usage in config/services.yaml or .env.local.php
$dbPassword = SecretLoader::load('DB_PASSWORD', '/run/secrets/db_password');
```

### Step 4: Deploy with Swarm

```bash
# Deploy stack with secrets
docker stack deploy -c docker-compose.prod.yml -c docker-compose.prod-secrets.yml isms

# Verify deployment
docker service ls
docker service ps isms_app
docker service ps isms_db

# Check secret access (should show secret files)
docker exec $(docker ps -q -f name=isms_app) ls -la /run/secrets/
```

---

## 🛠️ Development Setup

For local development, you can simulate secrets using bind mounts.

### Method 1: File-Based Secrets (Recommended)

```bash
# Create local secrets directory (gitignored)
mkdir -p .secrets
echo "isms_password" > .secrets/db_password
echo "little_isms" > .secrets/db_name
echo "isms_user" > .secrets/db_user
openssl rand -hex 32 > .secrets/app_secret

# Secure the directory
chmod 700 .secrets
chmod 600 .secrets/*

# Add to .gitignore
echo ".secrets/" >> .gitignore
```

Update `docker-compose.yml`:

```yaml
services:
  app:
    volumes:
      - ./.secrets:/run/secrets:ro
    environment:
      APP_SECRET_FILE: /run/secrets/app_secret
      DB_PASSWORD_FILE: /run/secrets/db_password
```

### Method 2: Docker Compose Secrets (No Swarm)

```yaml
# docker-compose.yml
version: '3.8'

secrets:
  db_password:
    file: ./.secrets/db_password
  app_secret:
    file: ./.secrets/app_secret

services:
  app:
    secrets:
      - db_password
      - app_secret
    environment:
      APP_SECRET_FILE: /run/secrets/app_secret
      DB_PASSWORD_FILE: /run/secrets/db_password
```

---

## 🔄 Managing Secrets

### View Secrets

```bash
# List all secrets
docker secret ls

# Inspect secret metadata (does NOT show the value!)
docker secret inspect db_password
```

### Update Secrets (Rotation)

```bash
# Create new version
printf "%s" "new-password" | docker secret create db_password_v2 -

# Update service to use new secret
docker service update \
  --secret-rm db_password \
  --secret-add source=db_password_v2,target=db_password \
  isms_app

# Remove old secret after verifying
docker secret rm db_password

# Rename new secret to original name
docker secret create db_password < <(docker secret inspect db_password_v2 -f '{{.Spec.Data}}' | base64 -d)
docker secret rm db_password_v2
```

### Delete Secrets

```bash
# Remove secret (only if no services are using it)
docker secret rm db_password

# Force remove (removes from all services first)
docker service update --secret-rm db_password isms_app
docker secret rm db_password
```

### Backup Secrets

⚠️ **Security Warning:** Only backup secrets to encrypted storage!

```bash
# Export secrets to encrypted archive
docker secret ls -q | xargs -I {} sh -c 'docker secret inspect {} -f "{{.Spec.Name}}:{{.Spec.Data}}"' | \
  base64 -d > secrets_backup.txt

# Encrypt the backup
gpg --symmetric --cipher-algo AES256 secrets_backup.txt

# Delete unencrypted backup immediately
shred -u secrets_backup.txt

# To restore:
gpg -d secrets_backup.txt.gpg | while IFS=: read -r name value; do
  printf "%s" "$value" | docker secret create "$name" - || true
done
```

---

## 🛡️ Best Practices

### ✅ DO

1. **Use External Secrets in Production**
   ```yaml
   secrets:
     db_password:
       external: true  # Managed outside compose
   ```

2. **Rotate Secrets Regularly**
   - Database passwords: Every 90 days
   - API keys: Every 30 days
   - APP_SECRET: Only when compromised

3. **Use Descriptive Names**
   ```bash
   docker secret create prod_db_password_2025_11 -
   ```

4. **Limit Secret Access**
   ```yaml
   services:
     app:
       secrets:
         - db_password  # Only what's needed
   ```

5. **Audit Secret Usage**
   ```bash
   docker secret inspect db_password -f '{{.UpdatedAt}}'
   docker service ps isms_app --format '{{.Name}} {{.CurrentState}}'
   ```

### ❌ DON'T

1. **Don't Store Secrets in Environment Variables**
   ```yaml
   environment:
     DB_PASSWORD: "hardcoded"  # ❌ BAD
   ```

2. **Don't Commit Secrets to Git**
   - Always use `.gitignore` for `.secrets/`
   - Scan commits with tools like `git-secrets`

3. **Don't Log Secret Values**
   ```php
   // ❌ BAD
   $logger->info('Password: ' . $password);

   // ✅ GOOD
   $logger->info('Password loaded successfully');
   ```

4. **Don't Use Default/Weak Secrets**
   ```bash
   # ❌ BAD
   echo "admin123" | docker secret create db_password -

   # ✅ GOOD
   openssl rand -base64 32 | docker secret create db_password -
   ```

5. **Don't Share Secrets Between Environments**
   - Dev secrets ≠ Staging secrets ≠ Production secrets

---

## 🧪 Testing Secret Access

```bash
# Check if secrets are mounted correctly
docker exec isms-app-prod ls -la /run/secrets/

# Verify secret content (for debugging only!)
docker exec isms-app-prod cat /run/secrets/db_password

# Test database connection with secret
docker exec isms-app-prod php bin/console doctrine:query:sql "SELECT 1"

# Verify APP_SECRET is loaded
docker exec isms-app-prod php bin/console debug:container --parameter=kernel.secret
```

---

## 🐛 Troubleshooting

### Secret Not Found

**Error:** `secret not found: db_password`

**Solution:**
```bash
# Check if secret exists
docker secret ls | grep db_password

# Recreate if missing
echo "your-password" | docker secret create db_password -
```

### Permission Denied

**Error:** `permission denied while trying to connect to /run/secrets/db_password`

**Solution:**
```bash
# Check file permissions in container
docker exec isms-app-prod ls -la /run/secrets/

# Secrets should be readable by the app user
# If not, check your user/group in Dockerfile
```

### Service Won't Start

**Error:** Service fails to start after adding secrets

**Solution:**
```bash
# Check service logs
docker service logs isms_app

# Verify secret is accessible
docker exec $(docker ps -q -f name=isms_app) test -r /run/secrets/db_password && echo "OK" || echo "FAIL"

# Check environment variable mapping
docker exec $(docker ps -q -f name=isms_app) env | grep -i secret
```

### Secret Update Not Applied

**Error:** Service still uses old secret after rotation

**Solution:**
```bash
# Force service update
docker service update --force isms_app

# Or restart service
docker service scale isms_app=0
docker service scale isms_app=2
```

---

## 📚 Additional Resources

- [Docker Secrets Documentation](https://docs.docker.com/engine/swarm/secrets/)
- [Docker Compose Secrets](https://docs.docker.com/compose/use-secrets/)
- [Symfony Secrets Management](https://symfony.com/doc/current/configuration/secrets.html)
- [OWASP Secrets Management](https://owasp.org/www-community/vulnerabilities/Use_of_hard-coded_password)

---

## 🎯 Migration Checklist

Migrating from environment variables to Docker Secrets:

- [ ] Enable Docker Swarm (production)
- [ ] Create `.secrets/` directory (development)
- [ ] Generate all required secrets
- [ ] Update `docker-compose.prod.yml` with secrets
- [ ] Modify application to read from `/run/secrets/`
- [ ] Test in development environment
- [ ] Deploy to staging with secrets
- [ ] Verify all functionality works
- [ ] Deploy to production
- [ ] Remove old environment variables
- [ ] Update documentation
- [ ] Train team on secret management
- [ ] Set up secret rotation schedule

---

**Last Updated:** 2025-11-14
**Maintained by:** Little ISMS Helper Project
**Security Level:** Production-Ready
