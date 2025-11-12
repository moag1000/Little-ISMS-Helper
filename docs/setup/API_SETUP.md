# Little ISMS Helper - REST API Setup Guide

## Overview

This document provides comprehensive setup instructions for the Little ISMS Helper REST API, built with Symfony 7.3 and API Platform 4.2.3.

## Features

The API provides complete CRUD operations for:
- ✅ **Risks** - ISO 27001 risk management
- ✅ **Assets** - Asset inventory with CIA values
- ✅ **Controls** - ISO 27001:2022 Annex A controls (93 controls)
- ✅ **Incidents** - Security incident tracking with GDPR fields
- ✅ **Internal Audits** - ISO 27001 Clause 9.2 compliance
- ✅ **Training** - Awareness training tracking

### API Capabilities

- **Serialization Groups** - Separate read/write permissions
- **Validation Constraints** - Input validation with clear error messages
- **API Filters** - Search, order, date, and boolean filters
- **Pagination** - 30 items per page (client-configurable up to 100)
- **MaxDepth Annotations** - Prevents circular references
- **Database Indexes** - Optimized for common queries
- **Computed Properties** - Business intelligence metrics
- **Security** - Role-based access (ROLE_USER, ROLE_ADMIN)

## Prerequisites

- PHP 8.4+
- PostgreSQL 16+ (or MySQL 8.0+)
- Composer 2.x
- Symfony CLI (optional but recommended)

### Required PHP Extensions

```bash
php -m | grep -E "(pdo|pgsql|intl|xml|ctype|iconv|mbstring)"
```

Install missing extensions:
```bash
sudo apt-get install php8.4-pgsql php8.4-intl php8.4-xml php8.4-mbstring
```

## Installation

### 1. Clone and Install Dependencies

```bash
cd Little-ISMS-Helper
composer install
```

### 2. Database Configuration

Create `.env.local`:
```bash
cp .env .env.local
```

Edit `.env.local`:
```env
###> doctrine/doctrine-bundle ###
DATABASE_URL="postgresql://isms_user:isms_password@127.0.0.1:5432/little_isms?serverVersion=16&charset=utf8"
###< doctrine/doctrine-bundle ###
```

### 3. Create Database and User

**PostgreSQL:**
```bash
sudo -u postgres psql
```

```sql
CREATE DATABASE little_isms;
CREATE USER isms_user WITH PASSWORD 'isms_password';
GRANT ALL PRIVILEGES ON DATABASE little_isms TO isms_user;
\q
```

**MySQL (Alternative):**
```sql
CREATE DATABASE little_isms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'isms_user'@'localhost' IDENTIFIED BY 'isms_password';
GRANT ALL PRIVILEGES ON little_isms.* TO 'isms_user'@'localhost';
FLUSH PRIVILEGES;
```

### 4. Run Migrations

```bash
# Create database if not exists
php bin/console doctrine:database:create --if-not-exists

# Run migrations
php bin/console doctrine:migrations:migrate --no-interaction

# Load fixtures (optional - test data)
php bin/console doctrine:fixtures:load --no-interaction
```

### 5. Start Development Server

```bash
# Option 1: Symfony CLI (recommended)
symfony serve

# Option 2: PHP built-in server
php -S localhost:8000 -t public/

# Access API documentation
open http://localhost:8000/api
```

## API Endpoints

All endpoints available at `/api`:

| Entity | Endpoint | Operations |
|--------|----------|------------|
| Risk | `/api/risks` | GET, POST, PUT, DELETE |
| Asset | `/api/assets` | GET, POST, PUT, DELETE |
| Control | `/api/controls` | GET, POST, PUT, DELETE |
| Incident | `/api/incidents` | GET, POST, PUT, DELETE |
| Internal Audit | `/api/internal_audits` | GET, POST, PUT, DELETE |
| Training | `/api/trainings` | GET, POST, PUT, DELETE |

### Swagger UI

Interactive API documentation with try-it-out functionality:
```
http://localhost:8000/api
```

### ReDoc

Alternative documentation view:
```
http://localhost:8000/api/docs
```

## Usage Examples

### Authentication

First, authenticate to get a session:
```bash
curl -X POST http://localhost:8000/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin"}'
```

### Create a Risk

```bash
curl -X POST http://localhost:8000/api/risks \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Unauthorized Access to Customer Database",
    "description": "Risk of unauthorized access to customer data",
    "asset": "/api/assets/1",
    "probability": 3,
    "impact": 5,
    "residualProbability": 2,
    "residualImpact": 3,
    "treatmentStrategy": "mitigate",
    "status": "identified",
    "riskOwner": "John Doe"
  }'
```

### List Risks with Filters

