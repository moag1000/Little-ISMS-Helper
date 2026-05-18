# Role-Scope Architecture — Spec & Rollout Plan

**Status:** Draft / planning
**Date:** 2026-05-18
**Trigger:** User reported `/de/admin/data/backup` returning 403 to a `ROLE_ADMIN` tenant-admin. Decision: `ROLE_ADMIN` administers their own tenant; `ROLE_SUPER_ADMIN` administers all tenants. Concept must apply systematically across all modules, including persona-roles.

## 0. The Contract

> **`ROLE_ADMIN`** administers their own tenant tree (own + descendants).
> **`ROLE_SUPER_ADMIN`** administers all tenants.
> **Global operations** (cross-tenant import, schema repair, global backup, license, system health) are `ROLE_SUPER_ADMIN`-only.
> **Holding-level roles** (`ROLE_GROUP_CISO`, `ROLE_KONZERN_AUDITOR`) get **read** across the holding tree.
> **Persona-roles** (`ROLE_CISO`, `ROLE_RISK_MANAGER`, `ROLE_DPO`, `ROLE_COMPLIANCE_MANAGER`, `ROLE_GROUP_BCM_OFFICER`, `ROLE_FUNCTION_OWNER`) are **module-visibility filters** on top of `ROLE_MANAGER` scope — they gate access to persona-specific dashboards and modules but do NOT widen tenant scope.

## 1. Current State Audit

### 1.1 `security.yaml` role hierarchy

```yaml
role_hierarchy:
    ROLE_AUDITOR:           ROLE_USER
    ROLE_MANAGER:           [ROLE_USER, ROLE_AUDITOR]
    ROLE_CISO:              ROLE_MANAGER
    ROLE_RISK_MANAGER:      ROLE_MANAGER
    ROLE_DPO:               ROLE_MANAGER
    ROLE_COMPLIANCE_MANAGER: ROLE_MANAGER
    ROLE_ADMIN:             [ROLE_USER, ROLE_AUDITOR, ROLE_MANAGER, ROLE_CISO, ROLE_RISK_MANAGER, ROLE_DPO, ROLE_COMPLIANCE_MANAGER]
    ROLE_SUPER_ADMIN:       [ROLE_ADMIN, ROLE_ALLOWED_TO_SWITCH, ROLE_GROUP_CISO, ROLE_KONZERN_AUDITOR]
```

Consequences:
- A `ROLE_ADMIN` user automatically holds every persona-role → sees every persona dashboard. Intentional.
- A persona-only user (e.g. `ROLE_RISK_MANAGER` without `ROLE_ADMIN`) has ONLY Manager-write scope + their own persona dashboard.
- A `ROLE_SUPER_ADMIN` user has both holding-roles → reads across all tenants AND administers them.

### 1.2 Enforcement audit — 26 admin controllers

| Pattern | Controllers | Notes |
|---|---|---|
| Clean tenant-scope-resolver inline | `AdminBackupController` | reference impl |
| Same logic duplicated 6× inline | `SsoProviderController` | worst offender |
| `#[IsGranted('ROLE_ADMIN')]` + silent `getCurrentTenant()` trust | 14 controllers | implicit, no auth check on cross-tenant attempt |
| `#[IsGranted('ROLE_SUPER_ADMIN')]` everywhere | 6 controllers | hides legitimate own-tenant ops from tenant-admins |
| Mix of method-level overrides | 4 controllers | confusing — already produced 1 user-visible 403 |

### 1.3 Hub-catalog visibility

`AdminHubCatalog::getGroups()` accepts `requiredModule` (feature-flag) but not `requiredRole`. Every `ROLE_ADMIN` user sees every card; clicks that lead to 403 walls feel like broken links. A `requiredRole` filter was briefly introduced (`d7090745`) but rolled back (`eeace793`). Mechanism is absent.

## 2. Role × Scope Matrix

