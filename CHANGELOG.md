# Changelog

Alle wesentlichen Aenderungen an diesem Projekt werden in dieser Datei dokumentiert.
Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

## [Unreleased] — feat/mris-integration → v3.2.0 (Tag wartet auf Aurora-Finalisierung)

### Headline-Feature: MRIS-Integration v1.5 — Gen-AI-Bedrohungslage im ISMS

Out-of-the-box-MRIS-Klassifikation aller 93 ISO-Annex-A-Controls + 13 Mythos-
Härtungs-Controls (MHC) als zweite Control-Schicht im Statement of Applicability.
Macht Gen-AI-getriebene Wirksamkeitsverluste bestehender Controls sichtbar und
schließt sie über einen priorisierten Zusatzkatalog.

**Wirtschaftlicher Hebel** (laut CM- + Senior-Consultant-Persona-Review):
- **Compliance-Manager intern:** ~11 FTE-Tage Quartal-Ersparnis bei 27001+NIS2-Bestand
- **Senior-Berater extern:** 22–34 Tage Ersparnis pro Kundenprojekt
- **Zusätzliche EU-AI-Act-Compliance:** AI-Agent-Inventar erfüllt gleichzeitig
  AI Act Art. 6/9-16 + ISO 42001 + MRIS MHC-13 + ISO 27001 A.5.16/A.8.27
  (eine Datenbasis, vier Frameworks)

### MRIS-Integration v1.5 (CC-BY-4.0-Ableitung Peddi 2026)

Komplette Integration des MRIS-Frameworks (Mythos-resistente Informationssicherheit
v1.5 von Richard Peddi, CC BY 4.0) in 5 Phasen + Plan-Vollerfüllung-Batch +
zusätzliche Erweiterungen.

**Plan-Erweiterungen (vom Ursprungs-Plan ausgenommen, aber priorisiert eingebaut):**

- **Mythos-Resilience-Indikator (MRI)** — aggregierter Score aus 5 gewichteten
  Dimensionen (Standfest 25 % / Reifegrad 30 % / Reibung-Inverse 20 % / Manual-KPIs
  15 % / AI-Doku 10 %). Prominent als „internes Management-Indikator" mit
  Audit-Disclaimer ausgewiesen — MRIS v1.5 selbst definiert kein Aggregat.
  Dekomposition pro Dimension immer sichtbar (kein Black-Box).

- **Auto-Re-Mapping bei MRIS-Versions-Updates** —
  `app:mris:migrate-version --from=v1.5 --to=v1.6 --apply` zeigt Diff
  (added/removed/renamed/maturity_changed), Soft-Delete via `dataSourceMapping`-
  JSON-Marker (`lifecycle_state=deprecated`), Audit-Log via `AuditLogger::logCustom`.
  Dry-Run als Default-Sicherung, `--apply` explizit erforderlich.

- **MRIS-Glossar** unter `/mris/glossar` — lädt `fixtures/mris/help-texts.yaml`
  und zeigt 20 Glossar-Einträge mit Definition + 9001-Analogie + Norm-Quelle.
  Sortier- und filterbar via Stimulus-Controller.

- **3 MRIS-Wizards:**
  - `/mris/wizard/pure-friction` — 5-Schritt-Routine für Reine-Reibung-Controls
  - `/mris/wizard/maturity-evidence` — Evidence-Checklist pro MHC (alle 13)
  - `/mris/wizard/ai-risk-class` — 12-Tools-Tabelle + 4-Step-Decision-Flow

- **AI-Agent-Form-Variante** — `AssetType` um 9 AI-Felder erweitert,
  `assetType=ai_agent` triggert dynamische Sichtbarkeit via
  `conditional_fields_controller`. Stimulus `asset_form_controller.js`
  schlägt Risikoklasse aus 12-Tools-Matrix vor (Provider-Match,
  case-insensitive, nur wenn Klasse leer).

- **Branchen-Baseline-UI** unter `/mris/baselines` — Card-Grid mit Anwenden-Button,
  Dry-Run-Vorschau, ROLE_MANAGER + CSRF.

- **Tenant-Settings-UI** für `mris_kpis_enabled` — Checkbox in
  `admin/tenants/form.html.twig`, persistiert via Settings-Merge.

- **KPI-Trend-Sparklines** — `KpiSnapshotRepository::findRecentByTenant(90)`
  liefert Trend-Daten, Inline-SVG-Polylines an jeder auto-KPI-Tile.

- **Mega-Menu-Erweiterung** — MRIS-KPIs + AI-Agent-Inventar +
  MRIS-Baselines + MRIS-Glossar im Compliance-Panel.



**Neue Module:**

- **MRIS-Library** (Phase 1): ComplianceFramework `MRIS-v1.5` mit 13 MHCs +
  Forward/Reverse-Mappings auf ISO 27001:2022 (44 Pairs je Richtung, 100 % Reciprocity).
- **Annex-A-Klassifikation** (Phase 1): 4 Kategorien (Standfest/Degradiert/Reibung/
  Nicht-betroffen) auf allen 93 ISO-Annex-A-Controls (S=29/T=37/R=4/N=23).
  Schema-Migration + Seed-CSV + Console-Command `app:mris:seed-classification`.
- **Reifegrad-Tracking** (Phase 2): MaturityService mit Soll/Ist-Delta-Berechnung,
  Audit-Log bei jeder Stufen-Änderung. UI: SoA-Filter + MRIS-Spalte + Reibung-
  Warning + MHC-Detail-Page mit Reifegrad-Tabelle + interaktivem Setzen.
