---
name: feedback-tenant-organisation-term
description: "B1 decision: system=Tenant (Code/Routes/DB), UI=Organisation/Organization. Code-Klassen Tenant-prefixed OK, EN-Spelling = US-ISO 'Organization'."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: f94a1c13-01b9-4ace-8321-f7d76cfa1b21
---

System-Begriff darf `Tenant` heißen — Code, Routes (`/admin/tenants/*`), DB-Spalten (`tenant_id`), Doctrine-Entities. UI-Labels für End-User durchgängig "Organisation" (DE) / "Organization" (EN, US-ISO 27001:2022 Schreibweise). Mandant/Mandanten als reine User-Labels banned.

**Ausnahmen (System-Kontext bleibt):**
- Azure-Produktnamen (`Azure Tenant ID`)
- BSI-C5 / ISO-27017 cloud-tenancy Tech-Begriffe (`Mandantenfähigkeit`, `Mandantentrennung`, `Tenant-Isolation`, `Cross-Tenant`)
- PHP-Klassennamen in Admin-Debug-Labels (`Tenant.settings`, `TenantPolicySetting`, `TenantBranding`)
- Twig-Variablen `{{ tenant.X }}` in Policy-Templates

**FormType-Konvention:**
- `TenantType`, `TenantComplianceSettingsType` etc. → System-Layer, Tenant-prefix OK
- `OrganisationInfoType`, `OrganisationScopeStep` → ISO Cl. 4.1 "context of the organization" Surface, behalten

**Why:** User-Entscheidung 2026-05-27 nach Audit-Vorlage. Vermeidet Route-Bruch (`/admin/tenants/*` bleibt, keine Bookmark-Brüche), DB-Migration (keine Spalten-Renames), und ISO-Konformität in UI (Cl. 4.1 "the organization"). Trade-off: dev muss System-vs-UI Mental-Split halten.

**How to apply:**
- Neue Translations: UI-Werte immer "Organisation"/"Organization", nie "Tenant"/"Mandant"
- Neue Klassen: System-Layer = Tenant-Prefix, UI-Wizard-Steps = Organisation-Prefix wenn ISO 4.1 Kontext
- Neue Routes: bevorzugt `/admin/tenants/*` weiterbenutzen — neue Routes für UI-Konzepte können `organisation` heißen wenn semantisch distinkt
- ADR-Quelle: `docs/decisions/2026-05-27-tenant-organization-terminology.md`

Closed B1 item from [[project-backlog-2026-05-25]].
