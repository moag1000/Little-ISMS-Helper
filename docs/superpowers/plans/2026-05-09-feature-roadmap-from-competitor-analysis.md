# Feature-Roadmap aus Wettbewerbs-Analyse — v2 (2026-05-09)

## Versions-Historie

- **v1** (2026-05-09): Initial-Plan basierend auf Wettbewerbs-Issues-Analyse
- **v2** (2026-05-09): Eingearbeitet UX + Compliance-Manager (Effizienz) +
  ISMS-Specialist Reviews. Strukturelle Änderungen unten markiert mit
  "[v2-CHANGE]".

## Zweck

Konsolidiert die häufigsten Feature-Requests aus offenen GRC-Open-Source-
Projekten zu einer priorisierten Roadmap, die unser Konzept (Multi-Tenant,
Module-Gating, kuratierte Library, tamper-evident Audit-Chain) **nicht**
bricht. Norm-konform für ISO 27001:2022 + BSI 200-x + EU DORA + EU NIS-2.

## Konzept-Invarianten (must-not-break)

1. **Tenant-Isolation** via `tenant_id`. Keine Cross-Tenant-Operationen.
2. **Curated-Library** für Frameworks/Mappings. User authoren KEINE
   Frameworks frei — Scoping/Profile only.
3. **HMAC-SHA256 Audit-Chain** über sicherheitsrelevante Events.
4. **Module-Gating** über `config/modules.yaml` (20 Keys nach T31).
5. **Aurora v4** Pflicht-Vokabular für UI.
6. **Symfony 7.4 LTS** — kein 8.0-Bump ohne Auftrag.
7. **[v2-NEW] Single Audit-Entry-Point pro Feature** — neue Services
   (Bulk-Importer, SSO-Provisioning, API-Bulk) dürfen Doctrine-Lifecycle
   NICHT umgehen ohne expliziten `AuditLogger::log*()`-Call. Sonst
   bricht HMAC-Chain.
8. **[v2-NEW] No Competitor-Names** in Code/Docs/CHANGELOG/UI-Strings.
   Standards (ISO/BSI/NIST/OIDC/OSCAL) OK.

## Übersicht der Features

| # | Feature | Prio | Sprints | [v2] Geändert |
|---|---|---|---|---|
| F1 | OIDC SSO + LDAP | P1 | 1-2 | Wizard+Presets, 6 Audit-Events, RoleMapping-Entity |
| F2 | CSV/XLSX Bulk-Import | P1 | 1-2 | + Delta-Mode, hybrid Audit-Pattern, Source-File-Retention |
| F4 | Evidence-Versioning + Reuse | P2 | 3 | + Cross-Framework Re-Verify-Cascade, DocumentVersion immutable |
| F11 | FTE-Tracking-Dashboard | P2 | 3-4 | **[v2-NEW]** ROI-Counter für CISO/GF |
| F3 | Notification-Rules + Webhooks | P2 | 4-5 | + SLA-Timer-Events, In-App-Center, Email-Digest, KEIN native Slack/Teams |
| F5b | BSI/TISAX-Roundtrip | P2 | 5 | **[v2-CHANGE]** ersetzt OSCAL |
| F10 | Scoping/Profile + Maturity | P2 | 5-6 | **[v2-CHANGE]** vorgezogen, per-Framework |
| F5 | OSCAL-Importer (NIST) | P3 | 7+ | **[v2-CHANGE]** zurückgestuft |
| F7 | Granulare RBAC | P3 | 8+ | unverändert |
| F6 | REST-API Bulk + Webhooks | P3 | 8+ | + Single-Audit-Entry |
| F8 | Health-Check + Observability | P3 | 9+ | + Tenant-Disclosure-Constraints |
| F9 | i18n FR/IT/ES/NL/PT-BR | P3 | 10+ | unverändert |

---

## P1 — Quick Wins

### F1. OIDC / OAuth2 SSO + LDAP (Multi-Tenant)

**Demand:** sehr hoch. Top-Issue bei jedem Wettbewerber.

**[v2] Norm-Mapping erweitert (alle 3 Reviewer):**
- **ISO 27001 A.5.15** — `Tenant.ssoEnforced` ist Policy-Enforcement-Point
- **A.5.16** Identity-Mgmt — JIT-Provisioning + IdP-Trust-Lifecycle
- **A.5.17** Auth-Information — SSO-Secrets via existing `SsoSecretEncryption`
- **A.5.18** Access-Rights — Claim-to-Role-Mapping
- **A.8.5** Secure-Auth — MFA-Inheritance vom IdP, JIT darf MFA nicht umgehen
- **DORA Art. 9(4)(c)** strong-auth für ICT-Asset-Zugriff
- **NIS-2 Art. 21(2)(j)** MFA + sichere Auth
- **27701 A.7.2.6** sub-processor-staff (bei zentral verwaltetem IdP)