- **Mythos-KPI-Block** (Phase 3): 8 KPIs aus MRIS Kap. 10.6 unter `/mris/kpis`.
  3 automatisch berechnet (MTTC, Phishing-MFA, Restore-Test), 5 manuell mit
  Eingabeformular. Tenant-Featureflag `mris_kpis_enabled`.
- **AI-Agent-Inventar** (Phase 4): Asset-Subtyp `ai_agent` mit 9 Pflichtfeldern
  für EU AI Act Art. 6/9-16 + ISO 42001 Annex A + MRIS MHC-13 + ISO 27001
  A.5.16/A.8.27. Inventar-Seite `/ai-agents` mit Compliance-Vollständigkeit
  pro Agent + Hochrisiko-Audit-Helfer.
- **Branchen-Baselines** (Phase 5): 4 vorkonfigurierte Soll-Stufen-Profile
  (KRITIS, Finance/DORA, Automotive/TISAX AL3, SaaS/CRA).
  Console-Command `app:mris:apply-baseline --tenant=X --baseline=NAME`.

**Persona-Reviews & Hilfetexte:**

- Junior-ISB-Persona-Befragung: 20 Verwirrungspunkte + 3 Top-Blocker
  (`docs/MRIS_HELP_TEXTS_JUNIOR_REQUEST.md`)
- Senior-Consultant-Persona lieferte `fixtures/mris/help-texts.yaml`:
  20 Items mit Tooltip + Inline-Help + Glossar (DE+EN, 9001-Analogien)
  + Pure-Friction-Decision-Routine + Reifegrad-Evidence-Checklist pro MHC
  + AI-Risiko-Entscheidungsmatrix für 12 typische Tools
- CM- + Senior-Consultant-Doppelreview als Plan-Validation
  (`docs/MRIS_INTEGRATION_PLAN.md`)

**Schema-Änderungen:**

- `control.mythos_resilience` VARCHAR(20) NULL + `mythos_flanking_mhcs` JSON NULL
  (Migration Version20260426132821)
- `compliance_requirement.maturity_current/target/reviewed_at`
  (Migration Version20260426145831)
- `asset` + 9 nullable AI-Agent-Felder
  (Migration Version20260426153940)
- Tenant-Settings: `settings.mris.kpis_enabled` + `settings.mris.manual_kpis[id]`

**KPI-Trendlinien:** `KpiSnapshotCommand` snapshot't 3 MRIS-auto-KPIs daily —
Trendlinien-Daten für künftige Sparklines.

**SoA-PDF-Export:** Neue Spalte „MRIS" mit Mythos-Kategorie + flankierenden
MHCs + CC-BY-4.0-Quellenangabe.

**Permissions:** ROLE_MANAGER auf Reifegrad-Set-Endpoint + Manual-KPI-Save.

**Navigation:** Mega-Menu-Compliance-Panel zeigt MRIS-KPIs + AI-Agent-Inventar.

**Tests:** 43+ neue PHPUnit-Test-Cases (Maturity 8 + KPI 8 + Classification 9 +
AI-Agent-Inventory 7 + Baseline 13). Alle grün.

**Quellenangabe (CC-BY-4.0) durchgängig:**

  Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5.
  Lizenz: Creative Commons Attribution 4.0 International (CC BY 4.0).
  Original-Whitepaper: `docs/MRIS- mythos-resistente infosec.pdf`

### Aurora v4 — flächendeckende Migration finalisiert (Wellen 1–8, ~3000 Site-Konvertierungen)

**Audit-Endstand** (gemessen via `scripts/quality/check_aurora_v4.sh`):

| Aurora-Komponente | Verwendungen | Bootstrap-Restbestand | Reduktion |
|---|---:|---:|---:|
| `fa-icon--*` | 729 | bi bi-* = 398 (alles generic UI) | -1700 ISMS-Domain-Icons |
| `fa-cyber-btn` | 356 | btn btn-* = 20 (setup/security/qr) | -658 |
| `fa-status-pill` | 56 | badge bg-* = 51 (Stimulus-controlled BC) | -87 |
| `fa-aurora-surface` | 55 | — | flächendeckend auf `<main>` |
| `fa-section` | 43 | — | via `_card`-Macro + Markup |
| `fa-alert` | 33 | alert alert-* = 15 (Modal-Forms) | -203 |
| `fa-empty-state` | 28 | — | mit Alva-Mood + CTA |
| `fa-rag-card` | 11 | — | Dashboard-RAG-Pattern |
| Hardcoded Hex in CSS | **0** | — | komplett auf Aurora-Tokens |

**Token-Layer komplettiert** (`fairy-aurora.css`):
- Tints: `--success-tint`, `--warning-tint`, `--danger-tint`, `--info-tint` (light + dark)
- RGB-Komponenten: `--primary-rgb`, `--accent-rgb`, `--success-rgb`, `--warning-rgb`, `--danger-rgb` (für rgba()-Komposition)
- Shadows: `--shadow-sm`, `--shadow-md`, `--shadow-lg`, `--shadow-up-sm`, `--shadow-up-md` (light + dark mit primary-Aura)
- Print-Tokens: `--print-fg`, `--print-bg`
- `--surface-translucent` für Overlay-on-Gradient

