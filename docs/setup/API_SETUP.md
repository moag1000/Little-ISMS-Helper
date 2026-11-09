# Little ISMS Helper - REST API Setup Guide

## Overview

REST API of Little ISMS Helper (Symfony 7.4, API Platform 4.3). 21 entities are API resources
(`grep -l '#\[ApiResource' src/Entity`); the current list is the Swagger UI at `/api`.

- Serialization groups: the API writes exactly the fields the form writes (`<entity>:write` groups)
- Validation: the entity `#[Assert]` constraints, same as the form
- Filters on collection endpoints, pagination (30 per page, client-configurable up to 100)
- `MaxDepth(1)` on relations
- Access: session or API token, entity voters, tenant isolation (see [Security](#5-security))

## Prerequisites

- PHP 8.4+
- MySQL 8.0+ / MariaDB (default in `.env`; PostgreSQL is a commented alternative there)
- Composer 2.x
- Symfony CLI (optional)

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

Edit `.env.local` (`.env` builds `DATABASE_URL` from `DB_USER`, `DB_PASS`, `DB_HOST`, `DB_PORT`, `DB_NAME`):
```env
DATABASE_URL="mysql://isms_user:isms_password@127.0.0.1:3306/little_isms?serverVersion=8.0.32&charset=utf8mb4"
```

### 3. Create Database and User

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
```

Sample data: Admin → Modules (imported into the admin's current organisation).

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
| ISMS context, ISMS objective | `/api/isms_contexts`, `/api/isms_objectives` | also PATCH |
| Asset | `/api/assets` | GET, POST, PUT, DELETE |
| Control | `/api/controls` | GET, POST, PUT, DELETE |
| Incident | `/api/incidents` | GET, POST, PUT, DELETE |
| Internal Audit | `/api/internal_audits` | GET, POST, PUT, DELETE |
| Training | `/api/trainings` | GET, POST, PUT, DELETE |
| Supplier, Location, Person, BC plan, BC exercise, change request, interested party, threat intelligence | `/api/suppliers`, `/api/locations`, … | GET, POST, PUT, DELETE (each behind its module) |
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
- Evidence fields are read-only (`#[ApiReadOnlyEvidence]`): risk acceptance and decision approver/date,
  `formallyAccepted`, supplier board acceptance, change-request approval and verification, incident
  resolve/close times and the NIS-2 / DORA report timestamps (set only by "Als gemeldet markieren" in
  the UI; a value in the body is ignored).
- Control `implementationStatus` and `implementationPercentage` are read-only: they feed requirement
  fulfilment and are set in the control form, the SoA or a reviewed bulk import.
- **Owners are User/Person references** (BREAKING since 2026-11): the free-text owner columns are gone.
  Fields: e.g. Asset `ownerUser` / `ownerPerson`, Risk `riskOwner` / `riskOwnerPerson` (ROLE_MANAGER).
  A person is sent as IRI (`/api/people/{id}`); `User` is no API resource. A user or person of another
  organisation answers 422 (`#[SameTenantOwner]`). A plain `owner` string is no longer accepted.
- Decisions of top management, register entries and suggestions are not created over the API.
- `PUT` updates the stored record with the writable fields only (`standard_put: false` in
  `config/packages/api_platform.yaml`); fields you leave out keep their value.
- Reviewer roles (`ROLE_AUDITOR`, `ROLE_GROUP_CISO`, `ROLE_KONZERN_AUDITOR`) and API tokens are
  read-only: write calls answer 403 (session) or 405 (token). Writing with a token is possible only
  through the assistant endpoint `/mcp` (see [`KI_ASSISTENT_MCP.md`](../user-guide/KI_ASSISTENT_MCP.md)).

### Swagger UI

Interactive API documentation with try-it-out functionality:
```
http://localhost:8000/api
```

### OpenAPI / ReDoc

OpenAPI document and ReDoc view (`enable_re_doc: true`):
```
http://localhost:8000/api/docs
```

Both need a login or an API token.

## Usage Examples

### Authentication

Two ways:

- **Browser session** (logged in via `/login`): read and write with the rights of the form.
- **API token** (read only): create one under *Profile → API tokens* (`/de/profile/api-tokens`); the
  plain token is shown once, only its SHA-256 hash is stored. Tokens can expire and be revoked.

```bash
curl -H "Authorization: Bearer <token>" http://localhost:8000/api/risks
```

Token rules (`api_token` firewall, `App\Security\ApiTokenAuthenticator`):

- stateless: a token request never creates a session or cookie;
- accepted only on `/api` and `/mcp`; on every other path the answer is 401;
- write verbs answer 405 everywhere except `POST /mcp` (`ReadOnlyApiSubscriber`);
- the token acts with its user's roles and organisation; a deactivated user (or a user of a deactivated
  organisation) is refused (`UserChecker`), and deactivating a user revokes their tokens;
- 20 failed token attempts per client IP in 10 minutes → 429 (`api_auth_failure` limiter).

### Create a Risk (session)

```bash
curl -X POST http://localhost:8000/api/risks \
  -H "Content-Type: application/json" \
  -b "isms_session=<session cookie>" \
  -d '{
    "title": "Unauthorized Access to Customer Database",
    "description": "Risk of unauthorized access to customer data",
    "asset": "/api/assets/1",
    "riskOwnerPerson": "/api/people/7",
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
  "highRisk": true,
  "controlCoverageCount": 3,
  "assessmentAccurate": null
}
```

## API Features

### 1. Validation Constraints

The constraints are the entity `#[Assert]` attributes, the same the form uses. Invalid data answers
422 with a `ConstraintViolationList`:

```json
{
  "@type": "ConstraintViolationList",
  "violations": [
    { "propertyPath": "probability", "message": "…" }
  ]
}
```

Allowed values (choices, ranges, the control id pattern) are in the OpenAPI document and the entity
classes (`src/Entity/<Name>.php`).

### 2. API Filters

Filters apply to the collection endpoints (`GET /api/<resource>`) only. They are declared as
`#[QueryParameter]` on each entity's `GetCollection` operation and listed in the OpenAPI document
(`/api/docs`).

| Endpoint | Search (partial) | Search (exact) | Boolean | Date range | Sort `order[…]` |
|---|---|---|---|---|---|
| `/api/assets` | `name` | `assetType`, `status` | – | – | `name`, `assetType`, `createdAt` |
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

Read-only values serialized with the read group (selection):

- **Risk:** `inherentRiskLevel`, `residualRiskLevel`, `riskReduction`, `riskScore`, `highRisk`
  (inherent level ≥ the organisation's "high" edge, `RiskMatrixThresholds`), `controlCoverageCount`,
  `incidentCount`, `assessmentAccurate`, `riskOwnerName`
- **Asset:** `totalValue` (highest CIA value), `effectiveOwner`
- **Control:** `effectivenessScore`, `reviewNeeded`, `protectedAssetValue`, `highRiskAssetCount`,
  `trainingStatus`
- **Incident:** `criticalAssetsAffected`, `totalAssetImpact`, `nis2ComplianceStatus`,
  `criticalProcessesAffected`, `affectedProcessCount`, `suggestedRecoveryPriority`,
  `aggregatedBusinessImpactScore`

### 5. Security

#### Role-Based Access Control

- Every operation carries its own security expression; tenant isolation is enforced by the
  Doctrine tenant filter (collections) and the entity voters (`ApiTenantVoter`, `RiskVoter`, …)
  for single records. A POST always lands in the caller's own organisation.
- Create and update with the same rights as the form: the entity voter decides (e.g. editing a risk
  needs `ROLE_MANAGER`, the `risk.edit` permission or being responsible for it).
- **ROLE_ADMIN**: delete (own organisation; `ROLE_SUPER_ADMIN` everywhere).
- **Reviewer roles** (`ROLE_AUDITOR`, `ROLE_GROUP_CISO`, `ROLE_KONZERN_AUDITOR`): read only
  (`ReadOnlyRoleSubscriber`). **API tokens**: read only (`ReadOnlyApiSubscriber`).
- Rate limit: 100 requests per minute per API token (else per user, else per IP).
- Module-owned resources answer 403 `module_not_active` while their module is off
  (`ApiResourceModuleListener`, also for sub-requests).
- Field-level gates: the risk owner and the quantitative risk fields need ROLE_MANAGER.

#### Serialization

Every resource names its read and write groups (`normalizationContext` /
`denormalizationContext`). Linked user accounts and the organisation are never serialized with their own fields; the new read-only resources name people by display name only.
`tests/Api/ApiResourceContractTest.php` fails when a resource has no groups, a
lifecycle-managed `status` is writable or an `#[ApiReadOnlyEvidence]` field sits in a write group.
All non-UI security properties and their tests: [`NON_UI_CONTRACTS.md`](../security/NON_UI_CONTRACTS.md).

## Performance Optimizations

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
# Test connection
mysql -u isms_user -p -h 127.0.0.1 little_isms
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
DATABASE_URL="mysql://prod_user:strong_password@db.example.com:3306/isms_prod?serverVersion=8.0.32&charset=utf8mb4"
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

## Support

- `docs/` for further documentation
- Symfony: https://symfony.com/doc/current/
- API Platform: https://api-platform.com/docs/