**[v2-NEW] Pflicht-HMAC-Chain-Events (6 Events):**
1. `sso.login.success` (user, idp_id, claims_hash, source_ip)
2. `sso.login.failure` (idp_id, reason)
3. `sso.jit.user_provisioned` (idp_id, user_id, role_assigned, claim_hash)
4. `sso.role_change_via_claims` (user, old_role, new_role) — **kritisch**,
   Role-Drift via Re-Login ist bekanntes Audit-Finding-Pattern
5. `sso.idp_config.{created,updated,deleted}` (admin, before_after_hash)
6. `sso.tenant.sso_enforced_toggle` (admin, before, after)

**Bestehend:**
- `IdentityProvider` Entity (`src/Entity/IdentityProvider.php`) mit
  `discoveryUrl`, `Url(requireTld:false)` ✓
- `OidcAuthenticationFlow`, `OidcDiscoveryService`, `SsoProviderRegistry`,
  `SsoSecretEncryption` Services skelettiert (Coverage 0–33%, Tests fehlen)
- 20-Field-Megaform `IdentityProviderType` + `templates/admin/sso/form.html.twig`

**[v2] Neue Entities:**
- `IdentityProviderRoleMapping` — claim → ROLE_X mit Audit-Trail. **Pflicht**
  damit Auditor "wie wird verhindert dass IdP-Group X niemals ROLE_ADMIN
  bekommt?" beantwortet werden kann.
- `IdentityProviderUserMapping` — IdP-User-ID → ALVA-User
- `Tenant.ssoEnforced` (boolean Flag — Tenant-Policy)

**[v2-CHANGE] UI: 3-Step-Wizard (statt Mega-Form):**
- Step 1 = **Preset-Picker**: Microsoft 365/Entra ID, Google Workspace,
  Keycloak, Okta, Auth0, Generic OIDC. Tile-Layout via `_fa_feature_card`,
  `role="radiogroup"`. Pre-fills issuer-template, scopes, attribute-map.
- Step 2 = **Discovery + Secret**: nur 2 Felder sichtbar. Auto-Validation
  on-blur via `OidcDiscoveryService`. Endpoints kollabiert in
  "Advanced — auto-filled" Disclosure.
- Step 3 = **Test-Connection vor Save** (chicken/egg-Fix).
- Top of form: **Copy-Button mit prefilled Callback-URL** —
  `https://app/{tenant-slug}/sso/callback/{idp-slug}` — Top-3-Failure-Mode
  "wrong redirect URI" wird damit eliminiert.
- Group-Mapping: NICHT als JSON-Textfield. Key/Value-Collection-Type mit
  Preset-Defaults pro IdP-Provider.

**[v2-NEW] Aurora-Macros:**
- Existierend: `_fa_page_header`, `_fa_section`, `_fa_feature_card`, `_fa_empty_state`
- **NEU `_fa_stepper`** — 3-Step-Wizard-Chrome (geteilt mit F2, F5, F5b)

**Aufwand:** 2 Sprints
- W1: Wiring + 3-Step-Wizard + IdP-Form-Refactor + JIT-Provisioning
- W2: Tests + Audit-Trail (6 Events) + Multi-Tenant-Routing + RoleMapping-Entity

**Effizienz (CM-FTE-Schätzung):** 50-User-Tenant: ~150 JML-Events/Jahr.
Manuell 20 min → ~50 FTE-Tage/Jahr. Mit JIT-SSO ~5 FTE-Tage. **Netto
~45 FTE-Tage/Jahr gespart**, plus Orphan-Account-Reduktion = Audit-Finding-
Vermeidung.

**[v2-CHANGE] Decision: OIDC zuerst, LDAP Sprint 2.** Entra ID dominiert
DACH-Mittelstand 2026, AD-DS pur stirbt aus.

**Module-Gating:** `authentication` (existiert).
**Translation-Domain:** **`sso`** (NEU — vor Implementation anlegen)

