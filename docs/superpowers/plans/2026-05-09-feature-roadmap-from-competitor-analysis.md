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

---

# Integration-Vollständigkeit pro Feature [v3-NEW]

Section listet pro Feature ALLE Touchpoints im Codebase die für volle
Tool-Integration nötig sind. Verhindert "halb-fertige" Features wo z.B.
neue Entity da ist aber Voter, Translation, Nav-Eintrag oder Audit-
Hook fehlt.

## Cross-Cutting-Standard-Checkliste (gilt für JEDE neue Entity)

Pro neuer Entity im Tool **muss** angelegt/aktualisiert werden:

| Item | Pfad / Pattern |
|---|---|
| Entity | `src/Entity/{Name}.php` mit `tenant_id` Field + Doctrine-Attributes |
| Repository | `src/Repository/{Name}Repository.php` extends ServiceEntityRepository |
| Service | `src/Service/{Name}Service.php` für Business-Logik (CRUD + Domain-Methods) |
| Controller | `src/Controller/{Name}Controller.php` mit `#[Route]` + `#[IsGranted]` Attributes |
| Voter | `src/Security/Voter/{Name}Voter.php` für Authorization |
| Form | `src/Form/{Name}Type.php` mit `ModuleAwareFormTrait` falls Modul-gated |
| Templates | `templates/{snake_name}/{index,show,new,edit}.html.twig` mit Aurora v4 |
| Migration | `migrations/Version{YYYYMMDDHHMMSS}_*.php` mit `isTransactional()=false` bei DDL |
| Fixtures | `src/DataFixtures/{Name}Fixtures.php` für Sample-Data |
| Translations | `translations/{domain}.{de,en}.yaml` — beide Sprachen, gleiche Keys |
| Module-Gate | `config/modules.yaml` — Key + Metadaten falls neuer Modul |
| Module-Check | Controller via `ModuleGatedControllerTrait::checkModuleActive()` |
| Audit-Hook | `src/EventSubscriber/AuditLogSubscriber.php` listet entity falls relevant |
| Navigation | `templates/_components/_mega_menu.html.twig` + `_mega_menu_panel_only.html.twig` |
| Tests | `tests/Entity/`, `tests/Service/`, `tests/Controller/{Name}ControllerTest.php` |
| Module-Check-Mock | Test-`setUp()` mockt `ModuleConfigurationService` (siehe Memory `feedback_csrf_tests_session`) |
| API (optional) | `#[ApiResource]` auf Entity falls API-exposed + `ApiTenantVoter` |
| AlvaHint (optional) | `src/AlvaHint/Rule/{Domain}/{Name}Rule.php` falls Hint-relevant |
| Workflow (optional) | `src/Service/Regulatory*WorkflowGenerator` falls timed-Workflow |
| Documentation | `docs/{feature}.md` falls neuer Major-Feature |

---

## F1 — OIDC SSO + LDAP (komplette Integration)

### Entities (neu)
- [ ] `src/Entity/IdentityProviderRoleMapping.php` — claim → role-mapping
  Fields: `tenant_id`, `identityProvider` (FK), `claimKey`, `claimValueExpression`,
  `assignedRole` (string ROLE_X), `assignedPermissions` (JSON), `priority` (int),
  `isActive` (bool), `auditDescription`
- [ ] `src/Entity/IdentityProviderUserMapping.php` — IdP-User-ID → ALVA-User
  Fields: `tenant_id`, `identityProvider` (FK), `user` (FK), `idpUserId`,
  `idpClaimsSnapshot` (JSON encrypted), `lastSyncedAt`, `firstLoggedInAt`

### Entities (modifiziert)
- [ ] `src/Entity/IdentityProvider.php` — Felder ergänzen:
  - `roleMappings` (OneToMany IdentityProviderRoleMapping)
  - `defaultFallbackRole` (string)
  - `mfaInheritance` (enum: required/optional/disabled)
  - `presetType` (enum: entra_id/google/keycloak/okta/auth0/generic)
- [ ] `src/Entity/Tenant.php` — `ssoEnforced` Field (boolean, default false)
- [ ] `src/Entity/User.php` — bereits SSO-fähig prüfen, ggf. `ssoLinked` Flag

### Repositories
- [ ] `src/Repository/IdentityProviderRoleMappingRepository.php`
- [ ] `src/Repository/IdentityProviderUserMappingRepository.php`
- [ ] `src/Repository/IdentityProviderRepository.php` ergänzen:
  - `findActiveByTenant(Tenant)`, `findByPresetType(string)`

### Services (neu)
- [ ] `src/Service/Sso/SsoUserProvisioningService.php` — JIT-Provisioning,
  geht durch Single-Audit-Entry-Point. Triggert `sso.jit.user_provisioned`.
- [ ] `src/Service/Sso/ClaimToRoleResolver.php` — wendet IdentityProviderRoleMapping an
- [ ] `src/Service/Sso/SsoEventLogger.php` — Wrapper um AuditLogger für SSO-spezifische
  Events (6 Events siehe Plan-v2)

### Services (modifiziert)
- [ ] `src/Service/Sso/OidcAuthenticationFlow.php` — Tests + Wiring (Coverage 7.69%
  → ≥80%)
- [ ] `src/Service/Sso/OidcDiscoveryService.php` — on-blur-Validation-API-Endpoint
- [ ] `src/Service/Sso/SsoProviderRegistry.php` — Preset-Support (entra_id/google/...)

### Controllers
- [ ] `src/Controller/Admin/SsoController.php` — bestehend? Refactor zu 3-Step-
  Wizard
- [ ] `src/Controller/Sso/SsoCallbackController.php` — OIDC-Redirect-Endpoint
- [ ] `src/Controller/Api/SsoDiscoveryApiController.php` — AJAX `/api/sso/validate-discovery`
  für on-blur-Check

### Forms (refactor)
- [ ] `src/Form/IdentityProviderType.php` — von 20-Field-Megaform zu 3-Step-Wizard
  (Step 1: Preset, Step 2: Discovery+Secret, Step 3: Test)
- [ ] `src/Form/IdentityProviderRoleMappingType.php` (neu) — claim/role pairs
  als CollectionType
