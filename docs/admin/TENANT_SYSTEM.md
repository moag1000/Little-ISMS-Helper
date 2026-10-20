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
     the record denies access.
   - Two scopes, one tree comparison: organisation *administration* (the table
     under *Admin Portal Access*: settings, contexts, membership) covers the own
     organisation and its subsidiaries; *records* (risks, assets, incidents …)
     cover the own organisation only, plus the holding read above. Both decide
     "own organisation or below" with `TenantOwnership::isSameOrDescendant()`.
   - Bulk endpoints (export, assign incl. the assignee, status change, delete)
     share one rule, `TenantOwnership::isInContextScope()`, which is the record
     scope: `ROLE_SUPER_ADMIN` processes records of all organisations (also with
     an organisation context set); everyone else, `ROLE_ADMIN` included, only
     records of the organisation it is acting in (`TenantContext`) — never of
     subsidiaries. Holding roles read subsidiary records one by one; they never
     bulk-process them.

2. **Admin Access:**
   - Only ROLE_ADMIN can manage tenants, and only within their own organisation tree
     (`TenantScopedAdminVoter::ADMIN_OWN_TENANT`); instance-wide operations need
     ROLE_SUPER_ADMIN (`ADMIN_GLOBAL_OP`) — see the table under *Admin Portal Access*
   - ROLE_SUPER_ADMIN is the instance operator (see "Upgrade notes — 3.15.3
     security release")
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
   to protect that account) is promoted to `ROLE_SUPER_ADMIN` — **but only
   if that admin has no organisation (default setup) or the instance has
   exactly one organisation.** Default installs never had an operator
   account — the setup admin effectively acted as one through the
   escalation this release closes.
   **Otherwise nobody is promoted** (the lowest-id admin of a
   multi-organisation instance may just be one organisation's admin): the
   migration output shows a `WARNING … nobody was promoted` line and you
   must run `php bin/console app:user:grant-super-admin <email>` for the
   correct operator account right after the upgrade — until then no account
   can manage users without an organisation, instance-wide backups or
   system roles.
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

**Action required:** existing SSO links of holding-level users
(`ROLE_GROUP_CISO`, `ROLE_KONZERN_AUDITOR`) or operators through an
organisation-owned provider stop working with this release — move these
users to a global provider or a local login before or right after the
upgrade.

Global providers behave as before.

## Upgrade notes — first release after 3.15.3

Read this before deploying the release that follows 3.15.3 (tenant-isolation
hardening). It builds on the 3.15.3 security release above — read that section
too if you upgrade straight from 3.15.2 or older. Single-organisation
installations need no action.

### Changes to the 3.15.3 rules

- **Roles are SUPER_ADMIN-only.** Creating, editing and deleting any role
  (custom or system) now requires `ROLE_SUPER_ADMIN`; the 3.15.3 exception
  "an organisation admin may edit a custom role if it may edit every holder"
  is gone, because a role is global and changes what users of every
  organisation may do. System roles can never be deleted.
- **User CSV export and guided-tour report** return exactly the users of the
  user list for the same account (one rule, `UserListScope`): `ROLE_ADMIN`
  its own organisation and its subsidiaries, `ROLE_SUPER_ADMIN` all users
  (with or without an organisation of its own). Holding roles without
  `ROLE_ADMIN` (they need `user.view_all`) reach only the user list and the
  CSV export, which follow their read scope; the guided-tour report requires
  `ROLE_ADMIN`. The async export receives the resolved organisation ids and
  refuses to run without them. An account below `ROLE_SUPER_ADMIN` without an
  organisation sees only itself in the user list but gets **403** on the
  export (deliberate: the export gate needs at least one organisation).
  A user export is still downloadable only by the user who started
  it (or a `ROLE_SUPER_ADMIN`); a different user now gets **403** (job owner
  check, see "Async job status" below) instead of 404.
- **Holding roles** (`ROLE_GROUP_CISO`, `ROLE_KONZERN_AUDITOR`) may *view*
  users of subsidiaries but never edit or delete them, even with the
  `user.edit` / `user.delete` permission. User administration by
  `ROLE_ADMIN` stays own organisation + subsidiaries, as in 3.15.3.

### Migration order with the 3.15.3 operator bootstrap

Both `Version20261016150000` (this release) and
`Version20261019120000_operator_bootstrap` (3.15.3) assign accounts without an
organisation on a single-organisation installation. They are safe in either
order and never assign twice:

- `Version20261016150000` only assigns when an instance operator
  (`ROLE_SUPER_ADMIN`) already exists, and never assigns an operator. Without
  an operator it leaves the assignment to the operator bootstrap, which first
  promotes the setup admin (keeping it instance-wide, without organisation)
  and then assigns the remaining accounts itself.
- If 3.15.3 is already installed, the operator bootstrap has run; this
  release's migration then only picks up accounts created without an
  organisation since then.

### Accounts without an organisation see no data (multi-organisation installs)

**Effect.** The tenant filter used to show an account without an organisation
the data of *every* organisation. It now shows such an account *no*
organisation's data — only a `ROLE_SUPER_ADMIN` without an organisation stays
instance-wide. Migration `Version20261016150000` assigns accounts without an
organisation to the sole organisation, but only when exactly one organisation
exists (and an operator exists — see "Migration order" above); with two or more
it leaves them untouched. Existing `ROLE_ADMIN`,
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

### Module switch now also guards `/api/...` and keeps pre-gate modules active

**Effect.** The module switch (`#[RequiresModule]`) now also applies to API
Platform: the operations of module-owned resources (business continuity plans
and exercises -> `bcm`, persons -> `persons`, suppliers -> `suppliers`,
training -> `training`, interested parties -> `interested_parties`, locations
-> `locations`, change requests -> `change_requests`, threat intelligence ->
`vulnerability_intel`) answer **403 JSON** (`module_not_active`) while the
module is off for the organisation. Integrations that still call such
endpoints with a module switched off will start failing after the upgrade.

**Existing `config/active_modules.yaml`.** The pages of `persons`,
`risk_appetite`, `risk_treatment_plans`, `portfolio_reports`,
`scheduled_reports`, `corrective_actions` and `interested_parties` were
reachable before they were gated. An instance file written by an older release
does not list them, so they are treated as active as long as the file carries
no `gating_baseline` marker. Nothing to do on upgrade. The marker is written
the next time a module selection is saved (Admin -> Modules, setup wizard,
industry preset); from then on a module missing from the file is really
inactive. New installs get the marker from `config/active_modules.yaml.dist`.
Organisations with their own module selection are not affected by this
fallback.

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