**[v2] Sprint-1 Acceptance:** Demo-Tenant mit Entra-ID-Preset → User loggt
erstmalig ein → JIT-Provisioning legt User+Role an → Audit-Trail zeigt
3 Events mit HMAC-Hash → MFA-Status sichtbar.
**Metrik: Time-to-First-Login < 90 Sek ab IdP-Config-Save.**

---

### F2. CSV/XLSX Bulk-Import/Export (Delta-Mode pflicht)

**Demand:** sehr hoch.

**[v2-CHANGE] Entity-Reihenfolge nach FTE-Impact:**
1. **Asset** (Volumen 200-2000) — Wave 1 zwingend
2. **Supplier** (DORA-Drittdienstleister-Register) — Wave 1
3. **Control / ControlImplementation** (SoA-Excel-Migration) — Wave 1
4. **Risk** (Risikoregister) — Wave 2
5. **BusinessProcess** — Wave 2
6. **Person** — niedrige Prio, kommt eh über F1-SSO

**[v2-NEW] Delta-Import-Mode (PFLICHT, nicht Nice-to-Have):**
- Use-Case: Excel kommt jährlich vom Asset-Owner — Diff-View essentiell.
- Bei slug/external-id-Match → side-by-side old→new. Selektives Apply.
- Ohne Delta-Mode wird Bulk-Import zur One-Shot-Migration, verstaubt
  nach Onboarding.

**[v2-NEW] Hybrid-Audit-Pattern (ISMS-Pflicht):**
- **EINE batch-entry** in HMAC-Chain: `bulk_import.executed`
  `{user_id, source_file_hash, file_name, entity_type, row_count,
    dry_run_result_hash, batch_id}`
- **PER-Entity-Entries** für jede created/updated Entity, referenziert
  `batch_id`. Ohne diese: "Show me Asset X history" funktioniert nicht.
- Source-File-Retention: Original CSV/XLSX als `Document(type=import_evidence)`,
  linked an batch_id (ISO 27001 Clause 7.5.3 Records-Control).

**[v2-NEW] Audit-Chain-Risiko:** Doctrine `executeStatement()` umgeht
Lifecycle-Events → AuditLogger feuert nicht. **Mandate: Bulk geht durch
Entity-Persist (langsamer, auditierbar) ODER expliziter
`AuditLogger::logBulk()`-Call nach Raw-SQL.**

**[v2] UI-Flow mit Drop-Off-Mitigations:**
1. **Upload** — Drag-Drop, .xlsx + .csv. SheetJS Client-Preview erste
   5 Zeilen. Auto-Detect Delimiter, Encoding, Header-Row.
2. **Auto-Detect Column-Mapping** via Header-Heuristik — "Asset Name"/
   "Bezeichnung"/"name" → `Asset.name` mit Confidence-Score. User
   confirms statt authors. **Cuts Drop-Off >50%.**
3. **Preview** — erste 20 Zeilen mit Badges: green=ok, yellow=warning,
   red=blocking. Inline-editable für rote Zeilen.
4. **Diff-View** für Re-Imports (Delta-Mode).
5. **Commit** — Async via Symfony Messenger (Bulk 5000 Assets darf UI
   nicht blocken). Progress-Bar, Skip-on-Error-Toggle, Error-CSV-Download.

**[v2-NEW] Aurora-Macros:**
- Existierend: `_fa_page_header`, `_fa_section`, `_fa_feature_card`, `_fa_empty_state`
- **NEU `_fa_diff_row`** — Old→New Cell-Diff (geteilt mit F5/F5b)
- **NEU `_fa_stepper`** — geteilt mit F1

**Bestehend:** `data_import` + `data_export` Module + Routes existieren.
Kern-Entity-Mapper unvollständig.

**Aufwand:** 1 Sprint pro Entity-Set (Wave 1: Asset+Supplier+Control,
Wave 2: Risk+BusinessProcess).

**Effizienz (CM-FTE):** Neuer Tenant 200 Assets + 80 Risks + 150 Controls
manuell ~12 FTE-Tage. Mit Bulk ~1.5 FTE-Tage. **~10.5 FTE-Tage/Tenant gespart.**

**XLSX vs CSV:** **Beide.** XLSX zwingend (GRC-Welt liefert nichts in CSV).
Dependency: `phpoffice/phpspreadsheet`.

**Module-Gating:** kein neues — pro Entity bereits gated.
**Translation-Domain:** Reuse existing `data_import`, `data_export`.

