# Feature-Roadmap (Sprint 10+ — open items)

> ⚠️ **STALE (2026-06-04).** Code-verified check showed this open-items strip is
> out of date — F5b/F27/F28 listed here as future are DONE, and the whole
> EU-authority-reporting cluster (F25/F26/F29/F30/F36/F40) shipped beyond this
> plan. Authoritative current status:
> [`2026-06-04-feature-catalog-verified-status.md`](2026-06-04-feature-catalog-verified-status.md).

**Status (2026-05-25):** Stripped to the still-open items. Sprint 0
foundations, Sprint 1 (F2 W1 + F40), Sprint 5b (F1 SSO Wave 2), and
most Sprint 2-9 work are in main; their detailed plans were deleted
along with the v1-v6 evolution history. Remaining sprints (10-13) and
the long-tail Backlog (Sprint 14+) survive here.

## Konzept-Invarianten (must-not-break)

1. **Tenant-Isolation** via `tenant_id`. Keine Cross-Tenant-Operationen.
2. **Curated-Library** für Frameworks/Mappings. User authoren KEINE
   Frameworks frei — Scoping/Profile only.
3. **HMAC-SHA256 Audit-Chain** über sicherheitsrelevante Events.
4. **Module-Gating** über `config/modules.yaml`.
5. **Aurora v4** Pflicht-Vokabular für UI.
6. **Symfony 7.4 LTS** — kein 8.0-Bump ohne Auftrag.
7. **Single Audit-Entry-Point pro Feature** — neue Services
   (Bulk-Importer, SSO-Provisioning, API-Bulk) dürfen Doctrine-Lifecycle
   NICHT umgehen ohne expliziten `AuditLogger::log*()`-Call. Sonst
   bricht HMAC-Chain.
8. **No third-party-product names** in Code/Docs/CHANGELOG/UI-Strings.
   Standards (ISO/BSI/NIST/OIDC/OSCAL) OK.

## Still-open Sprints (10-13)

| Sprint | Lead | Parallel | Notes |
|---|---|---|---|
| **10** | **F5b Wave 1** (BSI Kompendium 2024 + TISAX v6.0 Library) | **F27 BSI-200-4-Übungs-Logbuch** | Library-only YAML + BCM-Erweiterung, parallel-safe |
| **11** | **F10 Profile/Maturity** (per-Framework) | **F28 TISAX-ISA-Workflow** | F28 konsumiert F10-Foundation |
| **12** | **F13 TIA** (Transfer-Impact-Assessment) | **F31 DPIA-National-Templates** + **F32 DPA-Generator** | Privacy-Vertical-Cluster |
| **13** | **F33 EU-AI-Act-Klassifizierung** | **F34 CRA-SBOM-Inventar** | Forward-Looking-EU-Compliance |

## Backlog (Sprint 14+, strategisch)

Reihenfolge nach Demand × Aufwand-Effizienz:

1. **F26 Wave 2** (DSB-AT + EDÖB-CH + BaFin-MVP-Behörden-Templates)
2. **F5b Wave 2** (Swiss-Stack: nDSG 2023 + ISG SR-128, CH-optional)
3. **F39 ENISA EUVD Daily-Feed-Connector**
4. **F12 OSCAL-Profile-Roundtrip + F5 NIST-Catalog-Importer**
5. **F38 Policy-Pack-Format-Adapter** (Document-Type-Extension)
6. **F6 REST-API Bulk-Endpoints + Webhook-Lifecycle**
7. **F7 Field-Level-RBAC** (Voter-Erweiterung)
8. **F8 Health-Check + Observability**
9. **F9 i18n FR/IT/ES/NL/PT-BR**
10. **F14 Audit-Findings-Inline-Capture + F17 Procedures-Authoring**
11. **F20 Multi-Format-Document-Export** (MD/DOCX/PDF/HTML)
12. **F23 Supplier-Questionnaire-Distribution**
13. **F21 MCP-Server + F22 Local-LLM** (datensicher via Ollama)
14. **F24 EBIOS-RM-Methodik** (FR-Markt-Erschluss)
15. **F18 No-Code-Framework-Builder-GUI** (mit Sandbox-Constraints)
16. **F37 One-Command-Setup-Hardening**
17. **F35 EUCS-Cloud-Audit-Workflow** (wenn Standard final)
18. **F-NEU-ICT-PROVIDER** ICT-Service-Provider Asset-Library +
    Multi-Tenant Distribution (nach F33+F34)

---

## Sprint 10 — F5b W1 + F27

### F5b — BSI / TISAX Library-Roundtrip (Wave 1)

- BSI Kompendium 2024 importer + library entry — YAML-only, no
  Framework-authoring UI
- TISAX v6.0 ISA-Katalog as canonical library
- Module-Gating: `bsi`, `tisax`
- Translation-Domain: `bsi` + `tisax` (existing)

### F27 — BSI 200-4 Übungs-Logbuch

- BCM-Erweiterung: Exercise-types per BSI 200-4 §10
- Logbuch-Entity reuses existing `BCExercise` + extension fields
- Translation-Domain: `bsi_200_4_exercise` (Sprint-0 skeleton present)

**Acceptance (Sprint 10):** BSI Kompendium 2024 importiert + TISAX v6.0
verfügbar; Library-Loader-Success.