Cell: `read` / `write` / `configure` / `admin` / `super` / `-`

| Role | Own tenant | Holding tree (descendants) | All tenants | Persona dashboard |
|---|---|---|---|---|
| `ROLE_USER` | read own records | - | - | - |
| `ROLE_AUDITOR` | read | - | - | - |
| `ROLE_MANAGER` | write | - | - | - |
| `ROLE_CISO` | write + ciso dashboard | - | - | **ciso** |
| `ROLE_RISK_MANAGER` | write + risk-manager dashboard | - | - | **risk_manager** |
| `ROLE_DPO` | write + dpo dashboard | - | - | **dpo** |
| `ROLE_COMPLIANCE_MANAGER` | write + compliance dashboard | - | - | **compliance_manager** |
| `ROLE_GROUP_BCM_OFFICER` | write (BCM scope) | write (BCM scope) | - | **bcm** (if module active) |
| `ROLE_FUNCTION_OWNER` | sign-off on policies covering own function | - | - | - |
| `ROLE_ADMIN` | admin | admin (descendants) | - | **all (inherits all personas)** |
| `ROLE_GROUP_CISO` | read (own) | read (descendants) | - | inherited via SUPER |
| `ROLE_KONZERN_AUDITOR` | read | read-only across descendants | - | inherited via SUPER |
| `ROLE_SUPER_ADMIN` | admin | admin | **super** (global ops) | all |

Notes:
- Persona-roles do NOT widen scope; they only gate persona dashboards / module visibility.
- A user can hold multiple persona-roles (e.g. CISO+DPO).
- `ROLE_GROUP_BCM_OFFICER` activated only when `bcm` module is on.

## 3. Per-Module Role-Permission Matrix

### 3.1 Admin modules (hub)

Cell: `R`=read, `W`=write own scope, `C`=configure own scope, `G`=global/cross-tenant, `-`=no access.

| Module | Route | USER | AUDITOR | MANAGER | ADMIN | SUPER | GROUP_CISO | KONZERN_AUDITOR |
|---|---|---|---|---|---|---|---|---|
| Backup | `data_backup_*` | - | - | - | R+W (own + subtree) | R+W+G | - | - |
| GstoolImport | `admin_gstool_import_*` | - | - | - | W own | W any | - | - |
| ComplianceImport | `admin_compliance_import_*` | - | - | - | W own | W global + any | - | - |
| SampleData | `admin_sample_data_*` | - | - | - | W own | W any | - | - |
| LoaderFixer | `admin_loader_fixer_*` | - | - | - | W own | G (schema) | - | - |
| AuditRetention | `app_admin_audit_retention` | - | - | - | C own | C any | - | - |
| KpiThreshold | `admin_kpi_threshold_*` | - | - | - | C own | C any | - | - |
| SsoProvider | `admin_sso_*` | - | - | - | C own | C any + global IDP | - | - |
| SsoWizard | `admin_sso_wizard_*` | - | - | - | W own | W any | - | - |
| LifecycleOverrides | `admin_lifecycle_overrides_*` | - | - | - | C own | C global YAML + any | - | - |
| MappingQuality | `admin_mapping_quality_*` | - | R own | R own | R+W own | R+W global | R holding | R holding |
| RiskApprovalConfig | `app_admin_risk_approval_config` | - | - | - | C own | C any | - | - |
| IncidentSlaConfig | `app_admin_incident_sla_config` | - | - | - | C own | C any | - | - |
| SupplierCriticality | `app_admin_supplier_criticality_*` | - | - | R | C own | C any | R holding | R holding |
| IndustryBaseline | `admin_industry_baselines_*` | - | - | - | R global, C own overlay | C global | - | - |
| IndustryPreset | `app_admin_industry_preset_*` | - | - | - | C own | C global | - | - |
| NotificationRule | `admin_notification_rule_*` | - | - | C own | C own | C any | - | - |
| NotificationTemplate | `admin_notification_template_*` | - | - | C own | C own | C any + global | - | - |
| NotificationChannel | `admin_notification_channel_*` | - | - | C own | C own | C any + global | - | - |
| DataRepair | `admin_data_repair_*` | - | - | - | W own orphans/dupes | G (schema) | - | - |
| TenantComplianceSettings | `admin_tenant_compliance_settings_current` | - | - | - | C own | C any | - | - |
| WorkflowDefinitions | `app_workflow_definitions` | - | R | R | R (YAML read-only) | R | - | - |
| WorkflowOverlay | `admin_workflow_overlay_*` | - | - | - | C own overlay | C any | - | - |
| Library importer | `admin_library_*` | - | - | - | R+W own | W global | - | - |
| Tags | `admin_tag_*` | - | - | TAG_APPLY | C own | C any | - | - |
| TourContent | `admin_tour_content_*` | - | - | - | - | C global | - | - |
| TourCompletion | `admin_tour_completion_*` | - | - | - | R own stats | R any | R holding | R holding |
| Modules (activation) | `admin_modules_*` | - | - | MODULE_VIEW | C own | C any + global catalog | - | - |
| MFA admin | `admin_mfa_*` | - | - | - | C own users | C any | - | - |
| AuditLogMonitoring | `monitoring_audit_log` | - | R own | R own | R own | R any | R holding | R holding |
| System settings | `admin_settings_*` | - | - | - | C own (where scoped) | C global | - | - |
| User management | `user_management_*` | - | - | - | W own users | W any + role edit | R holding | - |
| Tenant management | `tenant_management_*` | - | - | - | R+W own+subtree | W any | R holding | R holding |
| Licensing | `admin_licensing_*` | - | - | - | R own | C global | - | - |
| API doc | `api_doc` | - | - | - | R | R | R | R |
| Setup wizard | `setup_wizard_*` | - | - | - | - | W global (first-run) | - | - |