- [ ] `src/Form/Step/Sso{Preset,Discovery,Test}StepType.php` — Sub-Forms

### Voters
- [ ] `src/Security/Voter/IdentityProviderVoter.php` — VIEW/EDIT/DELETE
- [ ] `src/Security/Voter/SsoConfigVoter.php` — Tenant-Admin-only

### Templates (Aurora v4)
- [ ] `templates/admin/sso/wizard/{step1_preset,step2_discovery,step3_test}.html.twig`
- [ ] `templates/admin/sso/index.html.twig` — IdP-Listen-View (refactor)
- [ ] `templates/admin/sso/show.html.twig` — Detail mit Audit-Log + Mappings
- [ ] `templates/_components/_fa_stepper.html.twig` (NEU — siehe CC1)

### Stimulus
- [ ] `assets/controllers/sso_wizard_controller.js` — Step-Navigation,
  on-blur-Discovery-Validation, Copy-Callback-URL-Button
- [ ] `assets/controllers/sso_role_mapping_controller.js` — Collection add/remove

### Migration
- [ ] `migrations/Version{YYYYMMDDHHMMSS}_f1_sso_role_mapping.php`
  - CREATE TABLE `identity_provider_role_mapping`
  - CREATE TABLE `identity_provider_user_mapping`
  - ALTER TABLE `identity_provider` ADD `default_fallback_role`,
    `mfa_inheritance`, `preset_type`
  - ALTER TABLE `tenant` ADD `sso_enforced`
  - `isTransactional()=false`

### Fixtures
- [ ] `src/DataFixtures/IdentityProviderFixtures.php` — Sample-Mapping für
  Demo-Tenant (Entra-ID Preset mit role-mapping)
- [ ] `fixtures/sso/presets/{entra_id,google,keycloak,okta,auth0,generic}.yaml` —
  Preset-Defaults (issuer-template, scopes, attribute-map)

### Module-Gate
- [ ] `config/modules.yaml` — `authentication` existiert ✓ (kein neuer Key)
- [ ] Tenant-Override: `config/active_modules.yaml` — Default-Activation prüfen

### Audit-Events (HMAC-Chain)
- [ ] `src/Service/AuditLogger.php` ergänzen falls nötig — neue ACTION-Konstanten:
  `ACTION_SSO_LOGIN_SUCCESS`, `ACTION_SSO_LOGIN_FAILURE`,
  `ACTION_SSO_JIT_PROVISIONED`, `ACTION_SSO_ROLE_CHANGED`,
  `ACTION_SSO_CONFIG_CHANGED`, `ACTION_SSO_ENFORCEMENT_CHANGED`
- [ ] `src/EventSubscriber/AuditLogSubscriber.php` — IdP-Lifecycle-Events einhaken

### Navigation
- [ ] `templates/_components/_mega_menu_panel_only.html.twig` Admin-Panel:
  Eintrag "SSO Identity Providers" unter "Users & Access"-Section. Modul-Gate
  `authentication`.

### Translation-Domain
- [ ] `translations/sso.de.yaml` (NEU)
- [ ] `translations/sso.en.yaml` (NEU)
- Keys-Bereiche: `sso.preset.{entra_id,google,...}`, `sso.field.*`,
  `sso.error.*`, `sso.audit.*`, `sso.help.*`, `sso.wizard.step.*`

### Tests
- [ ] `tests/Entity/IdentityProviderRoleMappingTest.php`
- [ ] `tests/Entity/IdentityProviderUserMappingTest.php`
- [ ] `tests/Service/Sso/SsoUserProvisioningServiceTest.php`
- [ ] `tests/Service/Sso/ClaimToRoleResolverTest.php`
- [ ] `tests/Service/Sso/OidcDiscoveryServiceTest.php` (Coverage 25% → 90%)
- [ ] `tests/Service/Sso/OidcAuthenticationFlowTest.php` (Coverage 7.69% → 90%)
- [ ] `tests/Controller/Admin/SsoControllerTest.php` — Wizard-Steps
- [ ] `tests/Controller/Sso/SsoCallbackControllerTest.php` — OIDC-Callback-Flow
- [ ] `tests/Form/IdentityProviderTypeTest.php`
- [ ] `tests/Security/Voter/IdentityProviderVoterTest.php`

### Documentation
- [ ] `docs/SSO_SETUP.md` — Tenant-Admin-Guide (Entra ID / Google / Keycloak)
- [ ] `docs/SSO_AUDIT_TRAIL.md` — welche Events, welche Norm-Mapping
- [ ] `CONTRIBUTING.md` — SSO-Test-Pattern (mock IdP via OidcDiscoveryService-Stub)

### CLAUDE.md
- [ ] SSO-Section ergänzen unter "Key Services"
- [ ] SSO-Audit-Pattern unter "Security Checklist"

---

## F2 — CSV/XLSX Bulk-Import (komplette Integration)

### Entities (neu)
- [ ] `src/Entity/BulkImportBatch.php` — eine Batch-Operation
  Fields: `tenant_id`, `entityType`, `sourceFileName`, `sourceFileHash` (SHA-256),
  `dryRunResultHash`, `rowCountTotal`, `rowCountSuccess`, `rowCountSkipped`,
  `rowCountError`, `mode` (initial/delta/dry_run), `executedBy`, `executedAt`,
  `sourceDocument` (FK → Document, type=import_evidence)
- [ ] `src/Entity/BulkImportRow.php` (optional, für Error-CSV-Re-Generation)
  Fields: `batch_id`, `rowNumber`, `status`, `errorMessage`, `parsedData` (JSON)

### Entities (modifiziert)
- [ ] `src/Entity/Document.php` — `documentType` Enum erweitern um `import_evidence`
- [ ] Pro Bulk-fähige Entity ein Field `lastImportBatchId` (optional, Audit-trace)

### Repositories
- [ ] `src/Repository/BulkImportBatchRepository.php`
  - `findByTenant(Tenant)`, `findRecentByEntityType(string)`,
    `findByExternalId(string $externalId)` (für Delta-Mode)

### Services (neu)
- [ ] `src/Service/Import/BulkImportOrchestrator.php` — High-Level: Upload →
  Parse → Map → Preview → Commit