## Sprint 11 — F10 + F28

### F10 — Per-Framework Scoping / Profile + Maturity

- `FrameworkProfile` entity (tenant + framework + maturity tier 1-5)
- Profile picker on framework activation
- Maturity-Heatmap dashboard tile
- Module-Gating: existing per-framework keys

### F28 — TISAX ISA-Workflow

- Self-Assessment workflow consuming F10 maturity-profile
- ISA-questionnaire CRUD via curated library
- Workflow-output → `ComplianceFulfillment` rows per question
- Translation-Domain: `tisax_isa` (Sprint-0 skeleton present)

**Acceptance (Sprint 11):** Per-Framework Maturity-Profil + TISAX-Self-
Assessment; TISAX-Maturity-Level berechnet.

## Sprint 12 — F13 + F31 + F32 (Privacy-Vertical)

### F13 — TIA (Transfer-Impact-Assessment)

- New entity `TransferImpactAssessment` (GDPR Art. 46 §3 + §49)
- Linked to `ProcessingActivity.thirdCountryTransfer`
- Risk-rated output → `Risk` row creation
- Module-Gating: `privacy`
- Translation-Domain: `tia` (Sprint-0 skeleton present)

### F31 — DPIA National Templates

- Sectoral DPIA templates: Healthcare § 22 BDSG, FinServ DORA, AI-Act
  Annex-III
- Library-pattern, no per-tenant authoring

### F32 — DPA-Generator

- Auftragsverarbeitungs-Vertrag template (GDPR Art. 28(3))
- Variable substitution from existing `ProcessingActivity`
- Output as `Document(type=dpa)`
- Translation-Domain: `dpa_template`

**Acceptance (Sprint 12):** TIA-Workflow + nationale DPIA-Templates;
TIA für Drittland generiert.

## Sprint 13 — F33 + F34 (Forward-Looking EU)

### F33 — EU-AI-Act-Klassifizierung

- EU AI Act Reg. 2024/1689 Annex-III Hochrisiko-KI-Klassifizierung
- New entity `AiSystemInventory` mit Annex-III-mapping
- Klassifizierung-Workflow inkl. Audit-Trail
- Module-Gating: `ai_act` (Sprint-0 skeleton present)
- Translation-Domain: `ai_act`

### F34 — CRA-SBOM-Inventar

- EU Cyber Resilience Act (Reg. 2024/2847) SBOM-Inventory
- New entity `SbomComponent` (per Asset)
- CycloneDX or SPDX import
- Module-Gating: `cra_sbom` (Sprint-0 skeleton present)
- Translation-Domain: `cra_sbom`

**Acceptance (Sprint 13):** EU-AI-Act Hochrisiko-KI-Klassifizierung +
SBOM-Import; Asset als KI-System Annex-III klassifiziert.

---

## Backlog feature blurbs (Sprint 14+)

### F-NEU-ICT-PROVIDER — Asset-Library + Multi-Tenant Distribution

Provider runs a shared SaaS for N regulated banks. Each bank must list
the provider as critical ICT-third-party in their DORA Register of
Information. Currently the only path is manual N× duplication.

**Design:**
- New `Tenant.tenantType` enum: regulated | ict_provider
- Provider-tenant = parent-tenant with `tenantType=ict_provider`
- Each client = child-tenant under provider (existing holding-tree
  structure)
- New entity `IctServiceLibraryAsset` — master-definitions at
  parent-tenant level
- Child-tenants get `linkedLibraryAssetId` field on Asset
- On master-update → emit AlvaHint "Linked-Library-Asset X changed,
  review your override" in each child-tenant
- DORA RoI export at child-tenant level pulls master-definition +
  child-override fields

**Differentiation from existing Holding pattern:**
- Holding = same legal entity with multiple business-units (group-CISO
  role)
- ICT-Provider = different legal entity providing services to N
  customers
- Both can coexist — child-tenant can be `ict_provider_client +
  holding_subsidiary`

**Effort estimate:** 1 migration + 2 services
(LibraryDistributionService, OverrideMergeService) + 1 AlvaHint +
1 admin-page + tests ≈ 2 weeks.

**DORA-compliance note:** Art. 28 explicitly allows redundant
reporting. Each client's RoI is its own contract — data-duplication
across tenants is correct, not a violation. Daten-Isolation between
tenants stays intact.

**When to ship:** After F33 + F34 in Sprint 13. Pull forward if a key
customer is an ICT-provider needing this for their first DORA report.

### Remaining backlog items

Detailed designs for F26 W2, F5b W2, F39, F12/F5, F38, F6, F7, F8, F9,
F14/F17, F20, F23, F21/F22, F24, F18, F37, F35 should be drafted as
individual sprint-specs when sprints are scheduled. The numbering is
preserved for cross-reference to historical commits and Sprint-0
translation-domain skeletons.

## Translation-Domains tracked in `config/modules.yaml`

Sprint-0 reserved 6 new module-keys: `notifications`,
`eu_authority_reporting`, `tisax_isa`, `ai_act`, `cra_sbom`,
`procedures`. Sprint 14+ may add `dpa_template`, `mcp_server`,
`ebios_rm`. `marisk` stays deprecated (DORA + RTS 2024/1773 supersede
the ICT part; non-ICT-Stack AT 4/5/9 remains for sector-spezial
compliance).
