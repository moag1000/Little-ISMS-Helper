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

**Required Role:** `ROLE_ADMIN`

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

2. **Admin Access:**
   - ROLE_ADMIN manages only its own tenant tree; ROLE_SUPER_ADMIN is the
     instance operator (see "Upgrade notes — 3.15.3 security release")
   - Super admins can disable filter for cross-tenant operations
   - Audit log tracks all tenant changes

3. **User Assignment:**
   - Users must belong to exactly one tenant
   - Tenant cannot be deleted if users are assigned
   - Deactivating tenant blocks all user logins for that tenant

## Upgrade notes — 3.15.3 security release

3.15.3 closes privilege-escalation and cross-tenant paths for organisation
(tenant) admins. Read this before upgrading; several behaviours change on
purpose.

### Role model after the upgrade

- `ROLE_SUPER_ADMIN` = **instance operator** (global). Only this role may
  grant or revoke the privileged roles `ROLE_SUPER_ADMIN`, `ROLE_GROUP_CISO`,
  `ROLE_KONZERN_AUDITOR` and `ROLE_ALLOWED_TO_SWITCH`, see users without an
  organisation, manage instance-wide backups and edit system roles.
- `ROLE_ADMIN` = **organisation admin**, limited to its own organisation and
  its subsidiaries (the accessible tenant tree). It can no longer see, edit
  or delete users of other organisations, nor edit/delete any
  `ROLE_SUPER_ADMIN` account.

### What the upgrade does automatically

Migration `Version20261019120000_operator_bootstrap` (data only, idempotent,
logs every change in the `doctrine:migrations:migrate` output):

1. If **no** user holds `ROLE_SUPER_ADMIN`, the initial setup admin (the
   user with the lowest id holding `ROLE_ADMIN`, the same rule the app uses
   to protect that account) is promoted to `ROLE_SUPER_ADMIN`. Default
   installs never had an operator account — the setup admin effectively
   acted as one through the escalation this release closes.
2. If exactly **one** organisation exists, users without an organisation
   are assigned to it (instance operators stay without organisation). With
   several organisations nothing is assigned; the affected e-mail addresses
   are listed in the migration output and a `ROLE_SUPER_ADMIN` must assign
   them under *Admin → Users*.

Migration `Version20261019120100_reserved_custom_role_names` changes no
data; it lists custom roles affected by the reserved-name rule below.

New installs: `app:setup-permissions` (and the setup wizard, which uses it)
creates the initial admin with `ROLE_SUPER_ADMIN` when no operator exists.

**Recovery** — wrong account promoted, or none existed:

```bash
php bin/console app:user:grant-super-admin admin@example.com
```

The command asks for confirmation and writes an audit-log entry
(`user.super_admin.granted`). Shell access is the authorisation; there is no
web UI for this.

### Behaviour changes for organisation admins

- **Users:** user list, CSV export (sync and async), import, bulk actions,
  MFA administration, session overview/termination and login statistics are
  limited to the accessible tenant tree. Imported users are placed in the
  admin's organisation. The import no longer says an e-mail "already
  exists"; the per-row message is generic. An async user export can only be
  downloaded by the user who started it (or a `ROLE_SUPER_ADMIN`).
- **Organisation pages:** *Admin → Organisations* only lists and opens the
  accessible tree (404 otherwise). Adding a user to an organisation requires
  that the admin may edit the user; making users organisation-less and
  re-parenting outside the own tree are `ROLE_SUPER_ADMIN` only.
- **Backups:** organisation admins only see, download, validate, restore or
  delete backups whose embedded scope lies within their tree. **Instance-wide
  backups (and legacy backups without scope) are hidden from them** and
  answer 404 — previously they were listed. Only a `ROLE_SUPER_ADMIN` can
  work with them.
- **Custom roles:** custom roles are global. An organisation admin can only
  edit or delete a custom role if it may edit every holder; holders in other
  organisations are no longer shown. System roles are `ROLE_SUPER_ADMIN`
  only. The user form no longer offers privileged-named custom roles to
  organisation admins (roles a user already holds are kept on edit).
- **Guided-tour report:** lists, exports and resets only users of the
  accessible tree.

### Reserved custom-role names

A custom role named like a reserved Symfony role (e.g. `ROLE_ADMIN`,
`ROLE_CISO`, `ROLE_SUPER_ADMIN`; the list lives in
`src/Security/RoleNames.php`) only grants that role if it is a seeded
**system** role, and `ROLE_SUPER_ADMIN` / `ROLE_ALLOWED_TO_SWITCH` are never
granted through a custom role at all. Non-system custom roles with such names
now grant nothing beyond their permission set. They are **not** upgraded
automatically (that would re-open the escalation). Check the output of the
`Version20261019120100_reserved_custom_role_names` migration and, if a role
is still needed, have a `ROLE_SUPER_ADMIN` re-create it as a system role or
assign the Symfony role directly. New non-system roles with reserved names
are rejected.

### SSO (OIDC) providers owned by an organisation

An organisation admin controls issuer, JWKS and therefore every claim of an
organisation-owned identity provider. Such a provider now:

- only logs in to / links with existing accounts **inside the provider's
  organisation tree**, and never with accounts holding a privileged role.
  Logins that previously linked accounts outside the tree (or privileged
  accounts) by e-mail are rejected — including existing links. Holding-level
  users (`ROLE_GROUP_CISO`, `ROLE_KONZERN_AUDITOR`) and operators must use a
  **global** provider (configured by a `ROLE_SUPER_ADMIN`) or a local login;
- never assigns a privileged role, whether through a claim→role mapping, the
  default role or the fallback role — such mappings are ignored at login.
  Saving a role mapping with a privileged role requires `ROLE_SUPER_ADMIN`.

Global providers behave as before.

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