**Neue Aurora-Komponenten:**
- `.fa-rag-card` mit `--green/--amber/--red` Modifiern für RAG-Status-Kacheln
- `.fa-data-table` Aurora-themed Tabelle (ersetzt `.table.table-bordered`)
- `.fa-issue-list` semantisch statt `<ul><li class="text-warning">`-Pattern
- `.fa-trend` mit `--up/--up-bad/--down/--down-bad/--flat` für KPI-Trends
- `.fa-glyph-size-{sm,md,lg,xl}` Bootstrap-Icon-Größen-Utilities (kein Konflikt mit `.fa-icon` Mask-Base)
- `.progress-h-{4,5,10,18,24,25}` ergänzt (Reihe komplett: 4/5/6/8/10/18/20/24/25/30/40)

**Neue Macros:**
- `_fa_icon.html.twig` (Aurora-Mask-Icons, 77 ISMS-Domain-Icons)
- `_fa_kpi_card.html.twig` (Dashboard-KPI-Tile mit Trend-Indicator)
- `_fa_rag_card.html.twig` (R/A/G-Status-Tile)
- `_fa_btn.html.twig` (Aurora-Native-Button-Macro)
- `_fa_alert.html.twig` (Aurora-Native-Alert-Macro)
- 77 SVG-Icons in `assets/icons/` + `fairy-aurora-icons.css`

**`.fa-cyber-btn` Default-Sizing**: Base-Klasse hat jetzt padding/font-size/border-radius wie `--md` Default, plus `:where()`-Safety-Net (zero-specificity-defaults für variant-lose Buttons).

**TomSelect-Override mit `!important`**: Tom-Select-Lib lädt CSS via Stimulus-Controller-Import (Source-Order-Konflikt). Aurora-Tokens werden durchgesetzt.

**Bug-Fixes während Migration:**
- Twig-3 Macro-Scope (`_fa_empty_state`, `_fa_hero`): file-top `{% import '_alva' as alva %}` propagiert nicht in eigene macros → ersetzt durch `{% include %}`-Pattern + file-body in `_alva.html.twig`.
- Embed-Block-Scope: 50 Sites in 39 Templates wo `_fa_*`-Macro-Calls inside `{% block %}` von `{% embed %}` ohne block-Import → Imports inline ergänzt.
- `_fa_alert.body` mit Twig-im-String-Literal (132 Sites): String-literal Twig wird nicht interpoliert → konvertiert zu `{% embed %}` mit `{% block alert_body %}`.
- `fa-cyber-btn--block` (BS-Naming-Carry-Over) → `fa-cyber-btn--full` (Aurora-Spec-Name).
- 3 fehlende CSS-Klassen ergänzt: `.fa-status-pill--lg`, `.fa-alert--dismissible`, `.fa-alert--with-alva`.
- GDPR-Wizard `.gdpr-wizard .form-check-label`: `var(--text-primary, var(--surface))` (dead-token-fallback → unsichtbar) → `var(--fg)`.
- Aurora-Klassen-Audit-Skript `scripts/quality/check_aurora_v4.sh` als Living-Audit + Stylelint-Hex-Verbot via `declaration-property-value-disallowed-list`.

**Skip-Kategorien (intentional Bootstrap):**
- `templates/setup/`, `templates/setup_wizard/`, `templates/security/` (eigener Style)
- Email/PDF/QR/Print-Templates
- `.btn-close`, `.dropdown-toggle`, `.btn-link`-Patterns wo kein Aurora-Pendant
- Modal-Footer-Buttons in einigen komplexen Stimulus-Containern
- 5 TODO-Kommentare für PHP/JS-driven dynamic icon switches

**Welle-Übersicht:**
- Welle 1-3: Token-Layer + Macro-Bridges + Dashboard-Primitives
- Welle 4: Lead-Pages-Buttons (E4) + Alert-Migration (E5) + Hex-Cleanup (E6)
- Welle 5: Badges (J1) + Detail-Page-Buttons (J2) + Inline-Style-Cleanup (J3)
- Welle 6-7: Admin/Profile-Buttons (K1) + Alert-Round-2 (K2) + _macros/-Library (N1) + Restmodule (N2)
- Welle 8: Final btn-* (P1, 579 Buttons) + bi-* Domain-Audit (P2, 449 Icons)

## [3.1.0] - 2026-04-26

### Mapping-Quality-Library: 24 Files / 314 Pairs / 100% Reciprocity

Cross-Framework-Mapping-Qualität messbar gemacht. Komplette DE/EU-Coverage mit 12 reziproken Mapping-Paaren und CISO-Coverage-View.

**Schema (Migration 20260425145800):**
- `compliance_mapping` erweitert um `lifecycle_state`, `provenance_source/url`, `methodology_type/description`, `relationship` (equivalent/subset/superset/related/partial_overlap), `gap_warning`, `audit_evidence_hint`, `mqs_breakdown` (JSON)

**Services:**
- `MappingQualityScoreService` — MQS (0-100) aus 6 gewichteten Dimensionen: Provenance 25 % / Methodology 20 % / Confidence 15 % / Coverage 15 % / Bidirectional 15 % / Lifecycle 10 %
- `MappingValidatorService` — YAML-Library-Validation (Schema, Provenance-Pflicht, Methodology-Pflicht, Coverage-Warnung, Source/Target-Existenz)
- `MappingLifecycleService` — State-Machine draft → review → approved → published; 4-Augen-Review für approved, ROLE_CISO-Sign-Off für published; Audit-Log pro Transition
- `MappingLibraryLoader` — lädt `fixtures/library/mappings/*.yaml` mit Validation + MQS-Compute
- `ComplianceMappingRepository::coverageBetweenFrameworks()` und `reciprocityCoherence()`