### 3.2 Persona dashboards (module-visibility, no scope expansion)

| Persona dashboard | Route | CISO | RISK_MGR | DPO | COMPLIANCE_MGR | BCM | FUNCTION_OWNER |
|---|---|---|---|---|---|---|---|
| Dashboard CISO | `dashboards_ciso` | **D** | - | - | - | - | - |
| Dashboard Risk Manager | `dashboards_risk_manager` | - | **D** | - | - | - | - |
| Dashboard DPO | `dashboards_dpo` | - | - | **D** | - | - | - |
| Dashboard Compliance Manager | `dashboards_compliance_manager` | - | - | - | **D** | - | - |
| Dashboard BCM | `dashboards_bcm` | - | - | - | - | **D** | - |
| Function-Owner Sign-off | `policy_acknowledgement_*` | - | - | - | - | - | **D** |

Persona-role users see ONLY their dashboard. `ROLE_ADMIN` sees all (via role-inheritance).

## 4. Generic Voter / Helper Design

### 4.1 New `TenantScopedAdminVoter`

`src/Security/Voter/TenantScopedAdminVoter.php`:

```php
final class TenantScopedAdminVoter extends Voter
{
    public const string ADMIN_OWN_TENANT   = 'ADMIN_OWN_TENANT';
    public const string ADMIN_ANY_TENANT   = 'ADMIN_ANY_TENANT';
    public const string ADMIN_GLOBAL_OP    = 'ADMIN_GLOBAL_OP';
    public const string ADMIN_HOLDING_READ = 'ADMIN_HOLDING_READ';

    public const string PERSONA_CISO       = 'PERSONA_CISO';
    public const string PERSONA_RISK       = 'PERSONA_RISK';
    public const string PERSONA_DPO        = 'PERSONA_DPO';
    public const string PERSONA_COMPLIANCE = 'PERSONA_COMPLIANCE';
}
```

