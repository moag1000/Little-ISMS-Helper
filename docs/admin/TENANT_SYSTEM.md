# Tenant System Documentation

## Overview

Little ISMS Helper includes a **complete multi-tenancy system** that allows multiple organizations (tenants) to use the same application instance while keeping their data completely separated.

## Core Components

### 1. Tenant Entity (`src/Entity/Tenant.php`)

The Tenant entity represents an organization/client in the system.

**Properties:**
- `code` (string, unique) - Tenant identifier (alphanumeric, used in URLs)
- `name` (string) - Tenant display name
- `description` (text, optional) - Tenant description
- `azureTenantId` (UUID, optional) - Azure AD tenant ID for SSO
- `isActive` (boolean) - Tenant activation status
- `settings` (JSON, optional) - Tenant-specific settings override
- `logoPath` (string, optional) - Path to tenant logo
- `createdAt` / `updatedAt` - Timestamps

**Relations:**
- OneToMany with `User` - All users belonging to this tenant

### 2. Tenant Context Service (`src/Service/TenantContext.php`)

Manages the current tenant context based on the logged-in user.

**Key Methods:**
```php
// Get current tenant
$tenant = $tenantContext->getCurrentTenant();

// Get current tenant ID
$tenantId = $tenantContext->getCurrentTenantId();

// Check if tenant context exists
if ($tenantContext->hasTenant()) { ... }

// Check if user belongs to specific tenant
if ($tenantContext->belongsToTenant($tenant)) { ... }

// Get all active tenants
$tenants = $tenantContext->getActiveTenants();
```

**Usage in Controllers:**
```php
use App\Service\TenantContext;

class MyController extends AbstractController
{
    public function __construct(
        private TenantContext $tenantContext
    ) {}

    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        // ... tenant is automatically determined from logged-in user
    }
}
```

### 3. Doctrine Tenant Filter (`src/Doctrine/TenantFilter.php`)

Automatically filters all database queries to only return entities belonging to the current tenant.

**Features:**
- Transparent query filtering
- Automatically applied to all entities with a `tenant` relation
- Skips filtering for Tenant and User entities
- Can be disabled for admin operations

**Configuration** (in `config/packages/doctrine.yaml`):
```yaml
doctrine:
    orm:
        filters:
            tenant_filter:
                class: App\Doctrine\TenantFilter
                enabled: true
```

**Enable/Disable Filter:**
```php
// Disable filter temporarily (e.g., for admin operations)
$filter = $em->getFilters()->disable('tenant_filter');

// Re-enable filter
$filter = $em->getFilters()->enable('tenant_filter');

// Set tenant ID for filter
$filter->setParameter('tenant_id', $tenantId);
```

## Tenant Management UI

### Admin Portal Access

Navigate to: `/admin/tenants`

**Required Role:** `ROLE_ADMIN` (the `TENANT_*` permissions), scoped to the organisation:

| Action | Organisation admin (`ROLE_ADMIN`) | Instance operator (`ROLE_SUPER_ADMIN`) |
|---|---|---|
| List, corporate structure | Own organisation and its subsidiaries | All organisations |
| Show, edit, organisation context, ISMS context, remove user | Own organisation and its subsidiaries | All organisations |
| Edit code, active flag, parent, corporate-parent flag, Azure tenant ID, raw settings | Read-only | Yes |
| Governance model of a subsidiary | Admin of the parent organisation | Yes |
| Create, activate/deactivate, delete, set parent, add (move) a user | No | Yes |

### Features

1. **Tenant List** (`/admin/tenants`)
   - View all tenants
   - Filter: All / Active / Inactive
   - Statistics: Total, Active, Inactive counts
   - Columns: Code, Name, Description, Azure Tenant ID, Status, User Count

2. **Create Tenant** (`/admin/tenants/new`)
   - Tenant Code (unique identifier)
   - Tenant Name
   - Description (optional)
   - Azure Tenant ID (optional, for SSO)
   - Active Status (checkbox)
   - Settings (JSON editor for tenant-specific configuration)