**Console-Commands:**
- `app:mapping:check-reciprocity` — Bidirectional-Coherence-Audit (CI-fähig)
- `app:mapping:library:import` — YAML-Library-Import
- `app:mapping:library:smoke-test` — End-to-End-Test mit Stub-Frameworks und MQS-Übersicht

**Admin-UI `/admin/mapping-quality`:**
- Liste mit Filter (state, min_score), Stats-Cards, Recompute-Button
- Detail mit 6-Dimensionen-Aufschlüsselung
- Lifecycle-Transition-Buttons mit Reason-Feld + 4-Augen/CISO-Berechtigungs-Checks
- Coverage-View `/admin/mapping-quality/coverage/all` (CISO-Aggregat-Tabelle pro Framework-Paar mit Coverage % und Confidence-Verteilung)
- Mega-Menu-Eintrag

**24 Mapping-Library-Files (12 Forward/Reverse-Paare, 314 Pairs total):**

DE national:
- BSI IT-Grundschutz ↔ ISO 27001:2022 (15+15)
- BSI C5:2020 ↔ ISO 27001:2022 (15+15)
- BSI C5:2020 ↔ BSI IT-Grundschutz (15+15)
- BSI IT-Grundschutz ↔ NIS2 Art. 21 (11+10)
- KRITIS-DachG ↔ NIS2-UmsuCG (8+7)

EU regulatorisch:
- ISO 27001:2022 ↔ NIS2 Art. 21 (12+10)
- ISO 27001:2022 ↔ DORA (15+14)
- BAIT ↔ DORA (15+13)
- NIS2 Art. 21 ↔ DORA (10+8)
- ISO 27001:2022 ↔ TISAX VDA-ISA-6 (15+15)
- GDPR ↔ ISO 27701:2025 (16+16, ISO Annex D offiziell)
- EU AI Act ↔ ISO 42001 (10+9, lifecycle review)

**Reciprocity:** 24 von 24 Directions = 100 % Coherence. Forward/Reverse-Paare mirroring jede Source/Target-Beziehung mit invertierten Relationships (subset↔superset, equivalent↔equivalent, partial_overlap↔partial_overlap, related↔related).

**Top-MQS-Scores:** iso27701→gdpr 99.7, tisax→iso 99.0, nis2→bsi 97.3, nis2→dora 97.0, nis2→iso 95.9, iso→bsi 93.0, bsi→bsi-c5 91.7, iso→bsi-c5 91.7.

**Lifecycle-State:** 22× published, 2× review (eu-ai-act ↔ iso42001 noch reifend).

**Tests:** 27 neue Test-Cases (MQS-Service 6 + Validator 7 + Lifecycle 7 + Loader 7).

**Dokumentation:** `LIBRARY_FORMAT_VISION.md` + `MAPPING_QUALITY_VISION.md` + `MAPPING_QUALITY_ANALYSIS.md` + `QUICKSTART_MAPPING_QUALITY.md`.

## [3.0.0] - 2026-04-25

### Highlights

- FairyAurora v3.0 Design System mit Alva-Charakter (9 Moods)
- **FairyAurora v4.0 Rollout — Aurora-DNA app-weit** (Page-Header, Section, Feature-Card, Empty-State, Hero, Filter-Chip, Alva-Companion-Dock, Form-Theme, Bootstrap-Bridges fuer Buttons/Alerts/Badges/Pagination/Tom-Select)
- 23 Compliance-Frameworks mit Cross-Framework-Mapping und transitiver Compliance
- Konzernstruktur mit Holding/Tochter-Governance und Vererbung
- 171-Begriff ISMS-Glossar mit ISO 9001 Analogien
- OWASP 2025 Final Security Audit (Score: 7.55/10)
- Backup/Restore mit Verschluesselung, Tenant-Scoping, Best-Effort-Mode und Repair-Tool
- 0 fehlende Uebersetzungen in DE und EN (87 Domains)

### FairyAurora v4.0 — Onboarding-DNA app-weit

- 6 neue Aurora-Primitive (Twig-Macros): `fa-page-header`, `fa-section`, `fa-feature-card`, `fa-empty-state`, `fa-hero`, `fa-filter-chip`
- `fa-aurora-surface` Opt-in-Utility bringt die Setup-Wizard-Atmosphaere auf jede Modul-Seite (4 Varianten: default/subtle/hero/dots)
- **Phase-6-Rollout**: 48 Modul-Index-Seiten migriert auf `fa-page-header` + `fa-aurora-surface` Wrapper
- **Alva-Companion-Dock**: site-wide kontextueller Helper via `window.alvaBus` Event-System, 9 Moods, User-Setting fuer on/off/size/position, Hooks auf Upload + Turbo-Submit + Empty-State
- **fa-cyber-input Form-Theme** als Symfony-Default: monospace-uppercase Label ausserhalb Frame, 4-Corner-Tick-Marks, Focus-Glow. Login, Auth und alle FormBuilder-Forms visuell unified.
- **Aurora-Bridges** fuer Bootstrap-Utility-Klassen: `.btn.btn-*` / `.btn-outline-*` → fa-cyber-btn Visual, `.alert.alert-*` → fa-alert, `.badge.bg-*` → fa-status-pill, `.pagination`, `.dropdown-menu`, Tom-Select `.ts-*`. Templates unveraendert, Bootstrap-Klassen bekommen Aurora-Tokens.
- **Legacy-Hex-Cleanup**: 179 Hex-Hardcodes reduziert auf 3 (alle in SVG-Brand-Fills legitim)
- **Stylelint-Hex-Ban**: `npm run stylelint` blockt Hex in 14 Color-Props, Governance-CI-Hook vorbereitet
- **Living-Styleguide** `/dev/design-system` rendert alle 6 fa-* Komponenten + Alva-9-Mood-Matrix + 15 Token-Swatches mit Copy-Paste-Snippets (dev-env only)
- **Legacy-Cleanup**: 487 Zeilen redundante `.btn-*`/`.alert-*`/`.badge-*` Color-Overrides entfernt aus `app.css` / `dark-mode.css` / `components.css`. `dark-mode.css` reduziert auf echte Dark-Effekte (Icon-Glow), keine Color-Swaps mehr
- Neue Design-Tokens: `--pattern-opacity-*`, `--brand-gradient-soft/line`, `--alva-dock-offset-*`, `--alva-z`
- Disaster-Recovery-Runbook (DE) + Backup-Architecture-Reference (EN) in `docs/operations/`