Resolution:
- `ADMIN_OWN_TENANT` → SUPER passes; else `ROLE_ADMIN` + `TenantContext::canAccessTenant($subject)`
- `ADMIN_ANY_TENANT` / `ADMIN_GLOBAL_OP` → `ROLE_SUPER_ADMIN` only
- `ADMIN_HOLDING_READ` → SUPER passes; else `ROLE_GROUP_CISO`/`ROLE_KONZERN_AUDITOR` + canAccessTenant
- `PERSONA_*` → corresponding `ROLE_*` granted

### 4.2 `TenantContext::resolveAdminScope()`

```php
public function resolveAdminScope(mixed $requested): ?Tenant
```

- `null`/`''`/`'global'` → SUPER returns `null`; ROLE_ADMIN falls back to own tenant
- specific tenant_id → SUPER any, ROLE_ADMIN only if `canAccessTenant()`
- throws `AccessDeniedException` on cross-tenant attempt

Consolidates inline duplications in `AdminBackupController` and `SsoProviderController`.

### 4.3 Hub-catalog `requiredAttribute` filter

```php
'requiredAttribute' => 'ADMIN_GLOBAL_OP'    // or 'ADMIN_OWN_TENANT', 'PERSONA_DPO', …
'requiredModule'    => 'nis2_dora'           // existing
```

`HubController::index()`:
```php
$group['modules'] = array_values(array_filter($group['modules'], fn(array $m): bool =>
    (empty($m['requiredAttribute']) || $this->isGranted($m['requiredAttribute']))
    && (empty($m['requiredModule']) || $this->moduleConfiguration->isActive($m['requiredModule']))
));
```

Drop empty groups so hub header doesn't render zero-card sections.

## 5. Implementation Phases

7 PRs. Phase 4 internally parallelizable (1 PR per cluster).

### Phase 1 — Voter foundation + tests
- New `TenantScopedAdminVoter` (4 admin-scope + 4 persona attributes)
- Extend `TenantContext::resolveAdminScope()`, `canAdminister()`
- ~30 voter tests (11 roles × 3 subject shapes)
- ~250 LoC source + 400 test. **1 PR.**

### Phase 2 — Hub-catalog visibility filter
- Re-introduce `requiredAttribute`/`requiredRole` filters in `HubController`
- Annotate every `AdminHubCatalog` module per Section 3.1
- Drop empty groups
- ~80 LoC source + 200 test. **1 PR.**

### Phase 3 — Backup controller reference impl
- Replace inline `resolveTenantScopeFor*()` with `TenantContext::resolveAdminScope()`
- Swap method-level `ROLE_SUPER_ADMIN` → `ADMIN_OWN_TENANT` / `ADMIN_GLOBAL_OP`
- ~-50/+30 LoC + 80 test. **1 PR.**

### Phase 4 — Apply pattern to remaining controllers (5 sub-PRs)
- **4a** SSO cluster (SsoProvider + SsoWizard) — biggest LoC win
- **4b** Imports (Gstool + ComplianceImport + Library + SampleData)
- **4c** Per-tenant configs (TenantComplianceSettings, RiskApprovalConfig, IncidentSlaConfig, KpiThreshold, SupplierCriticality, AuditRetention, WorkflowOverlay, LifecycleOverrides, IndustryPreset, MappingQuality)
- **4d** Notifications (Rule, Template, Channel)
- **4e** System settings (6 controllers)

Net ~-2,600 source, +600 test. **5 PRs.**

### Phase 5 — Service-layer tenant filtering
- `BackupService::listBackups(?Tenant $scope)` — filter via embedded `scope_tenant_id` (filename pattern OR metadata read)
- `RestoreService::validateBackup()` rejects cross-tenant restore by ROLE_ADMIN
- Opportunistic file rename for legacy `backup_*` filenames
- ~200 LoC + 150 test. **1 PR.**