- [ ] `src/Service/Import/HeaderHeuristicMapper.php` — Auto-Map Spalten via
  Header-Name-Match (Confidence-Score)
- [ ] `src/Service/Import/EntityMapperRegistry.php` — Registry pro Entity-Type
- [ ] `src/Service/Import/Mapper/{Asset,Supplier,Control,Risk,BusinessProcess}Mapper.php`
  — Entity-spezifische Field-Mapping + Validation
- [ ] `src/Service/Import/DeltaCalculator.php` — Diff-Berechnung bei Re-Import
  via slug/external-id
- [ ] `src/Service/Import/SpreadsheetParser.php` — XLSX (phpoffice) + CSV
- [ ] `src/Service/AuditLogger.php` ergänzen: `logBulk(string $eventType,
  array $batchData, array $perEntityData)` (CC3-Task)
- [ ] `src/MessageHandler/BulkImportMessageHandler.php` — Symfony Messenger
  async Worker
- [ ] `src/Message/BulkImportMessage.php` — Dispatch-Message

### Services (modifiziert)
- [ ] `src/Service/InputValidationService.php` — reuse für CSV-Sanitization
- [ ] `src/Service/FileUploadSecurityService.php` — XLSX-MIME-Whitelist + Size-Limit

### Controllers
- [ ] `src/Controller/Import/BulkImportController.php` — Wizard-Routes:
  - `/import/{entityType}/upload`
  - `/import/{entityType}/map/{batchId}`
  - `/import/{entityType}/preview/{batchId}`
  - `/import/{entityType}/commit/{batchId}`
  - `/import/{entityType}/diff/{batchId}` (Delta-Mode)
- [ ] `src/Controller/Import/BulkExportController.php` — symmetrischer Export

### Forms
- [ ] `src/Form/Import/UploadStepType.php` — File-Upload + Entity-Type-Picker
- [ ] `src/Form/Import/ColumnMappingType.php` — Spalten → Entity-Field-DropDowns
  (Collection mit Auto-Mapping-Defaults)
- [ ] `src/Form/Import/PreviewConfirmType.php` — Skip-on-Error-Toggle + Confirm

### Voters
- [ ] `src/Security/Voter/BulkImportVoter.php` — pro Entity-Type prüfen ob
  User Import-Permission hat (NICHT ROLE_USER, mind. ROLE_MANAGER)

### Templates (Aurora v4)
- [ ] `templates/import/wizard/{upload,map,preview,commit,diff}.html.twig`
- [ ] `templates/import/index.html.twig` — Liste vergangener Imports + Status
- [ ] `templates/import/_diff_table.html.twig` — Delta-View mit `_fa_diff_row`
- [ ] `templates/_components/_fa_diff_row.html.twig` (NEU — CC1)

### Stimulus
- [ ] `assets/controllers/bulk_import_wizard_controller.js` — Wizard-Step-
  Management, Drag-Drop, Client-Side-CSV-Preview (SheetJS via CDN-allowed)
- [ ] `assets/controllers/column_mapping_controller.js` — Auto-Map-Confidence-
  Display, manual-Override
- [ ] `assets/controllers/import_progress_controller.js` — Async-Job-Polling
  via Mercure oder SSE

### Migration
- [ ] `migrations/Version{YYYYMMDDHHMMSS}_f2_bulk_import.php`
  - CREATE TABLE `bulk_import_batch`
  - CREATE TABLE `bulk_import_row` (optional)
  - ALTER TABLE `document` (Enum-Werte erweitern)
  - Pro Bulk-Entity: ALTER TABLE ADD `last_import_batch_id` (nullable)
  - `isTransactional()=false`

### Fixtures
- [ ] `fixtures/sample-imports/assets-sample.xlsx` — Excel-Template zum
  Download für Onboarding
- [ ] `fixtures/sample-imports/{supplier,control,risk}-sample.xlsx`

### Module-Gate
- [ ] Pro Entity bereits gated. Bulk-Import nutzt vorhandene Module-Keys.
- [ ] `config/modules.yaml` — kein neuer Key.

### Audit-Events
- [ ] `bulk_import.executed` (Batch-Entry)
- [ ] `bulk_import.row_processed` (Per-Entity-Entry, ref batch_id)
- [ ] `bulk_import.delta_applied` (bei Delta-Mode)

### Navigation
- [ ] `templates/_components/_mega_menu_panel_only.html.twig` — `data_import`
  existiert bereits. Sub-Items pro Entity-Type ergänzen.

### Translation-Domain
- [ ] `translations/data_import.de.yaml` ergänzen (existiert ggf.)
- [ ] `translations/data_import.en.yaml` ergänzen
- Keys: `import.wizard.step.*`, `import.entity.{asset,supplier,...}`,
  `import.error.*`, `import.delta.*`, `import.success.*`

### Tests
- [ ] `tests/Service/Import/BulkImportOrchestratorTest.php`
- [ ] `tests/Service/Import/HeaderHeuristicMapperTest.php`
- [ ] `tests/Service/Import/Mapper/AssetMapperTest.php` (+ andere Mapper)
- [ ] `tests/Service/Import/DeltaCalculatorTest.php`
- [ ] `tests/Service/Import/SpreadsheetParserTest.php`
- [ ] `tests/MessageHandler/BulkImportMessageHandlerTest.php`
- [ ] `tests/Controller/Import/BulkImportControllerTest.php` — Wizard E2E
- [ ] `tests/Form/Import/ColumnMappingTypeTest.php`
- [ ] `tests/Security/Voter/BulkImportVoterTest.php`
- [ ] Fixture-Tests mit echten Sample-XLSX

### Composer-Dependency
- [ ] `composer require phpoffice/phpspreadsheet`
- [ ] `composer.json` Version-Constraint sinnvoll wählen (4.x)

### Documentation
- [ ] `docs/BULK_IMPORT.md` — User-Guide
- [ ] `docs/BULK_IMPORT_DEVELOPER.md` — Mapper-Pattern für neue Entity

### CLAUDE.md
- [ ] Bulk-Import-Pattern unter "Development Patterns"
- [ ] Audit-Hybrid-Pattern unter "Security Checklist"

---