### FairyAurora v3.0 Design System

- Komplett neues Token-basiertes CSS-Design-System (Aurora-Tokens)
- Alva-Charakter mit 9 Stimmungen (idle, thinking, happy, alert, ...)
- Dark Mode: 108+ Templates migriert, alle hardcoded Farben entfernt
- Bootstrap vor Aurora geladen (Cascade-Reihenfolge korrigiert)
- Card-Header-Farben normalisiert (keine kosmetischen bg-primary/success mehr)
- Chart.js Farben auf Aurora-Tokens
- WCAG 2.2 AA Kontraste durchgehend
- Print-Stylesheet mit neutralen Farben
- Responsive Breakpoint-Overrides
- 20+ neue Twig-Macros (Brand, CyberButton, StatusPill, KpiCard, Sparkline, ...)
- 4 neue Stimulus-Controller (aurora_alert, aurora_mode, aurora_banner, typewriter)
- Legacy-Bridge mappt 14 000 bestehende CSS-Zeilen automatisch auf Aurora-Tokens
- Self-hosted Fonts: Inter + JetBrains Mono (SIL OFL)
- Theme-Init 3-State (Light/Dark/System) mit localStorage-Persistenz

### Multi-Framework Compliance

- 23 Compliance-Frameworks im Admin-Katalog
- 8 Cross-Framework Seed-Kataloge (NIS2, DORA, TISAX, BSI, SOC2, C5:2026, GDPR<>ISO27001, GDPR<>ISO27701)
- Transitive Compliance-Berechnung (A->B->C)
- Mapping-Qualitaetsanalyse mit Konfidenzwerten
- Seed-Review-Queue mit Vier-Augen-Prinzip
- CSV-Import mit Dry-Run-Preview
- Mapping-Hub als zentraler Einstieg
- Data-Reuse-Hub mit FTE-Einsparungsberechnung
- Reuse-Heatmap zur Erkennung von Monokultur-Risiken
- Framework-Versions-Migration (z.B. C5:2020 -> C5:2026)
- Gap-Analyse (automatisiert, 5 Lueckentypen)
- Reifegrad-Portfolio (CMMI Level 0-5 pro Framework)
- Compliance-Vererbung mit Review-Queue und 4-Augen-Workflow
- Auto-Mapping-Vorschlaege (Jaccard-Token-Overlap, Klartext-Confidence)
- Audit-Paket-Export als ZIP mit Evidence-Dateien und SHA-256 im Audit-Log
- Bulk-Applicability-Editor mit Begruendungspflicht fuer N/A
- Multi-Framework-Audit (N Frameworks gleichzeitig abdecken)
- InternalAudit-Clone mit Title-Override
- Inverse-Coverage-Widget ("wo wird dieses Dokument referenziert?")
- Reuse-Trend-Chart mit dualer Y-Achse (FTE-Tage + Inheritance-Rate)
- 3-State Applicability-Badge (universal/conditional/voluntary)
- FrameworkApplicabilityService klassifiziert pro Tenant-Kontext

### Konzernstruktur (Holding / Tochtergesellschaften)

- ROLE_GROUP_CISO und ROLE_KONZERN_AUDITOR
- 5 Konfigurationsvererbungs-Resolver (Risk Approval, Incident SLA, KPI Thresholds, Password Policy, E-Mail Branding)
- Holding-Ceiling-Merge und Floor-Merge Strategien
- Konzern-Reports (7 Tabs: Uebersicht, Risk, Compliance, BCM, Incidents, Training, Audits)
- NIS2-Registrierungsmatrix fuer Konzernstruktur
- Compliance-Vererbung mit Review-Queue
- Sichtbarkeit-Steuerung (visibleToHolding)
- Cross-Tenant-Lieferantenverzeichnis mit LEI-Deduplizierung
- Incident-Cross-Posting mit Opt-out (vertrauliche Faelle)
- Holding-Policy-Vererbung (inheritable + overrideAllowed)
- Konzern-Audit-Programm mit Derivation fuer Toechter
- Tenant-NIS2-Felder (Klassifikation, Sektor, NACE, Registrierung)
- Tenant-Hierarchie-Sicherung gegen Zyklen und Self-Reference
- Baseline-Vererbung read-only mit Ahnenketten-Scan
- applyRecursive Propagation fuer Industry-Baselines
- HoldingTreeAccessTrait in 5 Votern (strikt downward-only)

### Glossar und Onboarding