**[v2] Sprint-1 Acceptance:** 200 Assets via Excel hochladen → Mapping
vorausgefüllt → Validation 195 OK / 3 Warnings / 2 Errors → Commit →
Audit-Trail mit Bulk-Reference-ID → Re-Upload mit 5 Änderungen → Delta-View
"5 Updates, 0 New, 0 Deletes".
**Metrik: 200 Assets < 5 Min, Delta-Re-Upload < 2 Min.**

---

## P2 — Mittlere Prio (Sprints 3-5)

### F4. Evidence-Versioning + Reuse + Cross-Framework-Cascade

**Demand:** hoch.

**[v2-NEW] Cross-Framework-Re-Verify-Cascade (Compliance-Manager-Pflicht):**
- Beim `DocumentVersion`-Inkrement: alle linked `ControlImplementation`
  + `ComplianceFulfillment` bekommen `evidenceOutdated: true` Flag.
- `verificationResult` wird NICHT auto-resettet (würde Audit-Historie
  zerstören). Stattdessen: neue Task `re_verification_required` im
  Reviewer-Queue.
- Reviewer-Queue zeigt: "12 Controls in 3 Frameworks brauchen Re-Verify
  nach Backup-Policy-Update v2.1."

**[v2-NEW] DocumentVersion-Immutability:**
- Nach Publish nicht mehr änderbar (ISO 27001 A.5.33 Records-Protection).
- Aktuelles `Document.version`-Field ist mutable → muss auf neue
  `DocumentVersion`-Entity wandern.
- Soft-Delete + Retention-Policy-Field (Clause 7.5.3).

**[v2-NEW] HMAC-Chain-Events:**
- `document.version.created` (doc_id, old_hash, new_hash, uploader)
- `document.version.evidence_invalidated`
  (impacted_control_implementations, requires_reverification: count)

**[v2] UI:**
- Auto-Archive on Re-Upload mit **5s-Undo-Toast** statt expliziter
  "+New-Version"-Button (User würden sonst via Delete+Re-Upload Chain brechen).
- Hash-identical → Silent-Refuse: "Identical to v3 — no new version."
- Reuse-Display subtle: Badge `12 controls · 4 frameworks` (mono-font,
  muted), Click → Drawer mit Breakdown. Max 2-Klick-Tiefe.
- Dashboard-Tile: "Avg evidence reuse: 3.4x" via `_fa_feature_card`.

**Bestehend:** `Document` Entity hat `version` Field + M2M zu Control/
ControlImplementation/ComplianceFulfillment/Asset. **Fehlt:**
contentHash (SHA-256), DocumentVersion-Entity, Reuse-Stats.

**Entities:**
- `DocumentVersion` (NEU — versioned Snapshot, immutable, hash, uploadedBy,
  replacedBy)
- `Document.contentHash` (NEU Field)
- `ControlImplementation.evidenceOutdated` (NEU Flag)
- `ComplianceFulfillment.evidenceOutdated` (NEU Flag)

**Services:** `EvidenceVersioningService`, `DocumentReuseAnalyticsService`,
`EvidenceCascadeInvalidationService` (NEU — sammelt impacted controls bei
Version-Inkrement).

**Aufwand:** 1 Sprint.

**Effizienz (CM-FTE):** Aktuell ~20 FTE-Tage/Jahr Evidence-Pflege bei
27001+NIS2+DORA. Mit Reuse + Cascade ~6 FTE-Tage. **~14 FTE-Tage/Jahr gespart.**

**Reuse-Faktor-Target:** **≥4** (CM-Update). Plan-v1 sagte ">3" — zu
konservativ.

**Translation-Domain:** existing `document`.

---

### F11. FTE-Tracking-Dashboard [v2-NEW]

**Demand:** intern (CM-Tool-ROI gegenüber CISO/GF).

**Scope:** Live-Counter "seit 27001+NIS2-Onboarding wurden durch Evidence-
Reuse + Bulk-Import + SSO X FTE-Tage gespart". Monatlicher Report ans Board.
Cross-Framework-Gap-Heatmap-View (Tabelle: 3 Spalten 27001/NIS2/DORA, 1 Zeile
pro Anforderungs-Cluster, Ampel-Status).

**Datenquellen:**
- F2: Bulk-Import-Events × Avg-FTE-pro-Manual-Insert
- F4: Document-Reuse-Faktor × Avg-FTE-pro-Single-Framework-Pflege
- F1: SSO-JIT-Events × Avg-FTE-pro-Manual-Provisioning

**Entities:** `FteTrackingMetric` (tenant_id, source, savings_days, period_start, period_end)