```bash
# Filter by status
curl "http://localhost:8000/api/risks?status=active"

# Filter by date range
curl "http://localhost:8000/api/risks?createdAt[after]=2024-01-01"

# Search by title
curl "http://localhost:8000/api/risks?title=database"

# Sort by creation date
curl "http://localhost:8000/api/risks?order[createdAt]=desc"

# Pagination
curl "http://localhost:8000/api/risks?page=2&itemsPerPage=50"
```

### Get Risk with Computed Properties

```bash
curl http://localhost:8000/api/risks/1
```

Response includes computed properties:
```json
{
  "@context": "/api/contexts/Risk",
  "@id": "/api/risks/1",
  "@type": "Risk",
  "id": 1,
  "title": "Unauthorized Access to Customer Database",
  "inherentRiskLevel": 15,
  "residualRiskLevel": 6,
  "riskReduction": 60.0,
  "isHighRisk": true,
  "controlCoverageCount": 3,
  "wasAssessmentAccurate": null
}
```

## API Features

### 1. Validation Constraints

All entities have comprehensive validation. Invalid data returns 400 Bad Request:

```json
{
  "@context": "/api/contexts/ConstraintViolationList",
  "@type": "ConstraintViolationList",
  "violations": [
    {
      "propertyPath": "probability",
      "message": "Probability must be between 1 and 5"
    }
  ]
}
```

**Risk Validation:**
- title: Required, max 255 characters
- description: Required
- asset: Required (must exist)
- probability/impact: Required, range 1-5
- treatmentStrategy: Choice (accept, mitigate, transfer, avoid)
- status: Choice (identified, assessed, treated, monitored, closed, accepted)

**Asset Validation:**
- name: Required, max 255 characters
- assetType: Required, max 100 characters
- owner: Required
- CIA values: Required, range 1-5
- status: Choice (active, inactive, retired, disposed)

**Control Validation:**
- controlId: Required, regex `/^\d+\.\d+(\.\d+)?$/` (ISO 27001 format)
- name, description: Required
- implementationStatus: Choice (not_started, planned, in_progress, implemented, verified)
- implementationPercentage: Range 0-100

**Incident Validation:**
- incidentNumber, title, description: Required
- severity: Choice (low, medium, high, critical)
- status: Choice (open, investigating, resolved, closed)
- detectedAt: Required
- dataBreachOccurred, notificationRequired: Required (boolean)

### 2. API Filters

#### Search Filter (Partial Match)
```bash
# Search risks by title
curl "http://localhost:8000/api/risks?title=unauthorized"

# Search assets by name
curl "http://localhost:8000/api/assets?name=server"
```

#### Exact Match Filter
```bash
# Filter by status
curl "http://localhost:8000/api/risks?status=active"

# Filter controls by category
curl "http://localhost:8000/api/controls?category=Access+Control"
```

#### Date Filter
```bash
# Risks created after date
curl "http://localhost:8000/api/risks?createdAt[after]=2024-01-01"

# Incidents before date
curl "http://localhost:8000/api/incidents?detectedAt[before]=2024-12-31"

# Date range
curl "http://localhost:8000/api/incidents?detectedAt[after]=2024-01-01&detectedAt[before]=2024-12-31"
```

#### Boolean Filter
```bash
# Only applicable controls
curl "http://localhost:8000/api/controls?applicable=true"

# Data breach incidents
curl "http://localhost:8000/api/incidents?dataBreachOccurred=true"
```

#### Order Filter
```bash
# Sort by creation date descending
curl "http://localhost:8000/api/risks?order[createdAt]=desc"

# Sort by title ascending
curl "http://localhost:8000/api/risks?order[title]=asc"
```

### 3. Pagination

Default: 30 items per page, max 100.

```bash
# Page 2
curl "http://localhost:8000/api/risks?page=2"

# Custom page size
curl "http://localhost:8000/api/risks?itemsPerPage=50"

# Disable pagination (get all)
curl "http://localhost:8000/api/risks?pagination=false"
```

Response includes pagination metadata:
```json
{
  "@context": "/api/contexts/Risk",
  "@id": "/api/risks",
  "@type": "hydra:Collection",
  "hydra:totalItems": 156,
  "hydra:member": [...],
  "hydra:view": {
    "@id": "/api/risks?page=1",
    "@type": "hydra:PartialCollectionView",
    "hydra:first": "/api/risks?page=1",
    "hydra:last": "/api/risks?page=6",
    "hydra:next": "/api/risks?page=2"
  }
}
```

### 4. Computed Properties

Entities expose computed properties for business intelligence:

**Risk:**
- `inherentRiskLevel`: probability × impact
- `residualRiskLevel`: residualProbability × residualImpact
- `riskReduction`: percentage reduction
- `isHighRisk`: inherent level ≥ 15
- `controlCoverageCount`: number of mitigating controls
- `incidentCount`: realized incidents
- `wasAssessmentAccurate`: validates risk assessment