## F4 — Evidence-Versioning + Cross-Framework-Cascade

### Entities (neu)
- [ ] `src/Entity/DocumentVersion.php` — versioned Snapshot, IMMUTABLE nach Publish
  Fields: `tenant_id`, `document` (FK), `versionNumber`, `contentHash` (SHA-256),
  `fileName`, `filePath`, `fileSize`, `mimeType`, `uploadedBy`, `uploadedAt`,
  `replacedBy` (FK self, nullable), `publishedAt`, `retentionUntil`, `isActive`
- [ ] `src/Entity/EvidenceReverificationTask.php` — Reviewer-Queue
  Fields: `tenant_id`, `documentVersion` (FK), `controlImplementation` (FK,
  nullable), `complianceFulfillment` (FK, nullable), `assignedTo`, `dueDate`,
  `status` (pending/in_progress/completed/skipped), `completedAt`, `notes`

### Entities (modifiziert)
- [ ] `src/Entity/Document.php` — Felder ergänzen:
  - `contentHash` (string, SHA-256 aktueller Version)
  - `currentVersion` (FK → DocumentVersion)
  - `versions` (OneToMany)
- [ ] `src/Entity/ControlImplementation.php` — Field `evidenceOutdated` (bool default false)
- [ ] `src/Entity/ComplianceFulfillment.php` — Field `evidenceOutdated` (bool)

### Repositories
- [ ] `src/Repository/DocumentVersionRepository.php`
- [ ] `src/Repository/EvidenceReverificationTaskRepository.php`
- [ ] `src/Repository/ControlImplementationRepository.php` ergänzen:
  - `findEvidenceOutdated(Tenant)`

### Services (neu)
- [ ] `src/Service/Evidence/EvidenceVersioningService.php` — Auto-Version on
  Re-Upload, Hash-Match-Detection, 5s-Undo-Buffer (Session-State)
- [ ] `src/Service/Evidence/EvidenceCascadeInvalidationService.php` — bei
  DocumentVersion-Inkrement: alle linked CI/CF.evidenceOutdated=true,
  Reverification-Tasks erzeugen
- [ ] `src/Service/Evidence/DocumentReuseAnalyticsService.php` — Reuse-Faktor
  Berechnung pro Document + aggregiert pro Tenant
- [ ] `src/Service/Evidence/ContentHashCalculator.php` — SHA-256 streaming für
  große Files

### Services (modifiziert)
- [ ] `src/Service/DocumentService.php` — uploadFile() integriert mit
  EvidenceVersioningService
- [ ] `src/Service/AuditLogger.php` — Events:
  `document.version.created`, `document.version.evidence_invalidated`

### Controllers
- [ ] `src/Controller/DocumentController.php` ergänzen:
  - `/document/{id}/versions` Liste aller Versionen
  - `/document/{id}/version/{vid}/download`
  - `/document/{id}/version/{vid}/undo` (5s-Window)
- [ ] `src/Controller/EvidenceReverificationController.php` (NEU) —
  Reviewer-Queue + Mark-as-Reverified

### Voters
- [ ] `src/Security/Voter/DocumentVersionVoter.php` — VIEW (kein DELETE
  wegen Immutability!)
- [ ] `src/Security/Voter/EvidenceReverificationTaskVoter.php`

### Templates (Aurora v4)
- [ ] `templates/document/_version_list.html.twig` — Versions-Drawer
- [ ] `templates/document/show.html.twig` ergänzen — Reuse-Badge "12 controls
  · 4 frameworks", Drawer mit Breakdown
- [ ] `templates/evidence_reverification/{index,show}.html.twig` — Reviewer-Queue
- [ ] `templates/document/_undo_toast.html.twig` — 5s-Undo-Notification

### Stimulus
- [ ] `assets/controllers/evidence_undo_controller.js` — 5s-Toast mit
  Cancel-Button
- [ ] `assets/controllers/reuse_drawer_controller.js` — Click-to-Expand

### Migration
- [ ] `migrations/Version{YYYYMMDDHHMMSS}_f4_document_versioning.php`
  - CREATE TABLE `document_version`
  - CREATE TABLE `evidence_reverification_task`
  - ALTER TABLE `document` ADD `content_hash`, `current_version_id`
  - ALTER TABLE `control_implementation` ADD `evidence_outdated`
  - ALTER TABLE `compliance_fulfillment` ADD `evidence_outdated`
  - DATA-Migration: existing `Document.version`-String → erste DocumentVersion-Row
  - `isTransactional()=false` für DDL, Data-Migration separat

### Fixtures
- [ ] `src/DataFixtures/DocumentVersionFixtures.php` — Sample-History

### Module-Gate
- [ ] Document existiert bereits ohne explicit Modul-Key (Core-Funktion).

### Audit-Events
- [ ] `document.version.created`
- [ ] `document.version.evidence_invalidated` (mit impacted_count)
- [ ] `evidence_reverification.task_created`
- [ ] `evidence_reverification.task_completed`

### Navigation
- [ ] `templates/_components/_mega_menu_panel_only.html.twig` Compliance-Section:
  "Evidence Reviewer Queue" Eintrag (badge falls offene Tasks)

### Translation-Domain
- [ ] `translations/document.de.yaml` ergänzen
- [ ] `translations/document.en.yaml` ergänzen
- Keys: `document.version.*`, `document.undo.*`, `document.reuse.*`,
  `evidence_reverification.*`

### Tests
- [ ] `tests/Entity/DocumentVersionTest.php`
- [ ] `tests/Entity/EvidenceReverificationTaskTest.php`
- [ ] `tests/Service/Evidence/EvidenceVersioningServiceTest.php`
- [ ] `tests/Service/Evidence/EvidenceCascadeInvalidationServiceTest.php`
- [ ] `tests/Service/Evidence/DocumentReuseAnalyticsServiceTest.php`
- [ ] `tests/Controller/EvidenceReverificationControllerTest.php`
- [ ] `tests/Functional/DocumentVersionImmutabilityTest.php` — confirm DELETE-block

### Documentation
- [ ] `docs/EVIDENCE_VERSIONING.md` — User + Auditor-View