**Services:** `FteCalculationService`, `BoardReportGenerator`.

**Aufwand:** 1 Sprint.

**Module-Gating:** `analytics`.
**Translation-Domain:** existing `analytics` + `kpi`.

---

### F3. Notification-Rulesets + Webhooks

**Demand:** sehr hoch.

**[v2-CHANGE] KEIN Native-Slack/Teams.** Webhook + HMAC reicht. Native =
Maintenance-Schuld. Stattdessen:
1. **Email-Channel rock-solid mit Digest-Mode** (nicht 50 Mails bei Bulk-Event)
2. **Webhook-Channel mit HMAC-Signing** (SSRF-Mitigation: `NoInternalIp`-Validator)
3. **In-App-Notification-Center** (Glocke + Badge) — **[v2-NEW]**, fehlte komplett

**[v2-NEW] SLA-Deadline-Events (ISMS-Pflicht):**
- `sla.deadline.approaching` (entity, deadline_type, hours_remaining: 24/12/4/1)
- `sla.deadline.missed` (entity, deadline_type, missed_by_hours)
- Entity-Types:
  - `DataBreach` — GDPR Art. 33: 72h
  - `Incident` — DORA Art. 19 (RTS 2024/1773): 4h initial / 24h interim / 1mo final
  - `Incident` — NIS-2 Art. 23: 24h early-warning / 72h notification / 1mo final
  - `WorkflowInstance.step` — custom SLA aus Regulatory-Workflow-Definitionen
- **Integration mit `WorkflowAutoProgressionService`** + `app:process-timed-workflows` —
  KEIN Parallel-Build.

**[v2-CHANGE] Tier-Approach UI:**
1. **Tier 1: Templates** — Pre-Built One-Click-Rules. "Notify CISO via Webhook
   on DataBreach severity≥high" deckt 80% Use-Cases. Hide Rule-Builder.
2. **Tier 2: Visual Rule-Builder** — 3 Sektionen: WHEN (event-type),
   CONDITIONS (chip-row: field/operator/value via `_fa_filter_chip`),
   CHANNEL+TARGET. NIE Raw-JSON dem User zeigen.
3. **Tier 3: Advanced** — Raw-JSON readonly für Audit/Copy. Editable nur
   mit explicit Toggle + Warning.

**Channel-Config separat:** `/admin/notifications/channels` — Webhook-URL/
Secret/Email-Template ONCE auf Tenant-Level konfigurieren. Rules picken
nur Channel-by-Name.

**Pflicht:** "Test"-Button + "Last 10 Evaluations"-Log pro Rule (sonst kein
Vertrauen).

**Norm-Mapping:**
- A.5.24 Incident-Mgmt-Coordination
- A.5.25 Assess+Categorize (Severity-Filter Pflicht — Alert-Fatigue-Vermeidung)
- A.5.26 Response-to-Incidents
- A.6.8 Reporting

**[v2-NEW] Audit-Chain:** `NotificationDelivery`-Entity mit `tenant_id` +
Chain-Entry on `delivered/failed`.

**Entities:**
- `NotificationRule` (tenant_id, eventType, conditions JSON, channels JSON, isActive)
- `NotificationDelivery` (rule_id, status, retries, log)
- `NotificationChannel` (tenant_id, type[email/webhook/in_app], config JSON, secret_encrypted)

**Services:** `NotificationDispatcher` (Symfony Messenger Async),
`NotificationRuleEvaluator`, `SlaDeadlineWatcher` (cron-driven),
Channel-Adapter `EmailChannel`, `WebhookChannel`, `InAppChannel`.

**[v2-NEW] Aurora-Macros:**
- Existierend: `_fa_page_header`, `_fa_section`, `_fa_feature_card`, `_fa_filter_chip`
- **NEU `_fa_condition_builder`** — Field/Op/Value-Chip-Row

**Aufwand:** 2 Sprints.

**Module-Gating:** `notifications` (existiert ggf. nicht — ergänzen wenn fehlt).
**Translation-Domain:** **`notifications`** (NEU)

---

### F5b. BSI/TISAX-Roundtrip [v2-CHANGE — ersetzt OSCAL F5]

**Demand:** hoch in DACH.

**Scope:**
- **BSI IT-Grundschutz-Kompendium-Edition-2024** Import-Roundtrip.
  Bausteine + Anforderungen als YAML in unsere Library-Format-Struktur.
- **VDA-ISA / TISAX-Katalog v6.0** (erwartet 2026) — Automotive-Markt riesig
  in DACH.