**Asset:**
- `totalValue`: sum of CIA values
- `riskScore`: 0-100 based on risks, incidents, controls
- `isHighRisk`: risk score > 70
- `protectionStatus`: adequately_protected | under_protected | unprotected

**Control:**
- `effectivenessScore`: 0-100 based on incidents after implementation
- `needsReview`: true if review overdue
- `assetCoverageCount`: number of protected assets
- `riskCoverageCount`: number of mitigated risks
- `trainingStatus`: comprehensive | partial | none
- `addressesCriticalRisks`: true if covers high-risk items

**Incident:**
- `hasCriticalAssetsAffected`: checks for high-risk assets
- `totalAssetImpact`: sum of affected asset values
- `estimatedImpactCost`: severity-based cost estimate
- `isRiskValidated`: links to pre-identified risks

### 5. Security

#### Role-Based Access Control

- **ROLE_USER**: Read, Create, Update
- **ROLE_ADMIN**: All operations including Delete

Configure in `config/packages/security.yaml`:
```yaml
security:
    access_control:
        - { path: ^/api, roles: ROLE_USER }
```

#### Entity-Level Security

Already configured in all entities:
```php
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ]
)]
```

## Performance Optimizations

### Database Indexes

All entities have indexes on frequently queried fields:

**Risk:** status, created_at, review_date
**Asset:** asset_type, status, created_at
**Control:** control_id, category, implementation_status, target_date, applicable
**Incident:** incident_number, severity, status, category, detected_at, data_breach_occurred
**InternalAudit:** audit_number, status, scope_type, planned_date
**Training:** training_type, status, scheduled_date

### MaxDepth Protection

All relationships have `MaxDepth(1)` to prevent:
- Circular reference issues
- Deep nesting in responses
- Performance problems with complex object graphs

Related entities beyond depth 1 are returned as IRIs:
```json
{
  "id": 1,
  "title": "Risk",
  "asset": {
    "id": 5,
    "name": "Database Server",
    "risks": [
      "/api/risks/1",
      "/api/risks/2"
    ]
  }
}
```

## Troubleshooting

### Database Connection Failed

```bash
# Check PostgreSQL is running
sudo systemctl status postgresql

# Test connection
psql -U isms_user -d little_isms -h 127.0.0.1
```

### Validation Errors

Enable debug mode in `.env.local`:
```env
APP_ENV=dev
APP_DEBUG=true
```

### Clear Cache

```bash
php bin/console cache:clear
```

### Regenerate Migrations

```bash
# Generate new migration based on entity changes
php bin/console doctrine:migrations:diff

# Check SQL before applying
php bin/console doctrine:migrations:status

# Apply
php bin/console doctrine:migrations:migrate
```

## Production Deployment

### 1. Environment Configuration

Create `.env.prod.local`:
```env
APP_ENV=prod
APP_DEBUG=false
APP_SECRET=your-secret-key-here
DATABASE_URL="postgresql://prod_user:strong_password@db.example.com:5432/isms_prod?serverVersion=16&charset=utf8"
```

### 2. Optimize Autoloader

```bash
composer install --no-dev --optimize-autoloader
composer dump-autoload --optimize --classmap-authoritative
```

### 3. Build Cache

```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

### 4. Run Migrations

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

### 5. Configure Web Server

**Nginx:**
```nginx
server {
    listen 80;
    server_name api.little-isms.local;
    root /var/www/Little-ISMS-Helper/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

### 6. Enable HTTPS

```bash
certbot --nginx -d api.little-isms.local
```

### 7. Rate Limiting (Recommended)

Install rate limiter:
```bash
composer require symfony/rate-limiter
```

Configure in `config/packages/rate_limiter.yaml`:
```yaml
framework:
    rate_limiter:
        api:
            policy: 'sliding_window'
            limit: 100
            interval: '60 minutes'
```

## Next Steps

1. ✅ Setup database and run migrations
2. ✅ Test API endpoints with Swagger UI
3. ⬜ Configure authentication (OAuth2/SAML)
4. ⬜ Add rate limiting for production
5. ⬜ Implement monitoring (e.g., Sentry, New Relic)
6. ⬜ Write integration tests
7. ⬜ Set up CI/CD pipeline

## Support

For issues or questions:
- Check `docs/` folder for additional documentation
- Review Symfony docs: https://symfony.com/doc/current/
- API Platform docs: https://api-platform.com/docs/

---

**Last Updated:** 2024-11-06
**Version:** 1.0.0
**Symfony:** 7.3
**API Platform:** 4.2.3