### CLAUDE.md
- [ ] Evidence-Cascade-Pattern unter "Development Patterns"

---

## F11 — FTE-Tracking-Dashboard

### Entities (neu)
- [ ] `src/Entity/FteTrackingMetric.php`
  Fields: `tenant_id`, `source` (enum: bulk_import/sso_jit/evidence_reuse/...),
  `entityType` (string), `entityId` (int, nullable), `manualMinutesEstimate`,
  `actualMinutesEstimate`, `savingsMinutes`, `recordedAt`, `period` (enum:
  daily/monthly), `metadata` (JSON)
- [ ] `src/Entity/FteCalibrationConstant.php` — pro Tenant konfigurierbar
  Fields: `tenant_id`, `operationType` (e.g. `manual_user_provisioning`,
  `manual_asset_creation`), `minutesPerOperation` (decimal), `lastUpdatedBy`

### Repositories
- [ ] `src/Repository/FteTrackingMetricRepository.php`
  - `getSavingsAggregate(Tenant, DateInterval)`,
  - `getSavingsBySource(Tenant)`, `getMonthlyTrend(Tenant, int $months)`
- [ ] `src/Repository/FteCalibrationConstantRepository.php`

### Services (neu)
- [ ] `src/Service/Fte/FteCalculationService.php` — pro Source-Typ
  Berechnungs-Logik
- [ ] `src/Service/Fte/FteRecorderService.php` — Hooks in F1/F2/F4 EventListener
- [ ] `src/Service/Fte/BoardReportGenerator.php` — Monthly-PDF/HTML-Report

### Services (modifiziert)
- [ ] `src/Service/Sso/SsoUserProvisioningService.php` — emit FTE-Metric-Event
- [ ] `src/Service/Import/BulkImportOrchestrator.php` — emit FTE-Metric-Event
- [ ] `src/Service/Evidence/DocumentReuseAnalyticsService.php` — emit FTE-Metric

### Controllers
- [ ] `src/Controller/Analytics/FteTrackingDashboardController.php`
  - `/{locale}/dashboard/fte-tracking`
  - `/{locale}/dashboard/fte-tracking/calibration`
  - `/{locale}/dashboard/fte-tracking/board-report.{format}` (pdf/html/csv)

### Voters
- [ ] `src/Security/Voter/FteTrackingVoter.php` — ROLE_MANAGER + nur
  eigene Tenant-Daten

### Templates (Aurora v4)
- [ ] `templates/dashboard/fte_tracking/index.html.twig` — Live-Counter,
  Source-Breakdown, Monthly-Trend-Chart (Chart.js)
- [ ] `templates/dashboard/fte_tracking/calibration.html.twig` — Calibration-
  Constants pro Tenant editierbar
- [ ] `templates/dashboard/fte_tracking/board_report.html.twig` — Print-View
- [ ] **Cross-Framework-Gap-Heatmap-View** in `templates/compliance/_heatmap.html.twig`
  — Tabelle Frameworks × Anforderungs-Cluster × Ampel

### Stimulus
- [ ] `assets/controllers/fte_chart_controller.js` — Chart.js-Init mit
  Period-Toggle

### Migration
- [ ] `migrations/Version{YYYYMMDDHHMMSS}_f11_fte_tracking.php`
  - CREATE TABLE `fte_tracking_metric`
  - CREATE TABLE `fte_calibration_constant` mit Default-Inserts
  - `isTransactional()=false`

### Fixtures
- [ ] `src/DataFixtures/FteCalibrationConstantFixtures.php` — Default-
  Werte aus CM-Schätzungen (manuelle User-Provisioning = 20 min,
  manuelles Asset-Insert = 3 min, Single-Framework-Evidence-Pflege = 8 min)

### Module-Gate
- [ ] `analytics` (existiert).

### Audit-Events
- [ ] `fte_tracking.metric_recorded` (low-priority, bei jedem Event)
- [ ] `fte_calibration.constant_changed` (high — Audit-relevant)

### Navigation
- [ ] `templates/_components/_mega_menu_panel_only.html.twig` Dashboard-
  Section: "FTE-Tracking" Eintrag

### Translation-Domain
- [ ] `translations/analytics.{de,en}.yaml` ergänzen
- [ ] `translations/kpi.{de,en}.yaml` ergänzen
- Keys: `fte_tracking.dashboard.*`, `fte_tracking.calibration.*`,
  `fte_tracking.source.*`, `fte_tracking.report.*`

### Tests
- [ ] `tests/Service/Fte/FteCalculationServiceTest.php`
- [ ] `tests/Service/Fte/BoardReportGeneratorTest.php`
- [ ] `tests/Controller/Analytics/FteTrackingDashboardControllerTest.php`

### Documentation
- [ ] `docs/FTE_TRACKING.md` — Methodik + Calibration

### CLAUDE.md
- [ ] FTE-Tracking-Pattern unter "Development Patterns"

---

## F3 — Notification-Rulesets + SLA-Timer-Events

### Entities (neu)
- [ ] `src/Entity/Notification/NotificationRule.php`
  Fields: `tenant_id`, `name`, `eventType` (enum), `conditions` (JSON,
  field/op/value), `channels` (M2M NotificationChannel), `severityFilter`,
  `isActive`, `evaluationCount`, `lastEvaluatedAt`, `createdBy`
- [ ] `src/Entity/Notification/NotificationChannel.php`
  Fields: `tenant_id`, `name`, `type` (email/webhook/in_app), `config` (JSON),
  `secretEncrypted`, `isActive`, `verifiedAt`
- [ ] `src/Entity/Notification/NotificationDelivery.php`
  Fields: `tenant_id`, `rule` (FK), `channel` (FK), `status`, `retries`,
  `responsePayload` (JSON), `attemptedAt`, `deliveredAt`, `errorMessage`
- [ ] `src/Entity/Notification/NotificationTemplate.php` — Tier-1 Pre-Built-Rules
  Fields: `templateKey`, `name`, `defaultEventType`, `defaultConditions` (JSON),
  `defaultChannels` (JSON-spec), `category` (incident/compliance/sla)