- **C5:2026** Migrationspfad — falls noch nicht vollständig.

**Begründung [v2]:** OSCAL-Use-Case im DACH-Mittelstand 2026 marginal —
NIST 800-53 spielt keine Rolle in 27001+NIS2+DORA-Stack. Viele DACH-Tenants
haben Grundschutz-Profile in fremden Tools, brauchen Migrationspfad.

**Entities:** keine neuen — schreibt in `fixtures/library/frameworks/`.

**Services:** `BsiKompendiumImporter`, `VdaIsaImporter`, `LibraryToCsv`/`CsvToLibrary`
(Roundtrip).

**Aufwand:** 1 Sprint.

**Translation-Domain:** existing `compliance`.

OSCAL F5 → P3 (siehe unten).

---

### F10. Scoping/Profile + Maturity-Ladder [v2-CHANGE — vorgezogen]

**Demand:** hoch (CM Kerntätigkeit).

**[v2-CHANGE] Per-Framework Maturity-Models, NICHT unified:**
- **BSI 200-2:** Basis / Standard / Kern (=Hoch) — protection-need driven
- **NIS-2:** Baseline / Enhanced — entity-class driven
- **NIST CSF 2.0:** Tier 1 Partial / 2 Risk-Informed / 3 Repeatable / 4 Adaptive
- **ISO/IEC 27001:** **KEIN Maturity-Ladder by design** — binary applicable/not
  + implementation-status. Maturity ist ISO/IEC 21827 (SSE-CMM) oder COBIT
  — separate Standards.
- **ISO 27017/27018:** additive Annex A on top of 27001 — kein Ladder, Delta-Set.
- **DORA:** Proportionalität (Art. 4) — size/risk-based, KEIN Ladder.

**Datenmodell:**
- `ComplianceFulfillment.maturityProfile` (string, framework-specific Enum)
- `ControlImplementation.maturityLevel` (per-control)
- Unified UI displays Progress side-by-side, Datenmodell preserves Framework-
  Semantik.

**[v2] Wiring:** Profile-Creation in `RiskTreatmentPlan` (Clause 6.1.3
Risk-Treatment-Decision).

**[v2] UI-Caveat:** Cross-Framework-Mapping ist auf Control-Level, NICHT
Maturity-Level. UI darf KEINE transitive Maturity-Conversion implizieren
(Auditor-Finding-Risiko: "ISO 27001 implemented" ≠ "BSI Standard-Absicherung").

**Aufwand:** 2 Sprints.

**Module-Gating:** `compliance`.
**Translation-Domain:** existing `compliance` + `compliance_wizard`.

---

## P3 — Strategisch (Sprints 7+)

### F5. OSCAL-Importer [v2-CHANGE — zurückgestuft P3]

**[v2-NEW] sourceProvenance-Field auf ComplianceFramework:**
- `sourceProvenance` (oscal_url + import_date + importer_user + content_hash)
- Ohne Provenance kann Framework nicht deterministisch re-importiert werden
  → Auditor: "ist das unalterierte NIST-Catalog?"

**[v2-NEW] Hybrid-Auto-Mapping:**
- ISO 27001 Annex A ↔ NIST 800-53r5 aus NIST-Official-Mapping
  (SP 800-53r5 Appendix H + ISO/IEC 27001:2022 Annex A — JSON publiziert).
- `provenance: "nist_official_mapping_v5.1"` für Auto-Mappings,
  `provenance: "tenant_curated"` für Manuelle.

**Catalog-Priority:**
1. NIST 800-53r5 (FedRAMP-Foundation, breitestes ISO-27001-Mapping)
2. NIST CSF 2.0 (höchste Markt-Demand, simpelste Struktur)
3. NIST 800-171r3 (DoD/CMMC pre-req, ~110 Controls)
- NICHT: 800-53A (Assessment) — das ist unsere Verification-Domain.

**Scope:** Catalog-Import-only. Profile-Roundtrip später separat
(Profile = unsere `ComplianceFulfillment`-Domain, nicht Library-Domain).

**[v2] UI: Admin-Only.** `Admin → Library → OSCAL Import` — Library-
Curator-Role-gated. **NICHT im CM-Flow.** Dry-Run mit Conflict-Cards
(Skip/Override/Rename) — CANNOT commit ohne Resolve. Aurora-Macros:
`_fa_diff_row` (geteilt mit F2).

