# Little ISMS Helper - REST API Setup Guide

## Overview

This document provides comprehensive setup instructions for the Little ISMS Helper REST API, built with Symfony 7.4 and API Platform 4.3.

> **Note:** The API currently exposes 18 API resource entities (not just the 6 listed below). The endpoint table documents the original set; additional resources are available at `/api` via the Swagger UI.

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

All endpoints available at `/api` (the complete, current list is the Swagger UI):

| Entity | Endpoint | Operations |
|--------|----------|------------|
| Risk | `/api/risks` | GET, POST, PUT, DELETE |
| Asset | `/api/assets` | GET, POST, PUT, DELETE |
| Control | `/api/controls` | GET, POST, PUT, DELETE |
| Incident | `/api/incidents` | GET, POST, PUT, DELETE |
| Internal Audit | `/api/internal_audits` | GET, POST, PUT, DELETE |
| Training | `/api/trainings` | GET, POST, PUT, DELETE |
| Supplier, Location, Person, BC plan, BC exercise, change request, interested party, threat intelligence, ISMS context, ISMS objective | `/api/suppliers`, `/api/locations`, … | GET, POST, PUT, DELETE (each behind its module) |
| Physical access log, cryptographic operation | `/api/physical_access_logs`, `/api/cryptographic_operations` | GET, POST |
| Top-management decision (ISO 27001 5.1, 6.1.3, 9.3; NIS-2 Art. 20) | `/api/management_decisions` | GET only, ROLE_AUDITOR and above; filters `type`, `status` |
| Register entry (ISO 27001 4.1–7.4) | `/api/isms_register_entries` | GET only; filter `type` |
| Requirement fulfilment | `/api/compliance_requirement_fulfillments` | GET only, module `compliance`; filters `framework` (code, e.g. `ISO27001`), `status`, `overrideSource` |

**What the API does not write.** The REST API follows the same rules as the UI:

- `status` of every lifecycle-managed record (risk, incident, objective, supplier, BC plan, …) is
  read-only. Transitions go through the lifecycle actions in the UI (roles, four-eyes, top-management
  approval, audit log); a `status` in a POST/PUT body is ignored.
- Fulfilment values are derived from controls, records and mappings
  (`FulfillmentDerivationService`); hand values are overrides with reason and review date in the UI.
  Saving a control or record over the API triggers the same recomputation as in the UI.
- Approval evidence is read-only: risk acceptance and decision approver/date, `formallyAccepted`,
  supplier board acceptance, change-request approval and verification.
- Decisions of top management, register entries and suggestions are not created over the API.
- `PUT` updates the stored record with the writable fields only (`standard_put: false` in
  `config/packages/api_platform.yaml`); fields you leave out keep their value.
- Reviewer roles (`ROLE_AUDITOR`, `ROLE_GROUP_CISO`, `ROLE_KONZERN_AUDITOR`) and API tokens are
  read-only: write calls answer 403 (session) or 405 (token).

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
    "treatmentStrategy": "mitigate"
  }'
```

### List Risks with Filters

```bash
curl "http://localhost:8000/api/risks?status=assessed&createdAt[after]=2024-01-01&order[createdAt]=desc"
```

All filter parameters per endpoint: see [API Filters](#2-api-filters).

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

Filters apply to the collection endpoints (`GET /api/<resource>`) only. They are declared as
`#[QueryParameter]` on each entity's `GetCollection` operation and listed in the OpenAPI document
(`/api/docs`).

| Endpoint | Search (partial) | Search (exact) | Boolean | Date range | Sort `order[…]` |
|---|---|---|---|---|---|
| `/api/assets` | `name`, `owner` | `assetType`, `status` | – | – | `name`, `assetType`, `createdAt` |
| `/api/controls` | `name` | `controlId`, `category`, `implementationStatus` | `applicable` | `targetDate`, `lastReviewDate` | `controlId`, `category`, `implementationPercentage`, `targetDate` |
| `/api/incidents` | `title` | `incidentNumber`, `severity`, `status`, `category` | `dataBreachOccurred`, `notificationRequired` | `detectedAt`, `resolvedAt`, `closedAt` | `detectedAt`, `severity`, `status` |
| `/api/internal_audits` | `title` | `auditNumber`, `status`, `scopeType` | – | `plannedDate`, `actualDate` | `plannedDate`, `actualDate`, `status` |
| `/api/risks` | `title`, `riskOwner.email`, `riskOwner.firstName`, `riskOwner.lastName` (ROLE_MANAGER) | `status` | – | `createdAt`, `reviewDate` | `title`, `createdAt`, `status` |
| `/api/suppliers` | `name` | `status`, `criticality` | – | `nextAssessmentDate`, `lastSecurityAssessment` | `name`, `criticality`, `nextAssessmentDate` |
| `/api/trainings` | `title` | `trainingType`, `status` | `mandatory` | `scheduledDate`, `completionDate` | `scheduledDate`, `status` |

- Partial search ignores case and treats `%` and `_` literally: `?title=database`.
- Exact search takes one or several values: `?status=assessed` or `?status[]=assessed&status[]=identified`.
- Boolean: `true`/`false` or `1`/`0`; any other value answers 422.
- Date range: `[after]`, `[before]` (inclusive), `[strictly_after]`, `[strictly_before]`:
  `?detectedAt[after]=2024-01-01&detectedAt[before]=2024-12-31`.
- Sort: `asc` or `desc`, any other value answers 422. Several sort keys apply in the order of the
  table column, not in the order of the query string: `?order[createdAt]=desc`.
- The `riskOwner.*` filters answer 403 below ROLE_MANAGER, like the `riskOwner` field itself.

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

- Every operation carries its own security expression; tenant isolation is enforced by the
  Doctrine tenant filter (collections) and the entity voters (`ApiTenantVoter`, `RiskVoter`, …)
  for single records. A POST always lands in the caller's own organisation.
- **ROLE_USER** and above: read, create, update inside the own organisation.
- **ROLE_ADMIN**: delete as well.
- **Reviewer roles** (`ROLE_AUDITOR`, `ROLE_GROUP_CISO`, `ROLE_KONZERN_AUDITOR`): read only
  (`ReadOnlyRoleSubscriber`). **API tokens**: read only (`ReadOnlyApiSubscriber`).
- Module-owned resources answer 403 `module_not_active` while their module is off
  (`ApiResourceModuleListener`, also for sub-requests).
- Field-level gates: the risk owner and the quantitative risk fields need ROLE_MANAGER.

#### Serialization

Every resource names its read and write groups (`normalizationContext` /
`denormalizationContext`). Linked user accounts and the organisation are never serialized with their own fields; the new read-only resources name people by display name only.
`tests/Api/ApiResourceContractTest.php` fails when a resource has no groups or a
lifecycle-managed `status` is writable.

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
**Symfony:** 7.4
**API Platform:** 4.3