- ISMS-Glossar von 20 auf 171 Begriffe erweitert (8 Kategorien)
- ISO 9001 Analogien fuer Umsteiger
- Suchfunktion und Kategorie-Filter
- Gefuehrte Touren pro Rolle (Junior, ISB, CISO, Auditor, Compliance Manager)
- Per-Step Icons und Resume-after-Navigation
- Hilfe-Menue im Mega-Menu (ISO 9001 Bruecke, Glossar, Tastenkuerzel)
- First-Steps Onboarding-Checkliste auf dem Dashboard
- Tour-Content-Override pro Tenant (4-Augen via SUPER_ADMIN)
- Admin-Report Tour-Completion mit User-Matrix und CSV-Export
- Rollenbasierter Tour-Launcher im User-Dropdown

### Backup und Disaster Recovery

- ZIP-Backup mit Schema-Version und Round-Trip-Test
- AES-256-GCM Verschluesselung mit Key-Derivation
- Tenant-scoped Backup und Restore (Multi-Tenant-Isolation)
- Best-Effort Restore mit Row-Level Failure Tracking
- Backup Repair Command (Salvage-Semantik)
- Backup Prune, Scheduled Create und Notifier Commands
- ManyToMany-Collection-Restore
- Disaster-Recovery-Runbook Dokumentation

### Setup Wizard

- 12-Schritte Wizard (Welcome -> Requirements -> DB -> Restore -> Admin -> Email -> Organisation -> Module -> Compliance -> Base Data -> Sample Data -> Complete)
- Framework-Auswahl mit Pflicht/Empfohlen/Optional-Klassifikation
- Branchen-Baselines (9 Starter-Pakete)
- Alva Busy-Indicator waehrend Datenimport
- Beispieldaten-Modul (Import + Entfernen)
- 8 Bug-Fixes fuer Step 8 Framework-Auswahl

### Incident-Modul

- Status-Filter-Bug behoben (Open-KPI zeigte immer 0)
- 5 Status-Karten statt 4 (alle Entity-Statuses abgedeckt)
- Hardcoded English Strings -> Uebersetzungsschluessel (~20 Strings)
- Emojis durch Bootstrap Icons ersetzt
- Escalation-Preview Stimulus Controller mit i18n
- NIS2 Compliance-Statuses in EN ergaenzt
- Dark-Mode-Support fuer Status-/Severity-Cards

### Internationalisierung

- 0 fehlende Uebersetzungen in DE und EN
- 87 Translation-Domains x 2 Sprachen = 174 YAML-Dateien
- Explizite Domain-Parameter in 7 Templates (~70 |trans Calls)
- Dynamische Translation-Keys gegen YAML verifiziert
- Consent-Enum-Aliases fuer Entity-Werte
- 36 Dashboard-KPI-Labels ergaenzt
- SoA-Message- und Compliance-Industry-Uebersetzungen

### Tenant-Konfiguration

- Risikomatrix-Labels im Translation-System
- Risk-Appetite Review-Buffer-Multiplier konfigurierbar
- Dokument-Klassifizierungs-Default per SystemSetting
- Lieferanten-Kritikalitaetslevel pro Tenant
- Incident-SLAs pro Tenant und Severity
- Genehmigungsschwellwerte pro Tenant
- Audit-Log-Retention editierbar im Admin-Panel
- E-Mail-Branding pro Tenant mit Holding-Fallback

### Security

- OWASP 2025 Final Audit-Script (Score 7.55/10)
- Dual-Report (2021 Legacy + 2025 Primary)
- Cookie samesite auf 'lax' korrigiert
- 11 Security Voters (von 5)
- MFA vollstaendig implementiert (TOTP)
- PasswordPolicyResolver mit Holding-Floor-Merge
- Schema-Reconcile Command fuer fehlgeschlagene Migrationen
- HMAC-SHA256-Chain fuer Audit-Log (NIS2 Art. 21.2 Tamper-Evidence)
- TOTP-Secret Base32-Encoding (RFC 6238, behebt MySQL-Insert-Fehler)

### Datenintegritaet

- Dynamische Orphan-Erkennung fuer alle Tenant-Entities
- Generische Reassign-Route fuer Orphan-Reparatur
- TenantFilter und confirm_hash Fixes
- DataIntegrityService: 15 Entity-Typen, Status-Validierung
- Audit-Freeze mit SHA-256-versiegeltem JSON-Payload (unveraenderlich)
- Schema-Update UI mit 2-Phasen-Flow und Backup-Pflicht-Checkbox

### KPI-System

- ISMS Health Score (Composite: Compliance 40% / Risk 25% / Incidents 20% / Assets 15%)
- Per-Framework Compliance-Prozent
- Risk-Appetite-Compliance, Residual Risk Exposure
- MTTR nach Severity (kritisch/hoch), korrigierter Divisor
- Control-Reuse-Ratio, Days Since Last Management Review
- Gewichtete Control-Compliance (implemented=1.0, partial=0.5)
- KpiThresholdConfig Entity + Admin-UI fuer tenant-spezifische Schwellen
- KpiSnapshot mit taegl. Retention + monatl. Aggregation
- Trend-Pfeile auf allen KPIs
- FTE-saved-KPI als Exec-Summary-Card auf Portfolio-Report

### Compliance-Kataloge