- [ ] `src/Entity/Notification/SlaDeadlineMonitor.php` — Active SLA-Tracker
  Fields: `tenant_id`, `entityType`, `entityId`, `deadlineType` (enum:
  gdpr_72h, dora_4h, dora_24h, dora_1mo, nis2_24h, nis2_72h, nis2_1mo, ...),
  `triggeredAt`, `deadlineAt`, `notifyAtCheckpoints` (JSON: [24,12,4,1]),
  `lastNotifiedAtHours`, `status` (active/missed/satisfied)

### Entities (modifiziert)
- [ ] `src/Entity/User.php` — `inAppNotificationsEnabled` Flag, `lastSeenNotifications`

### Repositories
- [ ] `src/Repository/Notification/NotificationRuleRepository.php`
- [ ] `src/Repository/Notification/NotificationChannelRepository.php`
- [ ] `src/Repository/Notification/NotificationDeliveryRepository.php`
- [ ] `src/Repository/Notification/SlaDeadlineMonitorRepository.php`
  - `findApproachingDeadlines(Tenant, int $hoursAhead)`,
  - `findMissedDeadlines(Tenant)`

### Services (neu)
- [ ] `src/Service/Notification/NotificationDispatcher.php` — Symfony Messenger
  async dispatch
- [ ] `src/Service/Notification/NotificationRuleEvaluator.php` — Condition-
  Engine (evaluiert JSON-Conditions gegen Entity-State)
- [ ] `src/Service/Notification/SlaDeadlineWatcher.php` — Cron-driven, fired
  via `app:process-timed-workflows` extension
- [ ] `src/Service/Notification/Channel/EmailChannel.php` — Symfony Mailer +
  Digest-Logik
- [ ] `src/Service/Notification/Channel/WebhookChannel.php` — Guzzle + HMAC
  Signing + NoInternalIp-Validation
- [ ] `src/Service/Notification/Channel/InAppChannel.php` — DB-Insert für
  Glocke-Center
- [ ] `src/Service/Notification/TemplateInstantiator.php` — Tier-1-Templates
  → echte NotificationRule
- [ ] `src/MessageHandler/Notification/DispatchNotificationHandler.php`
- [ ] `src/Message/Notification/DispatchNotificationMessage.php`

### Services (modifiziert)
- [ ] `src/Service/WorkflowAutoProgressionService.php` — emittiert
  `sla.deadline.approaching` Events bei Step-Transition
- [ ] `src/Command/ProcessTimedWorkflowsCommand.php` — ergänzt um
  `SlaDeadlineWatcher::tickAll()`

### Controllers
- [ ] `src/Controller/Admin/Notification/NotificationRuleController.php` —
  CRUD für Rules
- [ ] `src/Controller/Admin/Notification/NotificationChannelController.php` —
  CRUD für Channels
- [ ] `src/Controller/Admin/Notification/NotificationTemplateController.php` —
  Tier-1-Gallery + One-Click-Apply
- [ ] `src/Controller/Notification/InAppNotificationCenterController.php` —
  Glocke-Dropdown + Mark-as-Read

### Forms
- [ ] `src/Form/Notification/NotificationRuleType.php` — Visual-Builder
  (Tier-2)
- [ ] `src/Form/Notification/NotificationChannelType.php`
- [ ] `src/Form/Notification/ConditionBuilderType.php` — Field/Op/Value-
  Collection (Tier-2 inner)

### Voters
- [ ] `src/Security/Voter/NotificationRuleVoter.php`
- [ ] `src/Security/Voter/NotificationChannelVoter.php`

### Templates (Aurora v4)
- [ ] `templates/admin/notification/rule/{index,show,new,edit}.html.twig`
- [ ] `templates/admin/notification/channel/{index,new,edit}.html.twig`
- [ ] `templates/admin/notification/template/index.html.twig` — Tier-1-Gallery
- [ ] `templates/admin/notification/rule/_evaluation_log.html.twig` — Last-10
- [ ] `templates/_components/_navbar_notification_bell.html.twig` — Glocke
  in Header
- [ ] `templates/notification_center/index.html.twig` — In-App-Center-Page
- [ ] `templates/_components/_fa_condition_builder.html.twig` (NEU — CC1)

### Stimulus
- [ ] `assets/controllers/notification_bell_controller.js` — Polling /
  Mercure-Subscription
- [ ] `assets/controllers/condition_builder_controller.js` — chip-Add/Remove
- [ ] `assets/controllers/template_picker_controller.js` — Tier-1 One-Click

### Migration
- [ ] `migrations/Version{YYYYMMDDHHMMSS}_f3_notifications.php`
  - 5 CREATE TABLE
  - INSERT für NotificationTemplate (6 Tier-1-Defaults)
  - `isTransactional()=false`

### Fixtures
- [ ] `src/DataFixtures/NotificationTemplateFixtures.php` — Tier-1-Templates:
  - "Notify CISO via Webhook on DataBreach severity≥high"
  - "Notify Security-Team via Email on Incident criticality≥high"
  - "Notify DPO via Email when DataBreach 24h-deadline approaching"
  - "Notify Risk-Owner via Email on Risk exceeds-appetite"
  - "Notify Auditor via Webhook on Workflow.step completed"
  - "Notify CISO via In-App on ControlImplementation.overdueVerification"

### Module-Gate
- [ ] `config/modules.yaml` — **`notifications` Key ergänzen** falls fehlt
  (Plan-Annahme: noch nicht da)

### Audit-Events
- [ ] `notification.rule.{created,updated,deleted,enabled,disabled}`
- [ ] `notification.channel.{created,updated,verified}`
- [ ] `notification.delivery.{succeeded,failed,retried}`
- [ ] `sla.deadline.approaching` (entity, deadline_type, hours_remaining)
- [ ] `sla.deadline.missed` (entity, deadline_type, missed_by_hours)

### Navigation
- [ ] `templates/_components/_mega_menu.html.twig` — Notification-Bell
  Trigger-Icon im Header (separater von mega-menu, in Top-Bar)
- [ ] Admin-Panel: "Notifications → Rules / Channels / Templates"

### Translation-Domain
- [ ] `translations/notifications.de.yaml` (NEU)
- [ ] `translations/notifications.en.yaml` (NEU)