### Phase 6 — Persona-role / module-visibility sweep
- Annotate persona-specific modules with `requiredAttribute: PERSONA_*`
- Verify role-hierarchy still inherits (admins see all personas)
- Update mega-menu persona links
- Tests for persona-only users (each must see exactly one dashboard)
- ~50 LoC + 200 test. **1 PR.**

### Phase 7 — CI gate + integration test
- `scripts/quality/check_admin_role_scope.py` — every Admin-controller declares class-level voter attribute or class-level `ROLE_SUPER_ADMIN`
- Integration test: enumerate `AdminHubCatalog`, login as fresh ROLE_ADMIN, hit every route; expect 200/302 for `ADMIN_OWN_TENANT`, 403 for `ADMIN_GLOBAL_OP`
- Add to `.github/workflows/ci.yml`
- ~150 LoC checker + 250 test. **1 PR.**

## 6. Open Questions / Risks

1. **Tenant-admin viewing subsidiaries' backups** — `getAccessibleTenants()` returns own+descendants. Decision: keep, filter `listBackups` accordingly.
2. **Global-only ops** — Cross-tenant migrate, license, schema-reconcile, setup wizard, tour content, global settings stay `ROLE_SUPER_ADMIN`.
3. **Persona × ROLE_ADMIN inheritance** — confirmed working. No change.
4. **Persona × Hub modules** — should `ROLE_COMPLIANCE_MANAGER`-only see `admin_compliance_policy_index`? Probably yes — explicit `PERSONA_COMPLIANCE` attribute. Defer detail audit to Phase 6.
5. **Test-fixture impact** — need 11 role-permutation fixtures. Reuse Holding-Tenant test topology. DAMA bundle handles isolation.
6. **BC-break visibility** — Phase 2 filter visibly hides ~10 cards from non-SUPER admins. Add release note.
7. **File-rename risk Phase 5** — opt-in lazy rename, both names readable for ≥1 release.

## 7. Estimated Effort

| Phase | Title | PRs | Net LoC src | LoC test | Risk |
|---|---|---|---|---|---|
| 1 | Voter foundation | 1 | +250 | +400 | low |
| 2 | Hub-catalog visibility | 1 | +80 | +200 | low (UX-visible) |
| 3 | Backup ref-impl | 1 | -50 | +80 | low |
| 4 | Admin-controller sweep | 5 | -2,600 | +600 | medium (mechanical) |
| 5 | Service-layer tenant filter | 1 | +200 | +150 | medium (filesystem) |
| 6 | Persona-role sweep | 1 | +50 | +200 | low |
| 7 | CI gate + integration test | 1 | +400 | +250 | low |
| **Total** | | **11 PRs** | **~-1,670 net** | **+1,880** | |

Calendar: ~2-3 weeks at 1 PR/day. Phase 4 sub-PRs parallelizable across agents.

## 8. Critical Files

- `src/Service/TenantContext.php` — extend
- `src/Security/Voter/TenantScopedAdminVoter.php` — new
- `src/Controller/Admin/HubController.php` — filter logic
- `src/Service/Admin/AdminHubCatalog.php` — annotations
- `src/Controller/AdminBackupController.php` — Phase 3 reference
- `src/Service/BackupService.php` — Phase 5 tenant filter
- `config/packages/security.yaml` — unchanged

## 9. Glossary

- **Own tenant** = `User::$tenant`
- **Own subtree** = own tenant + `getAllSubsidiaries()` (Holding-parent admins administer descendants)
- **Holding tree** = same as own subtree, but read-only for `ROLE_GROUP_CISO`/`ROLE_KONZERN_AUDITOR`
- **All tenants** = no scope filter; only `ROLE_SUPER_ADMIN`
- **Global operation** = cross-tenant migrate, schema, license, system settings, tour content
- **Persona dashboard** = `/dashboards/<persona>` route, gated by matching `ROLE_*`

---

*End of spec.*