3. **View Tenant** (`/admin/tenants/{id}`)
   - Tenant information
   - Statistics: Total Users, Active Users, Inactive Users
   - User list (showing first 10 users)
   - Settings display (if configured)
   - Actions: Edit, Activate/Deactivate, Delete

4. **Edit Tenant** (`/admin/tenants/{id}/edit`)
   - Update all tenant properties
   - Metadata: Created At, Updated At
   - Help sidebar with field descriptions

5. **Tenant Actions**
   - **Activate/Deactivate**: Toggle tenant status (POST `/admin/tenants/{id}/toggle`)
   - **Delete**: Remove tenant (only if no users assigned)

## Tenant Settings Override

Tenants can have custom settings that override global application settings.

**Settings Structure (JSON):**
```json
{
  "branding": {
    "primaryColor": "#0d6efd",
    "logoUrl": "/uploads/tenants/acme-corp/logo.png"
  },
  "features": {
    "darkMode": true,
    "globalSearch": false,
    "customModule": true
  },
  "locale": "de",
  "timezone": "Europe/Berlin"
}
```

**Usage:**
```php
$settings = $tenant->getSettings();
$primaryColor = $settings['branding']['primaryColor'] ?? '#default';
```

## Logo Upload

Each tenant can have a custom logo.

**Entity Property:**
- `logoPath` (string, nullable) - Path to logo file (e.g., `/uploads/tenants/acme-corp/logo.png`)

**Implementation:**
1. Logo upload field in TenantType form (deferred)
2. Logo file stored in `public/uploads/tenants/{tenant_code}/`
3. Display logo in tenant detail view and application header (tenant context)

## Multi-Tenancy Best Practices

### 1. Entity Design

All tenant-specific entities should have a `ManyToOne` relation to Tenant:

```php
use App\Entity\Tenant;

class Asset
{
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    // ... getters/setters
}
```

### 2. Automatic Tenant Assignment

Use Doctrine event listeners to automatically assign tenants:

```php
use App\Service\TenantContext;
use Doctrine\ORM\Event\PrePersistEventArgs;

class TenantAssignListener
{
    public function __construct(private TenantContext $tenantContext) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Asset && !$entity->getTenant()) {
            $entity->setTenant($this->tenantContext->getCurrentTenant());
        }
    }
}
```

### 3. Tenant Isolation

The Doctrine Tenant Filter ensures automatic tenant isolation:
- Queries automatically filtered by tenant_id
- No manual WHERE clauses needed
- Admin users can bypass filter for cross-tenant operations

### 4. Tenant Switching (Admin Feature)

Admin users can switch between tenants:

```php
// In admin controller
$tenantContext->setCurrentTenant($selectedTenant);

// All subsequent operations will use this tenant
$assets = $assetRepository->findAll(); // Only returns assets for selected tenant
```

## Security Considerations

1. **Tenant Isolation:**
   - Doctrine Filter ensures data separation
   - Users can only access their tenant's data
   - Cross-tenant queries blocked by default
   - Accounts without an organisation: a `ROLE_SUPER_ADMIN` stays instance-wide;
     any other account sees no organisation's data (`TenantFilter::NO_ORGANISATION`,
     only rows with no tenant) and gets no default organisation context.
     Anonymous requests (login, setup wizard, public share links) and CLI/jobs
     are not affected. On a single-organisation installation accounts created
     without one (setup-wizard admin, global SSO provider, `app:setup-permissions`)
     join the sole organisation automatically; with several organisations an
     operator assigns them.
   - Record-level checks (voters) share one guard,
     `HoldingTreeAccessTrait::decideTenantScope()`: `ROLE_SUPER_ADMIN` may act on
     any record; everyone else, `ROLE_ADMIN` included, only on records of their own
     organisation. `ROLE_GROUP_CISO` and `ROLE_KONZERN_AUDITOR` may additionally
     *read* records of subsidiaries (never write). A missing tenant on the user or
     the record denies access. Bulk export/assign/delete endpoints use the same
     comparison (`TenantOwnership`); only a `ROLE_SUPER_ADMIN` without an
     organisation context processes records of all organisations.