### Tests
- [ ] `tests/Entity/Notification/{NotificationRule,Channel,Delivery,Template,SlaDeadlineMonitor}Test.php`
- [ ] `tests/Service/Notification/NotificationDispatcherTest.php`
- [ ] `tests/Service/Notification/NotificationRuleEvaluatorTest.php`
- [ ] `tests/Service/Notification/SlaDeadlineWatcherTest.php`
- [ ] `tests/Service/Notification/Channel/{Email,Webhook,InApp}ChannelTest.php`
- [ ] `tests/Controller/Admin/Notification/NotificationRuleControllerTest.php`
- [ ] `tests/Functional/SlaDeadlineFlow/DataBreach72hFlowTest.php`

### Documentation
- [ ] `docs/NOTIFICATIONS.md` — Tier-1/2/3 + SLA-Concepts
- [ ] `docs/SLA_DEADLINES.md` — Norm-Mapping (DORA Art. 19, NIS-2 Art. 23, GDPR Art. 33)

### CLAUDE.md
- [ ] Notification-Pattern unter "Development Patterns"

---

## F5b — BSI/TISAX-Library-Roundtrip

### Library-Format
- [ ] `fixtures/library/frameworks/bsi-it-grundschutz-2024.yaml` — komplettes
  Kompendium 2024 als YAML
- [ ] `fixtures/library/frameworks/vda-isa-tisax-v6.yaml` — sobald
  veröffentlicht (oder v5.1 aktuell)
- [ ] `fixtures/library/mappings/iso27001-2022_to_bsi-grundschutz-2024.yaml`
- [ ] `fixtures/library/mappings/bsi-grundschutz-2024_to_tisax-v6.yaml`

### Services
- [ ] `src/Service/Library/BsiKompendiumImporter.php` — XLSX-Parser für
  BSI-Releases
- [ ] `src/Service/Library/VdaIsaImporter.php`
- [ ] `src/Service/Library/LibraryRoundtripService.php` — bidirektional
  YAML↔CSV für externe Tools

### Controllers
- [ ] `src/Controller/Admin/Library/LibraryImporterController.php` —
  Admin-Only

### Migration
- [ ] keine — nur YAML-Library + Loader-Update

### Tests
- [ ] `tests/Service/Library/BsiKompendiumImporterTest.php`
- [ ] `tests/Service/Library/LibraryRoundtripServiceTest.php`

---

## F10 — Per-Framework Scoping/Profile + Maturity

### Entities (modifiziert)
- [ ] `src/Entity/ComplianceFulfillment.php` — Field `maturityProfile`
  (string, framework-specific Enum)
- [ ] `src/Entity/ControlImplementation.php` — Field `maturityLevel`
- [ ] `src/Entity/ComplianceFramework.php` — Field `maturityModel` (enum:
  `binary` für 27001, `protection_need` für BSI, `entity_class` für NIS-2,
  `csf_tier` für CSF)

### Services (neu)
- [ ] `src/Service/Profile/MaturityProfileService.php` — pro Framework
  Logik
- [ ] `src/Service/Profile/ProfileMigrationService.php` — Tenant-Profile
  zwischen Frameworks vergleichen (READ-only, KEINE transitive
  Conversion-UI!)

### Services (modifiziert)
- [ ] `src/Service/RiskTreatmentPlanService.php` — Profile-Creation als
  Risk-Treatment-Decision wired

### Controllers
- [ ] `src/Controller/Profile/MaturityProfileController.php`

### Forms
- [ ] `src/Form/Profile/MaturityProfileType.php`

### Templates
- [ ] `templates/profile/{index,show,edit}.html.twig`
- [ ] `templates/profile/_side_by_side_progress.html.twig` — Display-only

### Migration
- [ ] `migrations/Version{YYYYMMDDHHMMSS}_f10_maturity_profile.php`

### Tests
- [ ] `tests/Service/Profile/MaturityProfileServiceTest.php`

### Translation-Domain
- [ ] `translations/compliance.{de,en}.yaml` ergänzen
- [ ] `translations/compliance_wizard.{de,en}.yaml` ergänzen

---

## P3-Features (kompakte Integration)

### F5 OSCAL — siehe F5b-Pattern, ergänzt um:
- [ ] `src/Entity/ComplianceFramework.php` — Field `sourceProvenance` (JSON)
- [ ] `fixtures/library/frameworks/{nist-800-53r5,nist-csf-2,nist-800-171r3}.yaml`
- [ ] `src/Service/Library/OscalImporter.php`
- [ ] Translation-Domain `translations/oscal.{de,en}.yaml`

### F6 REST-API Bulk + Webhooks
- [ ] `#[ApiResource]` Bulk-Endpoints (`POST /api/{entity}/bulk`)
- [ ] `src/Entity/ApiToken.php` (falls noch nicht existiert) mit Rotation
- [ ] `src/Security/ApiTokenAuthenticator.php`
- [ ] Audit-Hook: API-Token-Issuance/Revocation

### F7 Field-Level RBAC
- [ ] Voter-Erweiterung: `src/Security/Voter/FieldLevelVoter.php`
- [ ] `src/Twig/Extension/FieldGuardExtension.php` für Template-Side
- [ ] Decorator um Form-Theme: hide field if denied
- [ ] Audit-Event `voter.field_denied` (low-priority Sample-Log)

### F8 Health-Check
- [ ] `src/Controller/HealthCheckController.php` (`/healthz`, `/readyz`)
- [ ] Disclosure-Constraints: nur Major.Minor, keine Tenant-Counts
- [ ] Prometheus-Metrics-Endpoint (optional)

### F9 i18n FR/IT/ES/NL/PT-BR
- [ ] Crowdin/Weblate-Setup
- [ ] Translation-Files für 90 Domains × 5 neue Sprachen

---

## Cross-Cutting Sprint-0 Tasks (CC1-CC4 detailliert)

### CC1. Aurora-Macro-Foundation
- [ ] `templates/_components/_fa_stepper.html.twig`
  - Macros: `render(steps, currentIndex, options)`, `step_indicator(index, label)`
  - A11y: `role="navigation"`, `aria-current="step"`, keyboard-Tab-Order