**Aufwand:** 1 Sprint.
**Module-Gating:** `compliance`.
**Translation-Domain:** **`oscal`** (NEU)

---

### F6. REST-API Bulk + Webhook-Triggers

**[v2-NEW] Audit-Chain:** API-Token-Mgmt = A.5.16 + A.5.17. Token-Issuance/
Revocation Pflicht in HMAC-Chain. Bulk-Endpoints müssen Single-Audit-
Entry-Point respektieren (siehe Konzept-Invariant 7).

**Scope:** Bulk-Endpoints (`POST /api/risks/bulk`), API-Token-Mgmt pro
Tenant (Rotation, Scope), Outgoing-Webhooks (überlappt F3).

**Aufwand:** 1-2 Sprints.

---

### F7. Granulare RBAC (Field-Level + View-Restricted)

**[v2-NEW] Audit-Anforderung:** A.8.15 Logging empfiehlt Voter-Decision
"deny"-Events für sensitive Felder (riskOwner-PII).

**Scope:** Voter-Erweiterung für Field-Level — z.B. ROLE_AUDITOR_EXTERNAL
darf Risk lesen aber NICHT `riskOwner`-Field.

**Aufwand:** 2 Sprints.

---

### F8. Health-Check + Observability

**[v2-NEW] Disclosure-Constraints:**
- `/healthz` ist unauthenticated → MUSS NICHT Tenant-Counts oder
  Version-Strings beyond Major.Minor leaken.
- Tradeoff: A.8.16 Monitoring vs A.5.7 Threat-Intel-Disclosure.

**Scope:** `/healthz` (DB+Redis+Queue), `/readyz`, Prometheus-Metrics.

**Aufwand:** <1 Sprint.

---

### F9. i18n-Erweiterung: FR / IT / ES / NL / PT-BR

**Scope:** 90 Translation-Domains × neue Sprache. Crowdin/Weblate.
**Aufwand:** 1 Sprint Setup + Community-Pflege.

---

## NICHT umsetzen (Konzept-Bruch)

- ❌ **User-authored Framework-Builder** — bricht curated Library + HMAC-
  Audit-Chain. F10 löst Bedarf korrekt via Profile/Maturity.
- ❌ **Cross-Tenant Object-Move** — bricht Tenant-Isolation. Inner-Tenant
  Org-Units OK.
- ❌ **VM-Pivot** (Scanner-Parsing als Primary-Workflow) — wir sind GRC.

---

## Cross-Cutting Engineering-Tasks (vor Feature-Implementation)

### CC1. Aurora-Macro-Foundation [v2-NEW]
- `_fa_stepper.html.twig` — Wizard-Chrome (F1+F2+F5+F5b)
- `_fa_diff_row.html.twig` — Old→New Cell-Diff (F2+F5)
- `_fa_condition_builder.html.twig` — Field/Op/Value-Chip (F3)
- Live-Preview unter `/dev/design-system`
- A11y: keyboard-Navigation, aria-labels, role-attributes

**Aufwand:** 0.5 Sprint vor F1+F2+F3.

### CC2. Translation-Domain-Init [v2-NEW]
- Anlegen vor Feature-Implementation:
  - `sso.{de,en}.yaml`
  - `notifications.{de,en}.yaml`
  - `oscal.{de,en}.yaml`
- KEINE Competitor-Names in Strings (Memory `feedback_no_competitor_names`).

**Aufwand:** 0.1 Sprint pro Feature-Start.

### CC3. AuditLogger-Bulk-Helper [v2-NEW]
- `AuditLogger::logBulk(string $eventType, array $batchData, array $perEntityData)`
  — sicherheitsrelevanter Single-Entry-Point für Bulk-Import (F2), API-Bulk (F6),
  SSO-Provisioning (F1).

**Aufwand:** 0.3 Sprint vor F1+F2+F6.

### CC4. Module-Keys-Audit [v2-NEW]
- Prüfen ob `notifications` als Modul-Key existiert. Falls nicht: ergänzen.
- F11 braucht `analytics` (existiert).

**Aufwand:** 0.1 Sprint.

---

## Sequenzierung [v2 final]