- 3 neue Frameworks: NIS2UmsuCG (15 Req), BDSG (12 Req), EU AI Act (10 Req)
- GDPR +15 Artikel (vollstaendig)
- BSI IT-Grundschutz Kompendium 2023: 1 868 Anforderungen, 121 Bausteine
- BSI Absicherungsstufen-Filter (basis/standard/kern) mit Anforderungstyp
- NIS2 Compliance Dashboard mit 11 Art.-21.2-Letters + Art.-23-Timer
- DORA Register-of-Information-Importer + Sub-Outsourcing-Editor
- TISAX Info-Classification-Schicht + Prototype-Protection-Flow (VDA Kap. 8)
- ISO 27001 Clauses 4-10 als ComplianceRequirements (28 Stueck)
- Industry-Baselines (4 Starter-Pakete: Production, Finance, KRITIS-Health, Generic)
- Seeder-Idempotenz fuer 7 Load-Commands mit --update Flag

### Risk- und Vulnerability-Management

- Incident <> Vulnerability ManyToMany mit idempotenter FK-Migration
- Risk.threatIntelligence und Risk.linkedVulnerability im FormType
- Schutzbedarfsvererbung (BSI 3.6 Maximumprinzip) via Asset.dependsOn
- AssetDependencyService (BFS-Traversierung, zyklensicher)
- RiskAggregationService (Portfolio-View, korrelierte Risiken, Heatmap)
- Incident <> Risk <> Vulnerability 1-Klick-Verknuepfung

### BCM

- BCMService (BIA-Analyse, Plan-Readiness, Exercise-Schedule)
- BC-Plan-Templates-Seeder mit 5 Standard-Szenarien
- BCM-Templates komplett uebersetzt
- Incident <> BusinessProcess Verknuepfung

### Form-UX

- Pattern A: Dual-State Owner fuer 7 Entities (Asset, BC-Plan, BusinessProcess, Control, Incident, Risk, Training)
- Pattern B: TomSelect fuer 6 Native-Multi-Selects
- Pattern C: Help-Texte fuer BCPlanType + 13 DORA/GDPR-Felder
- Pattern D: Progressive Disclosure mit Negation und Select-Trigger
- 90+ Felder mit ISO-Referenz-Help-Texten versehen
- CIA-Skala bei Asset-Labels inline sichtbar
- ISO-Reference-Label-Komponente (Control-ID + Klartext + Tooltip)

### Admin-Panel

- Mega-Menue umstrukturiert: Platform-Admin + Compliance-Admin
- Data-Repair Safety-Banner mit Audit-Log-Hinweis
- Dashboard-KPIs neu kuratiert (Framework-Ladezustand, ungepruefte Seed-Mappings)
- Dynamic Quick Actions (kontextabhaengig)
- Admin-scoped Command Palette (21 neue Commands per Cmd+P)
- Breadcrumb-Konsistenz in 12 Admin-Templates
- Beispieldaten-Modul (Import + Entfernen)
- Loader-Fixer idempotent Pattern
- Compliance-Policy-Einstellungen (13 Laufzeit-Parameter)
- Framework Loader-Fixer UI

### Navigation und UX

- Filter-State in URL (7 Index-Seiten, Links teilbar und bookmarkbar)
- Skeleton-Wrapper fuer Management-KPI-Widget (350 ms Perceived-Performance)
- Cmd+K-Chip im Global-Search-Button ab md-Viewport
- Bulk-Action-Bar konsolidiert
- Breadcrumb Home -> nav.home Translation

### Management-Reports

- Board One-Pager PDF (RAG-Status + Top-Risiken + Framework-Compliance)
- Management-Review-PDF mit Signatur-Block (eIDAS-Hinweis)
- Prototype-Protection PDF-Export (VDA Kap. 8)
- Delta-Assessment-Excel (3-Sheet-Layout)
- Portfolio-Report-Trend mit Drill-Down und echtem Delta

### CSS und Dark Mode

- Alle hardcoded `background: white` durch CSS-Variablen ersetzt (8 Dateien)
- Bootstrap-Subtle-Varianten fuer Alert-Farben
- bg-body / bg-body-secondary statt bg-white
- Fairy-Emoji durch Alva SVG ersetzt

### Dokumentation

- README komplett neu geschrieben (290 Zeilen, alle 23 Frameworks)
- 15 Dokumentationsdateien inhaltlich korrigiert
- ROADMAP-Metriken aktualisiert
- CLAUDE.md Domain-Liste auf 87 erweitert
- Disaster-Recovery-Runbook
- docs/ Cleanup: 115 -> 73 aktive Docs (38 geloescht, 21 archiviert)

### Tests

- 3 919 Tests, 10 827 Assertions, 0 Fehler, 0 Failures
- PHP 8.5 Deprecation-Fixes (failOnDeprecation=true, exit 0)
- Voter-Tests: 6 neue (Document x 3, Incident x 3)
- 21 Unit-Tests fuer Guided Tour (199 Assertions)

### Datenbank

- 47 Doctrine-Migrationen zu einer Squash-Migration konsolidiert
- Idempotente Helpers: safeAddColumn, safeAddFK, safeDropFK, safeModifyColumn
- Legacy-Migrationen archiviert in migrations/legacy/

---

## Fruehere Versionen

### [2.7.0] - 2026-04-17
- Phase 8J: 67+ Massnahmen ueber 7 Sprints (Standards Compliance und UX)
- 3 neue Frameworks (NIS2UmsuCG, BDSG, EU AI Act), GDPR/NIST/GxP erweitert
- DataSubjectRequest Entity (GDPR Art. 15-22), ElementaryThreat (BSI 200-3)
- First Steps Checklist, ISO 9001 Bridge Page, ISMS Glossar (20 Begriffe)
- KPI-Berechnungen korrigiert (MTTR, Control-Compliance, Risk-Treatment-Rate)