- [ ] `templates/_components/_fa_diff_row.html.twig`
  - Macros: `render(oldValue, newValue, options)`, `cell_diff(left, right)`
- [ ] `templates/_components/_fa_condition_builder.html.twig`
  - Macros: `render(fields, operators, conditions)`, `chip(field, op, value)`
  - A11y: `aria-live="polite"` für Add/Remove
- [ ] Live-Preview unter `/dev/design-system` registrieren
- [ ] Tests: `tests/Twig/Component/FaStepperTest.php` etc.

### CC2. Translation-Domain-Init
- [ ] `translations/sso.{de,en}.yaml` (leeres Skelett mit nav-/error-/help-Keys)
- [ ] `translations/notifications.{de,en}.yaml`
- [ ] `translations/oscal.{de,en}.yaml`
- [ ] `scripts/quality/check_translation_issues.py` — Domains-Whitelist
  ergänzen

### CC3. AuditLogger-Bulk-Helper
- [ ] `src/Service/AuditLogger.php` — neue Methode `logBulk(string $eventType,
  array $batchData, array $perEntityData): string` returns batch_id
- [ ] Tests: `tests/Service/AuditLoggerBulkTest.php`
- [ ] CLAUDE.md ergänzen: "Bulk-Operations gehen IMMER durch
  `AuditLogger::logBulk()` — nie direkt `executeStatement()`"

### CC4. Module-Keys-Audit
- [ ] `config/modules.yaml` ergänzen falls fehlt:
  - `notifications` (für F3)
- [ ] `tests/Service/ModuleConfigurationServiceTest.php` — testet alle
  20 Keys roundtrip
- [ ] Test-Fixture-Update: `setUp()` in 7 Controller-Tests ergänzt
  `notifications` in der `in_array` Liste (siehe Memory-Pattern)

---

## Memory-Updates nach Implementation

Pro Feature ggf. eintragen in `MEMORY.md`:

- [ ] `project_sso_foundation.md` nach F1
- [ ] `project_bulk_import_pattern.md` nach F2
- [ ] `project_evidence_cascade.md` nach F4
- [ ] `feedback_audit_logger_bulk_pattern.md` nach CC3
- [ ] `project_notification_tier_system.md` nach F3

---

## Definition-of-Done pro Feature [v3-NEW]

Ein Feature ist erst "fertig" wenn:

1. ☐ Alle Items aus Cross-Cutting-Standard-Checkliste abgehakt
2. ☐ Alle Items aus Feature-spezifischer Liste abgehakt
3. ☐ `find src tests -name "*.php" -print0 | xargs -0 -n1 php -l` = 0 errors
4. ☐ `php bin/console lint:container` = clean
5. ☐ `php bin/console lint:twig templates/` = all valid
6. ☐ `php bin/phpunit` = green (full suite)
7. ☐ `python3 scripts/quality/check_translation_issues.py` = no new issues
8. ☐ Dokumentation aktualisiert (CLAUDE.md + ggf. docs/{feature}.md)
9. ☐ MEMORY.md ergänzt falls neuer Pattern
10. ☐ Migration auf Test-DB erfolgreich (`doctrine:migrations:migrate --env=test`)
11. ☐ AlvaHint-Rule angelegt falls Hint-relevant (z.B. "kein SSO konfiguriert")
12. ☐ Aurora-v4-Compliance: keine Bootstrap-Utilities auf .card / .card-header
13. ☐ Multi-Tenant-Smoke-Test: Tenant-Isolation gegen Cross-Tenant-Leak getestet
14. ☐ Module-Gating-Smoke-Test: Modul-aus → Routes redirecten
15. ☐ HMAC-Audit-Chain-Smoke-Test: alle definierten Events landen im Trail

---

## Quantifizierung Gesamtaufwand [v3-NEW]

Grobe Hochrechnung Sprints (1 Sprint ≈ 1 Woche Solo-Dev):

| Phase | Sprints | Bemerkung |
|---|---|---|
| Sprint 0 (CC1-4) | 1 | Foundations, blockiert nichts danach |
| F1 SSO | 2 | Wave 1 + Wave 2 |
| F2 Bulk-Import | 2 | Wave 1 + Wave 2 (Asset+Supplier+Control, dann Risk+BP) |
| F4 Evidence-Cascade | 1 | klein, Cascade-Logik selbst |
| F11 FTE-Dashboard | 1 | gefolgt von F4 (nutzt dessen Reuse-Stats) |
| F3 Notifications | 2 | Wave 1 (Foundation) + Wave 2 (SLA-Timer) |
| F5b BSI/TISAX | 1 | parallel zu F3 W2 möglich |
| F10 Profile/Maturity | 2 | |
| F5 OSCAL | 1 | |
| F7 Field-RBAC | 2 | |
| F6 API-Bulk | 1-2 | |
| F8 Health-Check | 0.5 | |
| F9 i18n | 1 + ongoing | |
| **Total Solo-Dev** | **~17-19 Sprints** (~4-5 Monate) | |
| **Mit Subagent-Driven-Dev parallelisiert** | ~10-12 Wochen Wall-Time | |

---

## Letzte Notizen

- **Reihenfolge der Migrations** beachten — DocumentVersion-Migration (F4)
  muss vor F11-FTE-Tracking laufen (FTE referenziert Document-Reuse).
- **Existing Skelette nutzen** — F1 SSO-Services existieren als Stubs
  (`src/Service/Sso/*`) mit niedrigem Coverage. Nicht neu schreiben,
  vervollständigen.
- **Memory `feedback_no_competitor_names`** — UI-Strings nur Standards.
  Plan-Doc darf Competitor erwähnen für Begründung, Code/Translations nicht.
- **Memory `feedback_targeted_tests`** — pro Feature nur betroffene Tests
  laufen lassen während Implementation, Full-Suite am Sprint-Ende.
- **Memory `feedback_migration_consolidation`** — Pattern-Rollouts
  EINE Migration in Final-Task, nicht pro Entity.
- **Memory `feedback_migration_savepoint`** — JEDE Migration mit DDL
  braucht `isTransactional()=false`.
- **Memory `feedback_release_workflow`** — Releases via release-please
  weekly. Plan-Features fließen via `feat(scope):` Commits in CHANGELOG.