| Sprint | Features | Begründung |
|---|---|---|
| 0 | CC1 (Aurora-Macros) + CC2 (Trans-Domains für F1/F2) + CC3 (AuditLogger-Bulk) | Foundations |
| 1 | F1 W1 (SSO Wizard+JIT) + F2 W1 (Asset+Supplier+Control + Delta) | parallel |
| 2 | F1 W2 (Tests+6 Audit-Events+RoleMapping) + F2 W2 (Risk+BusinessProcess) | abschließen |
| 3 | F4 (Evidence + Cascade + DocumentVersion) + F11 (FTE-Dashboard) | klein, hoher CM-Wert |
| 4 | F3 W1 (Email-Digest + Webhook + In-App-Center + Templates Tier 1) | Foundation |
| 5 | F3 W2 (Visual-Builder + SLA-Timer-Events) + F5b (BSI/TISAX-Roundtrip) | DACH-Fokus |
| 6 | F10 (Scoping/Profile per-Framework) | CM-Kerntätigkeit |
| 7+ | F5 (OSCAL-Lib), F7 (Field-RBAC), F6 (API-Bulk), F8 (Health), F9 (i18n) | strategisch |

---

## Erfolgs-Metriken (Sprint-1 quantifiziert)

- **F1:** **Time-to-First-Login < 90 Sek** ab IdP-Save. ISB-FTE-Reduktion
  bei User-Provisioning ≥ 50%.
- **F2:** **200 Assets < 5 Min** vom Excel-Upload bis DB-Commit.
  **Delta-Re-Upload < 2 Min.** Onboarding-Aufwand "neuer Tenant mit
  200 Assets" sinkt von ~8h auf <30 min.
- **F4:** Document-Reuse-Faktor **≥4**.
- **F11:** Dashboard zeigt belegbare FTE-Tage-Einsparung kumulativ.
- **F3:** Time-bis-Reaktion bei Critical-Incident sinkt messbar.
- **F5b:** Mind. 1 Tenant migriert von externem Tool nach Library-Format.

---

## Offene Entscheidungen

1. **F1:** Decision OIDC zuerst, LDAP Sprint 2 — bestätigt durch CM.
   ➜ ENTSCHIEDEN.
2. **F2:** XLSX zwingend, phpoffice/phpspreadsheet als Dependency.
   ➜ ENTSCHIEDEN.
3. **F3:** Slack/Teams Native streichen, Webhook+In-App reicht.
   ➜ ENTSCHIEDEN.
4. **F5 vs F5b:** F5 zurück nach P3, F5b in P2.
   ➜ ENTSCHIEDEN.
5. **F10 Maturity:** Per-Framework, nicht unified.
   ➜ ENTSCHIEDEN.
6. **OFFEN:** Wann FTE-Tracking-Calibration? (F11 braucht Avg-FTE-pro-
   Manual-Operation als Konstante — wo definiert? Tenant-konfigurierbar?
   Default-Werte aus CM-Schätzungen?)

---

## Spezialisten-Sign-Off

- **UX-Specialist:** ✅ Sign-Off mit Empfehlungen für Wizard-Pattern,
  Aurora-Macros, A11y-Anforderungen, Translation-Domains
- **Compliance-Manager (Effizienz):** ✅ Sign-Off mit FTE-Schätzungen,
  Delta-Mode-Pflicht, Cross-Framework-Cascade-Pflicht, FTE-Dashboard-
  Vorschlag, F5→F5b-Refocus, F10-Vorzug
- **ISMS-Specialist:** ✅ Sign-Off mit Norm-Mapping-Erweiterung
  (A.5.15/16/17/18, A.8.5, DORA Art. 9, NIS-2 Art. 21(2)(j)/(h)),
  6 Pflicht-Audit-Events für SSO, Hybrid-Audit-Pattern für Bulk,
  SLA-Deadline-Events, DocumentVersion-Immutability, sourceProvenance-
  Field, Per-Framework-Maturity, Single-Audit-Entry-Point-Mandat

---

## Referenzen

- Wettbewerbs-Issues-Analyse: konsolidierter Subagent-Bericht 2026-05-09
- ISO 27001:2022 Annex A Controls 5.x, 6.x, 7.x, 8.x
- BSI Standards 200-1, 200-2, 200-3, 200-4
- BSI IT-Grundschutz-Kompendium 2024
- EU DORA Reg. 2022/2554 + RTS 2024/1773 (Art. 19 Reporting)
- EU NIS-2 Dir. 2022/2555 + DE NIS2UmsuCG (Art. 23 Reporting)
- GDPR Art. 33 (72h Notification)
- NIST 800-53r5, NIST CSF 2.0, NIST 800-171r3
- VDA-ISA / TISAX v5.1 (v6.0 erwartet)
- BSI C5:2020 + 2026
- OSCAL 1.1.x (NIST machine-readable)