### [2.6.0] - 2025-12-20
- PWA Advanced Features: Push Notifications, Background Sync, Share Target API
- Service Worker mit IndexedDB-basierter Offline-Queue
- Web App Manifest mit File/Protocol Handlers

### [2.5.2] - 2025-12-19
- Role Help Component mit visueller Hierarchie-Kette
- Progressive Web App Basis (Manifest, Service Worker, Offline Page)
- Role Tooltips auf User-Form Checkboxen

### [2.5.1] - 2025-12-15
- DateTime/DateTimeImmutable Type-Mismatch in 5 Forms behoben
- PHPStan-Fixes in 6 Console Commands
- ComplianceController Variable-Initialisierung

### [2.5.0] - 2025-12-15
- Phase 7: Management Dashboard und Compliance Wizard
- Compliance-Wizards fuer ISO 27001, TISAX AL2/AL3, BSI IT-Grundschutz
- 8 Management-Reports mit PDF/Excel-Export
- DORA Compliance Dashboard

### [2.2.4] - 2025-12-10
- Internationalisierung: 56 Domain-Korrekturen, 5 Templates uebersetzt
- 21 hardcoded aria-labels durch trans() ersetzt
- Translation-Issues von 215 auf 70 reduziert

### [2.2.3] - 2025-12-09
- PDF/Email/Setup-Templates vollstaendig internationalisiert
- window.translations in base.html.twig fuer JavaScript i18n

### [2.2.2] - 2025-12-08
- CI/CD Pipeline Fixes (PHPUnit, Test-DB, Environment)
- Dependency Updates

### [2.2.1] - 2025-11-29
- ReviewReminderService + SendReviewRemindersCommand
- Risk Slider Component (interaktive 5x5 Matrix)

### [2.2.0] - 2025-11-29
- Automatische Review-Reminders (GDPR Art. 33, ISO 27001 Clause 6.1.3.d, ISO 22301)
- Interaktiver Risk Slider mit Presets und Farbkodierung
- Symfony 7.4 Kompatibilitaets-Fixes

### [2.1.1] - 2025-11-28
- Code Quality (Rector): PHP 8.4 und Symfony 7.4 Best Practices
- Internationalisierung ~95% abgeschlossen (49 Domains x 2 Sprachen)
- Doctrine Entity Mapping Fixes nach Rector-Renames

### [2.1.0] - 2025-11-27
- GDPR Breach Wizard mit 72h-Countdown
- Incident Escalation Workflows mit Auto-Escalation
- Approval Workflows (Risk Treatment Plan, Document)
- Auto-Form Component mit Bootstrap 5.3 Floating Labels

### [2.0.0] - 2025-11-26
- Komplettes UI/UX-Redesign: Mega-Menu, Breadcrumbs, Dark Mode
- 97 Translation-Domains, 3 290+ Keys (DE/EN)
- Bootstrap 5.3 Floating Labels, WCAG 2.1 AA

### [1.10.1] - 2025-11-21
- Hotfix: Admin-Login nach Database-Reset (Tenant-Deadlock behoben)
- CSRF-Token Auto-Clear nach composer update

### [1.10.0] - 2025-11-20
- 6 Risk-Management-Prioritaeten (Owner, Review, Acceptance, GDPR, Guidance, Monitoring)
- ProcessingActivity (VVT/ROPA Art. 30), DPIA, DataBreach (72h)
- Badge-Standardisierung (32 Tabellen), WCAG 2.1 AA Forms

### [1.7.1] - 2025-11-17
- Hotfix: FK-Constraints, Entity-ID-Preservation, DateTime-Fixes beim Restore

### [1.7.0] - 2025-11-17
- Backup/Restore-System Overhaul mit Setup-Wizard-Integration
- ManyToOne Relation Support, Unique-Constraint-Detection, 30+ Entity-Ordering

### [1.6.4] - 2025-11-16
- Compliance Framework CRUD, Workflow Builder (Drag-and-Drop)
- 16 neue Service-Tests (~5 000 Testzeilen)

### [1.6.2] - 2025-11-15
- ARM64/ARM Support (Multi-Architecture Docker Builds)

### [1.6.0] - 2025-11-15
- Multi-Tenancy System mit Corporate Structure
- Unified Admin Panel, MFA/TOTP, 100+ Permissions
- 7 deutsche Compliance-Frameworks (BSI, BaFin, DSGVO, KRITIS, NIS2, TISAX, DORA)

### [1.5.0] - 2025-11-07
- PDF/Excel Reports, REST API (30 Endpoints), Notification Scheduler
- Global Search (Cmd+K), Quick View, Dark Mode, Drag-and-Drop

### [1.4.0] - 2025-11-06
- CRUD und Workflows, Risk Assessment Matrix, 5 FormTypes, 30+ Templates

### [1.3.0] - 2025-11-05
- Authentication (Local, Azure OAuth/SAML), RBAC mit 5 Rollen, Audit Logging

### [1.2.0] - 2025-11-05
- BCM mit BIA, Multi-Framework Compliance (TISAX, DORA), Cross-Framework Mappings

### [1.1.0] - 2025-11-04
- Core ISMS: 9 Entities (Asset, Risk, Control, Incident, Audit, Training, ...)
- 93 ISO 27001:2022 Annex A Controls

### [1.0.0] - 2025-11-01
- Projekt-Initialisierung, Symfony 7.3 Setup
