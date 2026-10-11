---
name: role-scope-architecture-rollout
description: 7-Phase-Plan zur Vereinheitlichung von ROLE_ADMIN- und ROLE_SUPER_ADMIN-Scopes in einem Tag (11 PRs) abgeschlossen.
metadata: 
  node_type: memory
  type: project
  originSessionId: df58c764-60e6-489c-9ac8-c65766309351
---

Trigger war ein 403 auf `/de/admin/data/backup` fuer einen `ROLE_ADMIN`-Tenant-Admin.
Decision: `ROLE_ADMIN` administriert eigenen Tenant-Tree (own + descendants),
`ROLE_SUPER_ADMIN` administriert alle Tenants; Global-Operations (cross-tenant
import, schema repair, license, system health) bleiben SUPER-only. Persona-Roles
(`CISO`, `RISK_MANAGER`, `DPO`, `COMPLIANCE_MANAGER`, `GROUP_BCM_OFFICER`,
`FUNCTION_OWNER`) sind reine Module-Visibility-Filter, keine Scope-Erweiterung.
Der komplette 7-Phase-Plan wurde am 2026-05-18 in 11 PRs gemerged:
#458 (Voter foundation) + #462 (Hub-Filter) + #463 (Backup ref-impl) + #467 (SSO) +
#469 (Imports) + #471 (Per-tenant configs, 10 ctrl.) + #468 (Notifications) +
#473 (System-settings, 7 ctrl.) + #472 (BackupService tenant filter) +
#470 (Persona-visibility) + #465 (Gate 41 CI gate). Aggregate: ~30 Admin-Controller
migriert, +1,880 LoC Tests, ~-1,700 net source LoC (Duplicate
`resolveTenantScopeFor*()`-Snippets entfernt). Gate 41 blockt neue
Admin-Controller ohne class-level Voter-Attribut oder `ROLE_SUPER_ADMIN`.
Spec: `docs/superpowers/specs/2026-05-18-role-scope-architecture.md`.
Pattern fuer neue Admin-Controller: `#[IsGranted('ADMIN_OWN_TENANT')]` (own +
subtree) oder `ADMIN_GLOBAL_OP` (SUPER-only) oder `PERSONA_*` — via
`TenantScopedAdminVoter`. NIE wieder inline `resolveTenantScopeFor*()`.