2. **Admin Access:**
   - Only ROLE_ADMIN can manage tenants, and only within their own organisation tree
     (`TenantScopedAdminVoter::ADMIN_OWN_TENANT`); instance-wide operations need
     ROLE_SUPER_ADMIN (`ADMIN_GLOBAL_OP`) — see the table under *Admin Portal Access*
   - Super admins can disable filter for cross-tenant operations
   - Audit log tracks all tenant changes

3. **User Assignment:**
   - Users must belong to exactly one tenant
   - Tenant cannot be deleted if users are assigned
   - Deactivating tenant blocks all user logins for that tenant

## Upgrade notes — first release after 3.15.2

Read this before deploying the release that follows 3.15.2 (tenant-isolation
hardening). Single-organisation installations need no action.

### Accounts without an organisation see no data (multi-organisation installs)

**Effect.** The tenant filter used to show an account without an organisation
the data of *every* organisation. It now shows such an account *no*
organisation's data — only a `ROLE_SUPER_ADMIN` without an organisation stays
instance-wide. Migration `Version20261016150000` assigns accounts without an
organisation to the sole organisation, but only when exactly one organisation
exists; with two or more it leaves them untouched. Existing `ROLE_ADMIN`,
`ROLE_MANAGER`, `ROLE_USER` … accounts without an organisation therefore go
from "see everything" to "see nothing" after the upgrade.

**Before the upgrade**, list the affected accounts:

```sql
SELECT id, email, roles FROM users
WHERE tenant_id IS NULL AND roles NOT LIKE '%ROLE_SUPER_ADMIN%';
```

**Fix.** Log in as a `ROLE_SUPER_ADMIN` (an admin without an organisation can
no longer see the accounts it would have to fix), open **`/admin/users`**,
edit each listed account and pick its organisation in the *Organization*
field (German UI: *Organisation*). `/quick-fix` does not help here: Data
repair deliberately skips user accounts, because a SUPER_ADMIN may
legitimately have no organisation.

New accounts created by SSO (generic OIDC, Azure OAuth, Azure SAML) on a
multi-organisation install without a provider-specific organisation also start
without one and need the same assignment.

### Async job status from before the deploy returns 403

**Effect.** Job status files (`var/jobs/<uuid>.json`) now record their owner
(`owner_user_id`, `owner_tenant_id`), and status polling plus export downloads
are only served to that owner. Files written before the deploy have no owner,
so polling a job started before the upgrade — and downloading its export —
returns **403** for everyone except a `ROLE_SUPER_ADMIN`.

**Fix.** Let running admin jobs/exports finish (and download their files)
before deploying, or simply start them again after the deploy. Old status
files are harmless and can be deleted from `var/jobs/` once no job is running.

## Migration

### Add logoPath Field

Migration: `migrations/Version20251113000000.php`

```bash
php bin/console doctrine:migrations:migrate
```

This adds the `logo_path` column to the `tenant` table.

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/admin/tenants` | GET | List all tenants |
| `/admin/tenants/new` | GET/POST | Create new tenant |
| `/admin/tenants/{id}` | GET | View tenant details |
| `/admin/tenants/{id}/edit` | GET/POST | Edit tenant |
| `/admin/tenants/{id}/toggle` | POST | Activate/deactivate tenant |
| `/admin/tenants/{id}/delete` | POST | Delete tenant (if no users) |

## Future Enhancements

- **Logo Upload UI** - File upload form for tenant logos
- **Tenant Analytics** - Usage statistics per tenant
- **Billing Integration** - Per-tenant billing/subscription
- **Tenant White-labeling** - Custom branding per tenant
- **Tenant API Keys** - API authentication per tenant
- **Cross-Tenant Reporting** - Aggregated reports for MSPs

## Related Documentation

- **ADMIN_GUIDE.md** - Admin portal user guide
- **ROADMAP.md** - Phase 6L-C: Tenant Management UI
- **README.md** - Admin Portal section
