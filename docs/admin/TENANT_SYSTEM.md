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
   - Only ROLE_ADMIN can manage tenants
   - Super admins can disable filter for cross-tenant operations
   - Audit log tracks all tenant changes

3. **User Assignment:**
   - Users must belong to exactly one tenant
   - Tenant cannot be deleted if users are assigned
   - Deactivating tenant blocks all user logins for that tenant

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
