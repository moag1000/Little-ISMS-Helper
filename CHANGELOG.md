# Changelog

All notable changes to Little ISMS Helper will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] — Migration Housekeeping

### Breaking

- **Migrationen konsolidiert**: 47 Doctrine-Migrationen seit v2.6.0 (Tag `97dd7ae5`) wurden
  in eine einzige Squash-Migration `Version20260424150000` zusammengefasst.
  Alte Files liegen archiviert in `migrations/legacy/` (nicht gelöscht, da
  `doctrine_migration_versions` noch die alten Versionen referenziert).
- **Upgrade-Pfad von v2.6.0 (fresh install)**:
  `php bin/console doctrine:migrations:migrate` führt die Squash-Migration durch —
  alle 47 Schema-States in einem idempotenten Lauf.
- **Existing installs** (die alle 47 bereits applied haben): Squash-Migration ist
  **no-op** — alle ALTER/CREATE-Statements prüfen via `information_schema` ob
  Spalten/Tables/FKs existieren, bevor sie SQL absetzen. Kein PREPARE/EXECUTE-Pattern.
- **Rollback**: Automatischer Rollback via `down()` ist nicht unterstützt.
  Restore from backup oder Reapply der einzelnen Migrationen aus `migrations/legacy/`.

### Technical

- `migrations/Version20260424150000.php`: konsolidierte Squash-Migration (470+ Zeilen),
  Sections: CREATE TABLE IF NOT EXISTS → safeAddColumn() → Daten-Backfills → safeAddFK().
- `migrations/legacy/`: 47 archivierte Migrations-Files (Version20260417173835 bis Version20260423130000).
- Helpers `safeAddColumn`, `safeAddFK`, `safeDropFK`, `safeModifyColumn` ersetzen
  das fragile PREPARE/EXECUTE-Pattern aus den alten Migrations.

### FairyAurora v3.0 Design System

- Complete CSS migration from legacy tokens to Aurora token system across all templates
- Dark mode deep-fixes across 108+ templates (hardcoded `background: white` replaced with CSS variables in 8 CSS files)
- Bootstrap loaded before Aurora to fix cascade order
- Card-header color normalization: removed cosmetic `bg-primary`/`bg-success` overrides
- Alva character face visibility fix in dark mode
- Text contrast fixes for `text-muted` in subtle backgrounds
- App sidebar + mega-menu with gradient backdrop on Aurora tokens
- Chart.js colors bound to Aurora CSS custom properties
- Guided tour per-step icons + resume-after-navigation support
- WCAG contrast improvements throughout (incident status/severity cards, Bootstrap subtle alert variants)

### Setup Wizard Stabilization

- 8 bug fixes for Step 8 framework selection (double-toggle, checkbox submit, toggle state persistence)
- Alva busy indicator during framework loading
- Missing i18n keys added for wizard translation domain
- Sample data import now sets tenant from wizard context
- Mandatory frameworks pre-selected by default in Step 8

### Holding & Corporate Governance (Phase 8M / 9)

- New roles: `ROLE_GROUP_CISO` and `ROLE_KONZERN_AUDITOR`
- 5 Holding-Inheritance config resolvers: Risk Approval, Incident SLA, KPI Thresholds, Password Policy, E-Mail Branding
- Tenant-scoped configuration with holding ceiling/floor merge strategy

### Backup & Disaster Recovery

- ZIP backup format with files, schema version metadata, and round-trip integrity test
- Tenant-scoped backup + restore for multi-tenant isolation
- Best-effort restore mode with row-level failure tracking
- `app:backup:repair` command (salvage-what-you-can for corrupt backups)
- `app:backup:prune`, `app:backup:scheduled-create`, `app:backup:notify` commands
- Disaster recovery runbook documentation
- ManyToMany collection restore fix

### Data Integrity & Repair

- Dynamic orphan detection for ALL tenant entities
- Generic reassign route for orphan repair
- TenantFilter + `confirm_hash` fixes for data repair safety
- `app:schema:reconcile` command for silent-failed migration detection

### Glossary Expansion (20 to 171 terms)

- Batch 1: 38 terms from 4 personas (Junior, CISO, CM, Consultant)
- Batch 2: 39 terms from ISMS + BCM specialists
- Batch 3: 33 terms from DPO + Risk specialists
- Final batch: 41 terms from all 6 specialist daily-work audits
- 8 categories: basics, risk, compliance, bcm, privacy, operations, governance, technical
- ISO 9001 analogies added on relevant terms

### Navigation & Help

- New "Help & Resources" mega-menu category with glossary, ISO 9001 bridge, and keyboard shortcuts
- Fairy emoji replaced with Alva SVG character across all templates
- Guided tour texts improved (raw URLs replaced with menu references)

### Translation & i18n

- 0 missing translations in DE and EN (verified by `debug:translation`)
- Explicit translation domains added to 7 templates (~70 `|trans` calls)
- Dynamic translation keys verified against YAML (consent enum aliases added)
- 36 missing dashboard KPI labels added
- SoA message translations added
- Compliance industry enum translations restructured

### Dark Mode & CSS

- All hardcoded `background: white` replaced with CSS variables across 8 CSS files
- Incident status/severity cards dark-mode compatible
- Bootstrap subtle variants for alert colors in dark mode

### Incident Module UX

- Critical bug fix: status filter mismatch (open KPI showed 0)
- Status overview cards: 4 to 5 cards matching entity statuses
- ~20 hardcoded English strings converted to translation keys
- Emojis replaced with Bootstrap Icons
- Stimulus escalation preview controller fully i18n-enabled
- NIS2 compliance statuses added to EN translations

### Tenant Configuration (Phase 8L / 8QW)

- Risk matrix labels in translation system
- Risk appetite review-buffer-multiplier configurable per tenant
- Document classification default via SystemSetting
- Supplier criticality level configurable per tenant
- Incident SLAs per tenant + severity configurable
- Approval thresholds per tenant configurable
- Audit log retention editable via Admin UI
- E-mail branding per tenant with holding fallback

### Admin Panel

- Sample data module (import + remove)
- Loader-Fixer idempotent pattern for framework catalog management

### Documentation Metrics Update

- All metrics in CLAUDE.md, README.md, ROADMAP.md, CHANGELOG.md updated to Apr 2026 actuals
- Entities 73, Controllers 104, Services 121, Commands 77, Templates 487, Translations 162 (81 domains), Tests 3919, LOC 167k

---

## [3.0.0] — 2026-04-21 🌸 FairyAurora Design-Reset

**Major Design-Reset**: komplette Migration vom "Cyberpunk-Fairy"-Theme
auf die **FairyAurora-Palette** (Cyan #0284c7 + Violett #7c3aed, Light + Dark
gleichrangig). Neue Fee **Alva** als Logo und Begleit-Charakter mit 9 Moods.
Plan: `.claude/FAIRY_AURORA_PLAN.md` (1000+ Zeilen).

### 🎨 Added — Aurora-Token-Layer

- Design-Tokens (`assets/styles/fairy-aurora.css`): Light + Dark + System
  Palette, Timing-Tokens (`--t-instant/fast/base/slow/magic`), Radius,
  Brand-Gradient, Alva-Vars.
- Legacy-Bridge (`fairy-aurora-bridge.css`): mappt 14 000 bestehende
  Legacy-CSS-Zeilen auf Aurora-Tokens automatisch.
- Self-hosted Fonts: Inter (400/500/600/700) + JetBrains Mono (400/500/600)
  unter `public/fonts/`, SIL OFL.
- Theme-Init-Script + Mode-Switch-UI 3-state (Light/Dark/System) via
  `aurora_mode_controller.js` + `_mode_switch.html.twig`, Persist in
  `localStorage['fa-theme']`.

### 🧚 Added — Alva Character (9 Moods)

- Logo (`public/logo.svg`): Alva mit subtil-breathing, CSS-Custom-Properties
  (Light/Dark auto), `prefers-reduced-motion` respect.
- Favicon (multi-size ICO) + PWA-Icons 72-512 + Apple-Touch 180 +
  OG-Image 1200×630 + Email-Logo.
- Character-Macro (`_alva.html.twig`): 9 Moods
  (idle/happy/thinking/focused/working/scanning/warning/celebrating/sleeping),
  inline-SVG, 7 Body-Bob-Keyframes, 5 Wing-Flap-Speeds.
- Server-Mood-Resolver (`AlvaMoodExtension`): Session-Flash →
  Session-Attribute → Nacht-Easter-Egg → idle. Twig-Global `alva_mood`.
- OnboardingFairy-Compound: Aura-Pulse + 4 Orbit-Dots + Mood-Wing-Tilt.

### 🧩 Added — Component-Library (20+ Aurora-Primitives)

Neue Twig-Macros unter `templates/_components/`:
`_brand`, `_cyber_button` (5 Varianten), `_cyber_input` (+select),
`_status_pill`, `_alert`, `_empty_state`, `_kpi_card`, `_sparkline`,
`_step_header`, `_check_row`, `_toggle_card`, `_nav_bar`,
`_tech_backdrop`, `_onboarding_fairy`, `_typewriter`, `_stepper`,
`_dropdown_panel`, `_filter_chips`, `_global_banner`,
`_confirmation_dialog`, `_accordion`, `_system_status`.

Plus 4 Stimulus-Controller (`aurora_alert`, `aurora_mode`, `aurora_banner`,
`typewriter`) und Chart-Theme-Modul (`assets/chart-theme.js`).

### 📱 Added — Shell + Responsive

- Sidebar 260 → 224 px Aurora-Surface; Topbar 80 → 52 px flat Aurora.
- User-Avatar Pill 36 px mit Brand-Gradient + Mono-Initialen.
- System-Status-Pill + Alva-Topbar-Mount in Topbar.
- Responsive-Layer (`fairy-aurora-responsive.css`): 4 Breakpoints
  (sm<640, md<768, lg<1024, xl≥1280), Sidebar off-canvas <lg, Touch-Targets
  44×44 <md, KPI-Reflow 4→2→1.

### 🧙 Added — Setup-Wizard (12-Step Flow)

- Neues Layout (`setup/_layout.html.twig`): 2-Col 480/flex mit TechBackdrop
  + Brand + OnboardingFairy + Typewriter + Phase-Indicator.
- Flow-Array (`src/Setup/SetupFlow.php`) mit 12 Steps DE+EN.
- Alle 12 Setup-Steps auf Aurora-Layout migriert.

### 🔐 Added — Auth-Flows

- Neues `base_auth.html.twig`: 2-Col Aurora-Layout mit
  auth_mood / auth_alva_line / auth_alva_sub Blocks.
- Login + MFA-Challenge komplett Aurora (CyberInput, CyberButton,
  OAuth-Buttons, `<details>`-Accordion).

### 📧 Added — Email-Template-Base

- `templates/emails/base.html.twig` (NEU) mit Aurora-Gradient-Header +
  neutral Light-Body + Inline-CSS (Mail-Client-Kompat).
- 3 broken-extends gefixt + 4 fehlende Templates erstellt.

### ⚠️ Added — Error-Pages (404/500/403)

Migriert auf `base_auth.html.twig` mit Alva-Mood-Mapping:
404→thinking, 500→warning (+ Request-ID mono), 403→sleeping.

### 🖨️ Added — Print-Styles

`@media print` Neutral-Light-Fallback: Shell hidden, Links mit
URL in Klammern, Page-Break-Utilities.

### ♿ Added — A11y-Compliance (WCAG 2.2 AA)

- Neue Tokens für WCAG-safe Button-Text-Kontraste
  (`--primary-strong`, `--*-strong`, `--on-primary/accent/...` Inverse-Flip).
- Audit-Runner (`.claude/fairy-aurora/a11y_contrast_audit.py`) —
  18/18 kritische Kombinationen bestehen WCAG 2.2 AA.
- Alva aria-labels für alle 9 Moods (DE + EN).
- Alle Animationen respektieren `prefers-reduced-motion`.

### ⚡ Changed — Performance + Foundation

- Service-Worker `CACHE_VERSION` → `v3.0.0-fa`, 7 Font-Files +
  favicon.ico + apple-touch-icon in STATIC_CACHE.
- Font-Preload: inter-500 + inter-600 above-the-fold.
- `composer.json`: neues `version`-Feld als Single-Source-of-Truth.
- Flash-Bridge: Symfony app.flashes → Aurora-Alerts mit Alva-Avatar.
- Toast-Controller-API 1:1 kompatibel, rendert jetzt Aurora-Alert-DOM.
- `manifest.json`: theme_color #06b6d4 → #0284c7.

### 🗑️ Removed

- Legacy `_theme_toggle.html.twig` + `theme_controller.js` (ersetzt durch
  Aurora 3-state Mode-Switch).
- 17 Legacy-Logos archiviert nach `public/archive/legacy-logos/`.

### 📊 Gesamt-Statistik

- ~40 FTE-d Umfang, ~25 Commits über 11 FA-Phasen.
- 20+ neue Twig-Macros, 4 neue Stimulus-Controller, 4 Twig-Extensions.
- 7 CSS-Files unter `assets/styles/fairy-aurora-*.css`.
- Zero Breaking-Changes dank Bridge-Layer.
- 521 Twig-Files Lint OK, Container-Lint OK, A11y-Audit 18/18 AA.

### 🎯 Migration-Reihenfolge

FA-0 Brand → FA-1 Theme → FA-2 Components → FA-3 Shell → FA-4 Alva →
FA-5 Setup-Wizard → FA-6 Auth → FA-8 Responsive → FA-9 Charts+Edge →
FA-10 Email+Error+Print → FA-7 Universal-Sweep.

---

## [Unreleased]

### ✨ Added

#### Sprint 13 — Guided Tour (Phase 8G) (2026-04-21)

Rollenbasierte First-Login-Tour mit eigenem Stimulus-Controller
(keine externe Library), Cyberpunk-Fee-Theme (cyan-pink-purple
Gradient, fairy-sparkle-Animations). Expertenrunde UX + 7 Personas +
5 Domain-Specialists siehe `.claude/GUIDED_TOUR_PLAN.md`.

**Phase A — Core (`9cb96800`)**
- `User.completedTours` JSON + Migration + Entity-Helpers.
- `GuidedTourService` mit 6 rollenbasierten Step-Listen
  (junior=7, cm=5, ciso=4, isb=5, risk_owner=2, auditor=3) +
  Role-Auto-Detect.
- `GuidedTourController` (steps / complete / reset).
- Custom Stimulus-Controller mit Popover + Backdrop + Highlight +
  Keyboard-Nav (←/→/ESC) + Focus-Trap + aria-live + prefers-
  reduced-motion + LocalStorage-Resume + Mobile-Fallback < 768 px.
- Cyberpunk-Fee-CSS (Gradient-Border, fairy-pulse + fairy-shimmer,
  3-Farb-Highlight-Pulse). Vollständige DE+EN-Translations.
- `HomeController::dashboard` schlägt rollen-passende Tour per Banner
  vor.

**Phase B — Launcher + Tests + Admin-Report + Modul-Addons
(`abae4a30`, `c9da4898`)**
- User-Dropdown „Tour neu starten" mit sparkle-Icon — global
  erreichbar (Mount auf base.html.twig gehoben).
- 21 Unit-Tests (Service + Entity). 199 Assertions, alle grün.
- `/admin/tours/completion` Admin-Report mit User × Tour-Matrix,
  Stats, Filter, CSV-Export, Reset-Action — **ISO 27001 A.6.3
  Awareness-Training-Audit-Nachweis**.
- Modul-bedingte Zusatz-Stopps (BSI-Grundschutz / GDPR / BCM) an
  Junior- und ISB-Touren.

**Phase C — Content-Config + Help-Handouts
(`8c04d1a1`, `5ef94e36`)**
- `GuidedTourStepOverride` Entity + Migration + Repository.
  Auflösungs-Reihenfolge: tenant-spezifisch > global (tenant=null) >
  Translation-Default.
- `/admin/tours/content` + `/{tourId}` Admin-UI mit DE+EN-Edit pro
  Step, Default als Placeholder. Bulk-Save + Reset-Tour.
  SUPER_ADMIN kann System-Default-Override setzen.
- `/help/tour` + `/help/tour/{role}` statische Pages mit
  Cyberpunk-Fee-Step-Number-Badges. `@media print`-CSS für
  Handout-Druck inkl. Unterschriftsfeld — **ersetzt geplanten PDF-
  Export zu 25 % Aufwand**.

**Neue Mega-Menü-Einträge unter Platform-Config:**
Tour-Einweisungsstand, Tour-Texte anpassen.

**Realisiert:** ~9,5 FTE-d, 28 Files.

#### Admin-Panel-Review: Sprint 10/11/12 (2026-04-21)

Drei-Personas-Walkthrough durchs Admin-Panel (Consultant / Junior-
Implementer / UX-Specialist) ergab sieben konsolidierte Findings.
Nach Impact umgesetzt in drei Sprints:

**Sprint 10 — Kritisch**
- **S10-1 Menu-Restructuring** (`8420a877`): Mega-Menü 7 flache
  Sektionen → 2 Oberdomänen „Platform-Admin" (Users & Access /
  Configuration / Data & Operations) + „Compliance-Admin" (Frameworks
  / Imports). Hidden Routes `KPI-Thresholds` und `Tags` sind jetzt
  sichtbar (vorher Dead-Routes). Compliance-Import-Wizard erhält
  direkten Menü-Eintrag.
- **S10-2 Data-Repair Safety-Banner** (`d5d888ef`): Roter
  Warn-Header oben auf /admin/data-repair/ mit 3-Punkte-Liste
  (Audit-Log-Trail, Backup-Hinweis, Confirm-Dialog-Betonung) + zwei
  Escape-Aktionen (Backup prüfen, Audit-Log ansehen). Junior-Sorge
  „Titel klingt gefährlich" adressiert ohne Dry-Run-Rewrite.

**Sprint 11 — Wichtig**
- **Dashboard-KPIs neu kuratiert + Dynamic Quick Actions** (`f3d10817`):
  - 2 der 4 KPI-Cards ersetzt: *Database Size (MB)* → *Framework-
    Ladezustand* („18 / 23"). *Total Records* → *Ungeprüfte Seed-
    Mappings*. Beide semantisch gefärbt (rot bei action required).
  - Framework-Card Footer-CTA „Verwalten" → /admin/compliance.
    Seed-Mapping-Card Footer-CTA „Jetzt prüfen" → Seed-Review-Queue
    (nur wenn >0).
  - Quick Actions sind jetzt kontextabhängig: „N Seed-Mappings prüfen"
    / „N Frameworks fehlen" / „N inaktive Benutzer" erscheinen nur
    wenn relevant. Standard-Shortcuts bleiben unten.
  - Controller injiziert optional 4 Repos (framework, mapping,
    workflow-instance, framework-loader) — null-safe für Tests.

**Sprint 12 — Schön**
- **S12-1 Breadcrumb-Konsistenz** (`86a2b151`): 12 Admin-Templates
  ergänzen fehlende Breadcrumbs. Zwei Patterns harmonisiert:
  `admin/layout.html.twig`-extender nutzen `{% block breadcrumb_items %}`,
  `base.html.twig`-extender includen `_components/_breadcrumb.html.twig`.
  Betroffen: compliance/statistics, modules/details + graph,
  compliance_policy, loader_fixer-index + result, tag-index + new +
  edit, kpi_threshold-index + new + edit.
- **S12-2 Admin-scoped Command Palette** (`a418e7e5`): ⌘P
  gewinnt 21 neue Admin-Commands (Tenants, Roles, Permissions,
  Sessions, MFA, Settings, KPI-Thresholds, Tags, Modules, Backup,
  Export, Import, Data-Repair, Health, Licensing, Compliance-Catalog,
  Policy, Loader-Fixer, Compliance-Import, Import-History) in der
  Administration-Kategorie. Dead-Routes damit per Suche erreichbar.
  Stimulus-Controller erkennt `/admin/*` und sortiert Admin-Commands
  nach oben — Junior muss nicht „admin" tippen, um Ziel zu finden.

#### Seed-Kataloge Erweiterung + Admin-Applicability + Wizard-Integration (2026-04-21)

Vier-teiliges Doppel-Sprint-Paket zur Verbesserung der Framework-
Onboarding-Experience. Ergebnis: 23 Frameworks im Admin-Katalog
sichtbar (vorher 15), 8 kuratierte Seed-Kataloge verfügbar (vorher 3),
3-Bucket-Applicability statt binärer Pflicht-Anzeige.

**Sprint 7 — 4 neue Seed-Kataloge (`3edf5160`)**
- **NIS2 ↔ ISO 27001**: 79 Paare (61 neu), ENISA + BSI + Impl. Reg.
  (EU) 2024/2690 Anhang I. Art. 20-25 komplett, Art. 21.2.a-j +
  Third-Party + Reporting.
- **DORA ↔ ISO 27001**: 68 Paare (57 neu), EBA Guidelines +
  ENISA DORA Tech. Guidance + BaFin FAQ. Art. 5-33 mit Fokus
  ICT-Risk / Incident-Mgmt / Testing / Third-Party.
- **TISAX ↔ ISO 27001**: 98 Paare (57 neu), VDA-ISA → 27001:2022
  Annex A. Volle ACC/BCM/CMP/COM/CRY/DEV/HRS/INC/INF/MOB/OPS/PHY/
  PROT/SUP-Struktur, meist full=100%.
- **GDPR ↔ ISO 27001**: 40 Paare (40 neu), EDPB Guidelines +
  ISO/IEC 27701. Art. 5 Grundsätze, 24/25/28/30/32-37/44-46
  Schlüsselartikel.

**Sprint 8 — GDPR ↔ ISO 27701 (PIMS) Seed (`a0b4e5a8`)**
- 60 Paare (48 neu), ISO 27701:2019 Annex D GDPR-Mapping.
- Deutlich höhere Treffer-Dichte als GDPR↔ISO 27001 weil 27701
  die DSGVO operationalisiert (Controller-Obligations A.7.x +
  Processor-Obligations B.8.x + dedizierte GDPR-Interop-Sektion).
- Seed-UI zeigt jetzt 8 Karten statt 3.

**Admin-Katalog-Erweiterung (`54889154`)**
- Acht Frameworks im Katalog nachgetragen (waren in der DB, aber
  nicht in `ComplianceFrameworkLoaderService::getAvailableFrameworks()`):
  SOC2, NIST-CSF, CIS-CONTROLS, ISO-22301, ISO27005, BDSG,
  EU-AI-ACT, NIS2UMSUCG. `loadFramework()`-Match-Statement
  entsprechend ergänzt — Reload/Delete via UI funktioniert jetzt.

**Option B — 3-State Applicability-Badge (`f24278c1`)**
- Junior-Walkthrough-Feedback: *"DORA `Pflicht: Ja` ist irreführend —
  nur Finanzdienstleister betroffen"*. Binäres Mandatory-Flag ersetzt
  durch drei Zustände:
  - 🔴 `universal`: rechtliche Pflicht ohne Branche/Größe-Filter
    (nur GDPR im EU-Kontext)
  - 🟡 `conditional`: branchen-/größen-/tätigkeits-abhängig
    (TISAX, DORA, NIS2, NIS2UmsuCG, BSI IT-Grundschutz, KRITIS,
    KRITIS-Health, DIGAV, TKG, GXP, BDSG, EU AI Act)
  - ⚪ `voluntary`: Markt-Standard ohne Gesetzescharakter
    (ISO 27001/27005/27701/22301, BSI-C5, SOC2, NIST-CSF, CIS-Controls)
- Pro conditional-Framework ein Trigger-Text (z.B. DORA:
  *"Finanzdienstleister nach DORA Art. 2"*), im Admin-Katalog unter
  jeder Karte als alert-warning sichtbar.

**Sprint 9 — Applicability-Service + Wizard-Integration (`a770c9b8`)**
- Neuer `FrameworkApplicabilityService` klassifiziert pro Tenant-
  Kontext (Branche, Größe, Land, AI-High-Risk-Flag) jedes Framework
  in einen von drei Buckets: `mandatory` / `recommended` / `optional`.
- Setup-Wizard Step 8 zeigt Frameworks nicht mehr flach, sondern
  in drei farbkodierten Sektionen mit Pre-Select von mandatory +
  recommended. Junge Implementer sehen *was* empfohlen wird UND
  *warum*.
- Beispiele: Finanzdienstleister DE → DORA + NIS2 + GDPR + BDSG
  mandatory. Automotive + 120 MA + DE → TISAX + NIS2 mandatory.
  8-Mann-Marketing-Startup AT → nur ISO27001 + GDPR mandatory.

**BSI-Grundschutz-Check Macro-Fix (`b9228654`)**
- Template-Bug: `{% import _self as helpers %}` im äußeren Block
  war in `{% embed %}`-Scopes nicht sichtbar. `_self` innerhalb
  eines Embeds verweist auf das eingebettete `_card.html.twig`.
  Fix: In 3 betroffenen `card_body`-Blöcken explizit
  `{% import 'bsi_grundschutz_check/index.html.twig' as helpers %}`.

#### CM-Data-Reuse-Plan Sprint 1–3 (2026-04-20)

Umsetzung des `.claude/CM_DATA_REUSE_PLAN.md` über 12 Commits nach der
Consultant-Priorisierung. 22 FTE-Tage Invest → ~37 FTE-Tage/Jahr
Ersparnis beim CM-Alltag (~53 000 €/Jahr direkter Effekt).

**Sprint 1 — Cross-Framework-Foundation (`6ada67ba`, `d9f44caf`, `de579b12`)**
- **A4 Audit-Paket-Export** (`/audit-package/{framework}`): ZIP mit
  INDEX.csv + AUDIT_SUMMARY.pdf + per-Requirement-Ordner mit Evidence-
  Dateien + `*.MISSING.txt`-Stubs bei Daten-Gaps. SHA-256 im Audit-Log.
- **A2 Auto-Mapping-Vorschläge**: `MappingSuggestionService` mit Jaccard-
  Token-Overlap; Widget auf SoA-Control-Show mit 1-Klick-Accept + CSRF +
  Audit-Log-Herkunft `A2_auto_mapping_suggestion`.
- **B2 BSI ↔ ISO 27001 Seed-Mappings**: `app:seed-bsi-iso27001-mappings`
  mit 42 Mappings aus offizieller BSI-Cross-Reference-Tabelle.

**Sprint 2 — Cross-Norm-Visibility (`3e093b52`, `18ebe204`, `28fdd629`)**
- **B1 Transitive Coverage-Badge**: `TransitiveCoverageService` +
  `_transitive_coverage_badge.html.twig`. Dreht die Frage *"welche
  Controls treiben diese Coverage?"* in einen aufklappbaren `<details>`-
  Block mit direkten + transitiven Contributions, Formel pro Bucket.
- **A3 Consultant-Template-Importer**: Generischer CSV-Importer
  `app:import-cross-framework-mappings --source=X --target=Y`.
- **B6 Framework-Version-Migration**: `FrameworkVersionMigrator` +
  `app:migrate-framework-version --from=X --to=Y [--strategy=id|title|both]`.

**Sprint 3 — Ausbau (`edc0b534`, `d2798d7e`, `b3097d74`, `108da8ac`, `7824ab26`, `161636c0`)**
- **B5 SOC 2 + C5:2026 Seed-Mappings**: 38 SOC 2 ↔ ISO, 16 C5 ↔ ISO.
- **C2 CMMI-Reifegradmodell**: `FrameworkMaturityService` mit 5 Levels
  (Initial → Optimizing) + Maturity-Heatmap auf `/compliance/`.
- **C3 Bulk-Applicability-Editor**: Inline-Form auf SoA-Index mit
  pflichtiger Begründung für N/A-Marker + Audit-Log je Änderung.
- **B4 Multi-Framework-Audit**: `InternalAudit.additionalScopedFrameworks`
  M:M + Form-Multi-Select. Audit-Runs decken jetzt N Frameworks
  gleichzeitig ab.
- **A1 Inverse-Coverage-Widget**: `InverseCoverageService` + Partial
  auf Document-Show + Supplier-Show (*"wo wird dieses Dokument
  referenziert?"*).
- **C1 InternalAudit-Clone**: `InternalAuditCloner` + POST-Route
  `/audit/{id}/clone` mit Title-Override + Planned-Date-Feld.

#### CM-Junior-Consultant-Walkthrough Sprint 4–6 (2026-04-21)

Fortführung des CM-Plans aus Sicht eines Junior-Consultants, der zum
ersten Mal Reuse + Framework-Mapping sieht. 12 Items / 15 FTE-Tage auf
3 Sprints verteilt. Plan in `.claude/CM_JUNIOR_CONSULTANT_WALKTHROUGH.md`.

**Sprint 4 — HIGH (8 FTE-d)**
- **M3 Klartext-Confidence** (`83017e1b`): SoA-Mapping-Suggestion-Widget
  zeigt "Sehr hohe Text-Ähnlichkeit" + Tooltip statt Jaccard-Prozent —
  Junior versteht 85 % Confidence nicht instinktiv.
- **R3 Reuse-Trend-Chart** (`52c54eda`): Neue Entity `ReuseTrendSnapshot`
  + `app:reuse:capture-snapshot` (tägl. Cron) + Chart.js-Line-Chart auf
  Portfolio-Report mit dualer Y-Achse (FTE-Tage + Inheritance-Rate %).
- **R2 Home-Dashboard-FTE-KPI** (`aa1e1b8d`): Einzelne große FTE-Zahl auf
  `/dashboard` oberhalb der Management-KPIs, klickt durch auf Portfolio-
  Report — Board-reife Einzahl.
- **M2 Seed-1-Klick-UI** (`68f33377`): `/compliance/mapping/seeds`
  ersetzt drei CLI-Commands durch drei Karten mit Status-Badges
  (BSI/SOC 2/C5:2026 ↔ ISO). In-Process-Execution via `symfony/process`.
- **R1 Data-Reuse-Hub** (`00c8f28a`): First-Class-Route `/reuse` mit
  Hero-KPI (FTE-saved) + Stats-Bar + Top-10-Dokumente + Top-10-Lieferanten.
  9001-Brücke als alert-info. Mega-Menü-Eintrag.
- **M1 4-Step-Wizard** (`04d7cee5` + fix `328c93eb`): `/compliance/mapping/wizard`
  führt durch Framework-Paar → gefilterte Requirement-Dropdowns →
  Typ-Karten (full/partial/weak/exceeds mit Auto-Default-Prozent) →
  Rationale. Stimulus-Client-Filter auf `requirements_by_framework`-JSON.

**Sprint 5 — MEDIUM (5 FTE-d)**
- **R4 ISO-9001-Analogien** (`053aeea8`): Reusable Component
  `_components/_iso9001_analogy.html.twig` mit 5 variant-spezifischen
  Texten (mapping/seeds/quality/reuse/default), verlinkt auf Voll-Brücke
  `/help/iso9001-bridge`. Auf 4 Seiten eingebunden.
- **M4 Mapping-Hub** (`ce6d39fd`): `/compliance/mapping/hub` als
  Junior-Einstieg. KPI-Strip (Total / Unreviewed / Bidirectional /
  Frameworks) + 6 Einstiegs-Karten inkl. "Hier starten"-Badge am Wizard.
  Liste bleibt für gezielte Suche erreichbar.
- **R5 CSV-Import-UI** (`686d88af`): `/compliance/mapping/import`
  (ROLE_MANAGER) nutzt bestehenden `CrossFrameworkMappingImporter` mit
  Dry-Run-Preview, KPI-Strip, Warnings-Tabelle. Consultant-Tabellen
  ohne CLI verdau­bar.
- **M5 Version-Migrations-UI** (`2bb93bb0`): `/compliance/framework/{id}/migrate`
  kapselt `FrameworkVersionMigrator` — Strategie-Wahl (ID / Titel /
  Fallback), Dry-Run-Preview mit matched + unmatched + via-Badge,
  idempotent. Löst CLI-Only-Barriere für ISO 27001:2013 → 2022 /
  C5:2020 → 2026.

**Sprint 6 — LOW (2 FTE-d)**
- **M6 Seed-Review-Queue** (`f5118e39`): `/compliance/mapping/seed-review`
  listet alle maschinell erstellten Mappings (`verifiedBy` matches
  `app:seed-*`, `consultant_template_import`, `csv_import_ui*`,
  `mapping_wizard`, `app:migrate-framework-version`) mit
  `reviewStatus='unreviewed'`. Approve/Reject pro Zeile, Filter nach
  Herkunft. Audit-Antwort auf *"wer hat das Mapping gemacht?"*.
- **R6 Reuse-Heatmap** (`d9603404`): `/reuse/heatmap` rendert alle
  wiederverwendeten Dokumente + Lieferanten als farbige Kacheln in 5
  Buckets (grau → gelb → orange → rot) via normierter Ratio gegen
  Max-Wert. Thematisiert Monokultur-Risiko.

**Bug-Fix post-audit (`328c93eb`)**: M1-Wizard griff via `$fw->id`
direkt auf die private Eigenschaft der `ComplianceFramework`-Entity zu
(Commit `2a2bc51f` hatte die Property-Hooks entfernt). Umgestellt
auf `getId()`.

#### Audit-v2.2 Residual-Closure + BSI Kompendium 2023 vollständig (2026-04-20 / 04-21)

Letzte Nice-to-haves + BSI-Vollständigkeit aus Audit-v2.2 Residual-Liste.
Residual-Tool-Budget **3 → 0 FTE-Tage**.

- **Management-Review-PDF Signatur-Block** (`b685e63c`): 3-Zeilen-Tabelle
  (Top-Management / CISO / ISMS-Manager) mit vorbefülltem Reviewer-Name
  + Datum wenn `status=completed`. eIDAS-Hinweis.
- **Prototype-Protection PDF-Export** (`4af43750`): Route
  `/prototype-protection/{id}/pdf` mit Cover, Meta-Tabelle, 5 VDA-Kap.-
  8-Sektionen + Signatur-Block (Assessor + Approver).
- **BSI-Kompendium Extended-Seed** (`163f885b`): `app:load-bsi-kompendium-
  extended` mit 52 kuratierten Anforderungen aus 20 zusätzlichen
  Bausteinen (CON.11, OPS.1.2.3, OPS.2.2, APP.3.2/3.3/3.6/4.2/5.3,
  SYS.1.2.3/1.3/1.8/1.9/2.4/3.1/3.2.2/4.1/4.5, NET.4.1/4.3, INF.13).
- **BSI-Kompendium-XML-Importer Refactor** (`2a2bc51f`): Parser auf
  DocBook 5.0 umgeschrieben (real BSI-Format statt spekulatives Schema).
  XPath-Walk über alle `//section/title` mit Regex gegen
  `<Baustein>.A<N>`, Klassifikations-Parser `(B)/(S)/(H)` → basis/
  standard/kern, ENTFALLEN-Skip, periodic-flush alle 100 Einträge für
  Memory-Safety.
- **BSI-Kompendium 2023 vollständig** (`5e9ec5c1`): Offizielles
  DocBook-XML (3 MB) von bsi.bund.de/ITGSK/XML_Kompendium_2023.xml
  heruntergeladen und via `app:import-bsi-kompendium-xml` importiert.
  **1 868 Anforderungen** (B:492, S:867, H:404, 105 legacy), **121
  Bausteine**. ENTFALLEN (290) übersprungen. Re-Import bei 2024er-
  Edition = 1 Kommando, idempotent.

#### Phase 8H.1 Detail-Group & UI-Komponenten (2026-04-20)

Abgeschlossen mit einem finalen Einschub:

- **Skeleton-Wrapper für Management-KPI-Widget** (`1c126d5e`) auf der
  Home-Landing-Page: 350 ms Skeleton-KPI-Grid statt leerer Card. Nutzt
  bestehenden `_skeleton.html.twig` + `skeleton`-Stimulus-Controller.
  Perceived-Performance-Win ohne Server-seitige Änderung.

#### Bug-Fixes

- `65ee264e` BSI-Grundschutz-Check: `{% import _self as helpers %}` +
  `{% set %}`-Statements waren außerhalb des Blocks und wurden von Twig
  bei `extends` verworfen. Fix: alles in `{% block body %}` gezogen.
- `2a2bc51f` ComplianceFramework PHP-8.4-Property-Hook-Rekursion: das
  `get {}` las `$this->id`, was den Hook erneut triggerte → Stack-
  Overflow. Fix: Property-Hook entfernt, klassischer `getId()` getter.
- `ac11eba2` Interested-Party: verwendet jetzt `partyType` statt nicht-
  existenter `category`-Property.

---

#### Phase 9.P2 — Konzern-Governance & Reporting (2026-04-20)

Fortführung der Holding-Struktur aus P1. Die sieben Items aus dem
Consultant-Plan für Phase 9.P2 (8–15 FTE-d geschätzt) sind in fünf
Commits abgebildet. Alle neuen Dashboards sind ROLE_GROUP_CISO-gated
und strikt downward-only (Siblings unsichtbar).

- **Drei Read-only-Group-Dashboards** (Commit `c10ff753`).
  `/group-report/risks` — Top-10 Konzernrisiken über alle Töchter
  (sortiert nach Residualwert, Fallback inherent) plus Verteilung
  pro Tenant. `/group-report/kpi-matrix` — Framework-Reifegrad
  pro Tenant, Zellenwert aus der vorhandenen
  `getFrameworkStatisticsForTenant` (identisch zum Per-Tenant-
  Compliance-Dashboard, keine doppelte Berechnung).
  `/group-report/soa-matrix` — 93 Controls × N Tenants, single-char
  Status-Badges (✓ ◐ ◯ – N/A), Sticky Header, max-height 70vh.
  Keine Schema-Änderungen — alle Daten aus bestehenden Repositories.

- **Cross-Tenant-Lieferantenverzeichnis** (Commit `b425fcad`).
  `SupplierRepository::findGroupedForTenants()` dedupliziert per
  LEI-Code (ISO 17442, wenn vorhanden — strong match, "LEI"-Badge)
  oder normalisiertem Namen (lowercase + trim — weak match,
  "name-only"-Badge mit Nudge zur LEI-Pflege). Worst-case-
  Kritikalität gewinnt pro Gruppe (ein bei Tochter A "critical"
  gelabelter Lieferant ist konzernweit "critical", auch wenn
  Tochter B "medium" meldet). Sortierung nach Tenant-Anzahl
  absteigend, damit Konsolidierungskandidaten oben stehen. Ziel:
  DORA Art. 28.3 Sub-Outsourcing-Kette + ISO 27001 A.5.19.

- **Incident-Cross-Posting mit Opt-out** (Commit `7960d33a`,
  Migration `Version20260420120000`). Neues Boolean-Feld
  `Incident.visible_to_holding`, default `true`. Eine Tochter kann
  den Flag für vertrauliche Incidents (z. B. HR) deaktivieren —
  der Flag kurzschließt im Voter vor dem Holding-Tree-Check, nicht
  einmal ein Group-CISO überschreibt ihn. `/group-report/incidents`
  filtert sichtbar vs. Opt-out; die UI gibt bewusst **keine**
  "N versteckt"-Anzeige aus, damit das Verstecken kein implizites
  Signal wird. IncidentType-Form bekam die Checkbox mit Hilfstext
  "nur für vertrauliche Fälle deaktivieren". 3 neue Voter-Tests.

- **Holding-Policy-Vererbung** (Commit `bc86a6ec`, Migration
  `Version20260420130000`). `Document.inheritable` (default false)
  und `Document.overrideAllowed` (default true). Nur Holding-
  Dokumente mit `inheritable=true` werden in Töchtern read-only
  sichtbar — konservativer Default, damit die bestehende
  Dokumentenbasis nicht plötzlich propagiert wird.
  `DocumentRepository::findInheritedForTenant()` (strikt gefiltert
  im Gegensatz zum älteren `findByTenantIncludingParent`).
  `DocumentVoter::canView` bekommt eine neue Grant-Strecke:
  Tochter-User sieht Dokument, wenn `inheritable=true` und
  `user.tenant.isChildOf(doc.tenant)`. Edit bleibt
  uploader-only — inherited Docs sind automatisch read-only in
  Töchtern. `DocumentType` bekommt zwei CheckboxType-Felder mit
  Klartext-Helps zu "ISMS-Leitlinie 1:1 durchmandatieren"
  vs. "lokaler Override erlaubt". 3 neue Voter-Tests.

- **Konzern-Audit-Programm mit Derivation** (Commit `9988bb3d`,
  Migration `Version20260420140000`). `InternalAudit.parent_audit_id`
  self-FK (ON DELETE SET NULL, Index). Die FK-Erstellung läuft über
  ein INFORMATION_SCHEMA-Guard-Muster (MySQL unterstützt kein
  "ADD CONSTRAINT IF NOT EXISTS"). Neuer Service
  `GroupAuditProgramService::deriveForSubsidiaries(program, tenants,
  actor)` kopiert Scope/ScopeType/PlannedDate verbatim, suffixt die
  Audit-Nummer mit `-<tenant-code>`, setzt Status hart auf `planned`
  (jede Tochter läuft ihren eigenen Audit-Zyklus), und loggt ins
  AuditLog (program_id, derived_count, skipped_count, target
  tenant IDs — ISB-Anforderung). Idempotent: Töchter mit bereits
  existierender Ableitung desselben Programms werden übersprungen.
  Matrix-View `/group-report/audit-program`: Rows = Programs,
  Columns = Subsidiaries, Cell = Status-Badge oder Strich;
  Derive-Button pro Programm, CSRF-geschützt.

**Group-Report-Hub** jetzt 7 Tabs: nis2 / risks / kpi / soa /
suppliers / incidents / audit-program. Tree-View hat alle im Header.

**Tests**
- Voter: 6 neue (Document × 3, Incident × 3).
- Full sweep 129/129 green nach P2.4. lint:twig, lint:yaml,
  lint:container durchgehend sauber.

**Scope-Entscheidungen / Auslassungen**
- Findings-Roll-up (ein Dashboard das alle Tochter-Findings gegen
  einen Holding-Audit-Program aggregiert) intentional nicht
  enthalten — der Parent-Link ist die Datenbasis, die View ist ein
  Follow-up.
- `DocumentService` governance-model-basierte Vererbung
  (`findByTenantIncludingParent`) bleibt unangetastet; die neue
  `findInheritedForTenant` ist die strikt policy-gefilterte
  Variante für künftige UI-Sektionen.
- Group-KPI-Matrix zeigt leere Zelle ("—") wenn Framework im Tenant
  nicht anwendbar markiert ist — wichtige Unterscheidung gegenüber
  "0 % erfüllt", explizit für Auditor-Lesbarkeit.

#### Phase 9.P1 — Holding/Konzern-Struktur (2026-04-20)

Umsetzung des Consultant-Plans zu NIS2-Holding-Abbildung (§28 BSIG-neu).
Ziel: Holding-Governance steuern, Töchter bleiben eigene Rechtspersonen
mit eigener NIS2-Klassifikation und Zertifizierungsscope.

- **Tenant-Hierarchie gehärtet** (Commit `fa9b6d3d`).
  `Tenant::setParent()` wirft jetzt `LogicException` bei Self-Reference
  und bei transitiven Zyklen (vorher Endlos-Schleife in `getRootParent`
  möglich). `Tenant::isChildOf()` prüft direkte und indirekte Abstammung.
  `TenantContext` erhielt `getAccessibleTenants()`, `getCurrentRoot()`,
  `canAccessTenant()` als Topology-Feed für die Voter-Schicht.
  Handle `null`-IDs bei frisch konstruierten Objekten korrekt.

- **Baseline-Vererbung read-only** (Commit `8a354644`).
  `AppliedBaselineRepository::findInheritedByTenant()` scannt die
  Ahnenkette und meldet Baselines, die an einem Vorfahren — nicht am
  Tenant selbst — angewendet wurden (closest ancestor wins). UI zeigt
  drei Zustände: direkt angewendet (grüner Badge), vererbt von
  Holding X (blauer Badge mit Quellname), nicht angewendet.

- **`applyRecursive` Propagation** (Commit `8a354644`).
  `IndustryBaselineApplier::applyRecursive()` wendet eine Baseline auf
  Holding + alle direkten/transitiven Töchter an, idempotent pro Subtree.
  Neue Route `POST /industry-baselines/{code}/apply-recursive`
  (ROLE_MANAGER + CSRF), Button erscheint nur bei Tenants mit Töchtern.
  Flash-Summary meldet "%applied% neu versorgt, %skipped% bereits
  vorhanden, %total% Tenants insgesamt".

- **ROLE_GROUP_CISO / Konzern-ISB** (Commit `3fa65e0d`).
  Neue Modifier-Rolle in `security.yaml role_hierarchy`: erbt nur
  `ROLE_USER` — stackt auf vorhandene Rollen. `HoldingTreeAccessTrait`
  von 5 Votern (Risk, Asset, Control, Incident, Document) konsumiert.
  Strict downward-only: Siblings nicht zugreifbar; EDIT/DELETE
  kreuztenant-weit verboten. Neue Voter-Tests gegen Sibling-Leakage
  und Edit-Bypass.

- **Tenant-NIS2-Felder** (Commit `1c2100ef`, Migration `20260420110000`).
  Sieben zusätzliche Felder auf `Tenant`: `nis2_classification` (essential/
  important/not_regulated/unknown), `nis2_sector`, `nace_code`,
  `legal_name`, `legal_form`, `nis2_contact_point`, `nis2_registered_at`.
  `Tenant::isNis2Regulated()` Helper. Konsequent pro Rechtsperson — nie
  konzernweit konsolidiert (BSIG §28 verlangt das).

- **Group-Report-Controller** `/group-report/*` (Commit `1c2100ef`).
  `tree` — rekursives Macro-Rendering des Subtree ab aktuellem Tenant
  (nach Review-Fix: explizit NICHT `getRootParent()`, um lateral/upward
  Access zu verhindern). `nis2-registration` — Matrix-View mit je
  Tenant: Rechtsperson, Klassifikation, Sektor, NACE, BSI-Meldekontakt,
  Registrierungsdatum. Header-KPIs (essential / important /
  not_regulated / unknown / registered). Rote "Registrierung fehlt"-
  Badges für regulierte Tenants ohne Registrierungsdatum. Zugang via
  `IsGranted('ROLE_GROUP_CISO')`.

- **Form + Navigation** (Commit `db890e1b`, P1-Review-Fixes).
  `TenantType` um 7 NIS2-Felder erweitert (ChoiceType + DateType + Text),
  DE/EN-Übersetzungen für Labels, Helps, Placeholders, Enum-Labels.
  Mega-Menu-Eintrag "Konzern-Reports" im ISMS-Bereich, ROLE_GROUP_CISO
  gated.

**Scope-Entscheidungen / Bewusste Auslassungen**
- Flat hierarchy (Self-FK) — keine Multi-Parent-Matrix. Reicht laut
  Consultant für 80 % der Mittelstands-Holdings; M&A-Sonderfälle → P3.
- Konzern-Risk-Aggregation, Incident-Cross-Posting, Policy-Override-
  Sperre, Cross-Tenant-Supplier-Register, Group-KPI-Report,
  Group-SoA-Matrix → P2 (Backlog).
- Konsolidierte Finanz-KPIs explizit als "später mal" markiert
  (CFO-Reporting-Trigger, SAP-GRC-Territorium).

**Tests + Regression**
Entity 3 neue, Service 5 neue, Applier 2 neue, Voter 4 neue; full
sweep 53/53 grün. lint:twig + lint:yaml + lint:container sauber.

#### Compliance-Manager-Residual-Sprint (2026-04-19 / 04-20)

Abschluss aller Residual-Items aus `docs/audit/compliance_manager_analysis.md`
v2.1. Audit-Doc v2.2 fortgeschrieben, Gesamtbewertung **98 / 100** (v2.1: 96).
Alle sieben Ziel-Frameworks erstmals Tool-🟢. Residual-Budget **19 → 3 FTE-Tage**.

**NIS2 Directive (EU 2022/2555) — Art. 21 + Art. 23**
- **Nis2ComplianceService** — Backend-Metriken für alle 11 Art.-21.2-Letters
  (a Risk-Policies, b Authentication, c Encryption, d Vulnerability-Mgmt,
  e Secure-SDLC, f Supply-Chain, g HR-Security, h Access-Control, i Asset-Mgmt,
  j BCM, k Cryptographic-Controls) plus Art.-23-Timer (24 h / 72 h / 1 Monat)
  und gewichteter Gesamtscore. Alle Werte aus bestehenden Domain-Daten — keine
  Doppelpflege. Commit `6d88e74f`.
- **Dashboard-UI** — `/nis2-compliance` rendert 11-Letter-Grid + Art.-23-
  Timeline-Card, ersetzt den bisherigen 3-Letter-Mittelwert durch den
  gewichteten Score. Status-Farben, Drill-Down, DE/EN-Übersetzungen für
  alle Letter-Titel. Commit `78423dcc`.

**BSI IT-Grundschutz**
- **Kompendium-Delta-Loader** `app:load-bsi-kompendium-delta` — 29
  Anforderungen aus dem Kompendium 2023, die im Base- und Supplement-Loader
  fehlten: CON.4/5 Standardsoftware/Entwicklung, OPS.2.3 Outsourcing,
  APP.4.4 Kubernetes, APP.6 Allgemeine Software, SYS.1.2 Windows-Server,
  SYS.1.3 Unix-Server, SYS.1.6 Containerisierung, SYS.2.2 Windows-Clients,
  SYS.3.3 Mobiltelefon, NET.4.2 VPN, INF.10 Besprechungsräume.
  `absicherungsStufe` tagged, idempotent. Commit `b1a3db20`.
- **BsiGrundschutzCheckService** — gruppiert Anforderungen nach Baustein,
  klassifiziert MUSS/SOLLTE/KANN (aus `anforderungsTyp` → Description-
  Heuristik → Priority-Fallback), berechnet gewichtete Compliance
  (MUSS × 3, SOLLTE × 2, KANN × 1), filter-tauglich nach Absicherungsstufe.
  Commit `b1a3db20`.
- **IT-Grundschutz-Check-View** `/bsi-grundschutz-check` — Overall-Score,
  Schicht-Summary-Tabelle, Baustein-Cards mit Progress + expandierbarer
  Anforderungsliste. Absicherungsstufen-Filter (basis/standard/kern/alle).
  Mega-Menu-Eintrag + DE/EN-Übersetzungen. Commit `5b36be96`.
- **BSI Absicherungsstufen-KPI-Filter** — neue `bsi_stufen`-Sektion im
  Management-KPI-Dashboard, emittiert 3 gewichtete KPIs (basis/standard/
  kern). Commit `4a2575eb`.

**DORA (Digital Operational Resilience Act)**
- **Register-of-Information-Importer** `DoraRegisterOfInformationImporter` +
  `app:import-dora-register <file> --tenant=<id> [--dry-run]` — symmetrisch
  zum bestehenden ITS-Exporter. Upsert per LEI (Fallback Name), 19 ITS-
  Spalten, UTF-8 BOM tolerant, RFC 4180 quoting, strukturiertes Resultat
  (processed / created / updated / skipped / errors). Commit `ae0f6eda`.
- **Sub-Outsourcing-Editor (Art. 28.6 / 30)** — strukturierter Stimulus-
  Row-Editor ersetzt die Freitext-Liste: Tier 1–5, Name, LEI, Country,
  Service, Criticality pro Zeile. Baum-Visualisierung auf Supplier-
  Show-Page mit Tier-Gruppierung + Badges. Backward-compat: Legacy-
  Newline-Strings bleiben nutzbar. Form-Theme + SupplierType POST_SUBMIT
  entkoden JSON. Commit `e73a5d9f`.

**TISAX (VDA ISA 6.0.4)**
- **Info-Classification-Schicht** — `tisaxInformationClassification` Enum
  auf Asset + Document: public, internal, confidential, strictly_confidential,
  prototype. Getrennt von `dataClassification` (ISO-leaning). Migration
  Version20260419250000 idempotent. Commit `681c8bde`.
- **Prototype-Protection-Flow (VDA Kap. 8)** — eigenständige
  `PrototypeProtectionAssessment`-Entity mit Scope, TISAX-Level (AL2/AL3),
  4 Prototype-Labels (parts, vehicles, test-vehicles, events_and_shoots),
  je einem `result` + `notes` pro Kap.-8-Sektion (8.1 Physical, 8.2
  Organisation, 8.3 Handling, 8.4 Trial-Operation, 8.5 Events), Evidence-
  Documents M:M, Overall-Score worst-of-sections. CRUD unter
  `/prototype-protection` gated by ROLE_MANAGER (Delete ROLE_ADMIN),
  Index mit Expiring-60d-Alert. Mega-Menu + `prototype_protection.*`
  Translation-Domain DE/EN. Commit `6ea86404`.

**ISO 27001 Clause 9.3 — Management Review**
- **H-02 Management-Review-PDF-Export** `/management-review/{id}/pdf` —
  supervisory-grade Dokument, das die persistierten Review-Daten auf die
  normative 9.3-Struktur mappt: 11 Input-Zeilen (Clause 9.3.2 a–f),
  6 Output-Zeilen (Clause 9.3.3). Cover-Sheet, Status-Badge, Tenant,
  Participants, Reviewer, Generated-at. Tenant-Name als Klassifizierungs-
  Footer. DE/EN-Übersetzungen für alle Input/Output-Keys.
  Commit `81adc39b`.

**Audit-Dokumentation**
- `docs/audit/compliance_manager_analysis.md` auf v2.2 fortgeschrieben mit
  Delta-Liste, aktualisierter Reifegrad-Ampel und Portfolio-Reuse-Score
  pro Szenario. v2.1-Residual-Zeilen in-place als ✅ erledigt markiert.

#### Junior+UX+CM Audit Sprint (2026-04-19)

Drei unabhängige Audits (`docs/JUNIOR_IMPLEMENTER_WALKTHROUGH.md`,
`docs/UX_JUNIOR_RESPONSE.md`, `docs/CM_JUNIOR_RESPONSE.md`) lieferten
26 Findings. Umgesetzt in drei Sprints:

**Sprint 1 Quick-Wins (8/8)**
- Q1 CIA-Skala bei Asset-Labels inline sichtbar (1 öffentlich → 5
  geheim / 1 gering → 5 kritisch / 1 >24h ok → 5 <15 Min)
- Q2 ⌘K-Chip im Global-Search-Button ab md-Viewport sichtbar +
  `aria-keyshortcuts`
- Q3 Redundantes `monetaryValue`-Feld aus Asset-Form entfernt
- Q4 `_iso_reference_label`-Komponente: Control-ID + Klartext +
  Bootstrap-Tooltip (10 Caller migriert)
- Q5 `_bulk_action_bar` konsolidiert, Plural-Variante gelöscht, 4
  Caller migriert
- Q6 Breadcrumb `Home` → `nav.home` Translation (UXC-10)
- Q7 `InheritanceMetricsService`: per-framework Inheritance-Rate +
  Tenant-Totalwerte — erfüllt Plan-v1.1-Mess­kriterium
- Q8 FTE-saved-KPI als Exec-Summary-Card auf `/reports/management/
  portfolio` (via `CompliancePolicyService::KEY_REUSE_DAYS_PER_
  REQUIREMENT`)

**Sprint 2 Blocker (3/3)**
- B1 Portfolio-Report-Trend + Drill-Down (CM-3):
  `PortfolioSnapshot`-Entity + `app:portfolio:capture-snapshot`
  Cron-Command + `PortfolioReportService::buildMatrixWithTrend`
  mit echtem Delta. Matrix-Zellen drillen auf Requirement-Liste via
  `/reports/management/portfolio/drill/{framework}/{category}`.
  Trend-Pfeile: ↗ > 5 / ↘ < -5 / → stable / — no data.
- B2 Interessierte-Parteien Single-Source (Junior #5): `ISMSContext
  .interestedParties`-Freitext aus Form entfernt; Kontext-Index
  zeigt jetzt Live-Aggregat aus strukturiertem Modul mit CTA-Link.
  Legacy-Freitext bleibt als aufklappbare `<details>` mit
  Migrations-Hinweis.
- B3 Incident↔Risk↔Vulnerability 1-Klick-Verknüpfung (Junior #8):
  `_entity_link_matrix`-Komponente + Pre-Fill-Parameter in Risk/
  Vulnerability/Incident `new()` (`?fromVulnerability=`,
  `?fromIncident=`, `?fromRisk=`), tenant-strict.

**Sprint 3 Strategic (5/5)**
- S1 Filter-State in URL (UXC-11+12): 7 Index-Seiten auf GET-Form-
  Filter umgestellt, `?q=`, `?status=`, `?sort=`, `?dir=`, `?page=`
  persistieren im Link. Neue Components `_search_filter_form` +
  `_reset_filters_link`. Audit-Links jetzt teilbar und bookmarkbar.
- S2 Industry-Baselines (CM-6): neue Entities `IndustryBaseline` +
  `AppliedBaseline`, `IndustryBaselineApplier` (idempotent, tenant-
  strict, audit-logged), `app:load-industry-baselines`-Seed-Command
  mit **4 Starter-Paketen** (Production, Finance, KRITIS-Health,
  Generic). UI unter `/industry-baselines` mit Preview + 1-Klick-Apply.
- S3 Audit-Freeze (CM-8): `AuditFreeze`-Entity mit SHA-256-versiegeltem
  JSON-Payload. `/audit-freeze`-Routen unter ROLE_MANAGER:
  index/new/show/verify/generate-pdf/download-pdf. Unveränderlich
  by design — kein Update/Delete-Endpoint.
  `AuditFreezeSnapshotBuilder` captured SoA-Entries, Framework-
  Anforderungen, Top-Risiken, KPI-Summary zum Stichtag.
- S4 Delta-Assessment-Excel (CM-2): `DeltaAssessmentExcelExporter`
  mit 3-Sheet-Layout (Summary, Detailed-Delta, Mapping-Inventory).
  Route `/delta-assessment/{framework}/excel?baseline={code}`.
  Traffic-Light-Backgrounds + BOM-safe via `ExcelExportService`.
- S5 Onboarding-Checkliste "Mein erstes ISMS" (Junior #4):
  `_first_steps`-Component auf 5-Schritt-Pfad umgestellt —
  Kontext → Asset → Risiko → Control → Dokument. 9001-Analogie
  explizit im Hint: "Qualitätspolitik ≈ ISMS-Kontext, Prozess-
  Landkarte ≈ Asset-Inventar, Maßnahme ≈ Control, dokumentierte
  Information ≈ Dokument."

**DB-Repair-Review-Findings — gefixt vor Sprint 1**
- Consultant A1: 5 Loader (BSI, C5:2020, C5:2026, ISO22301, TKG)
  waren nicht idempotent — jedes "Run All" verdoppelte Requirements.
  Alle auf Tisax-Pattern migriert mit `--update`-Flag.
- Consultant A2: `fixAllOrphans` Cross-Tenant-Leak — jetzt blockiert
  bei > 1 Tenant, Confirm-Hash gegen Preview-Count, per-Entity
  Audit-Log.
- Consultant A4: Schema-Update UI divergierte mit
  `doctrine_migration_versions` → `SchemaHealthService::applyUpdate`
  blockt jetzt wenn Migrations pending sind, mit `$bypassMigrationGate`
  als explicit Notfall-Option. SHA-256-Hash jeder ausgeführten SQL-
  Bundle im Audit-Log.
- ISB MINOR: `ReSignAuditLogCommand --after` brach HMAC-Chain →
  neue `AuditLogIntegrityService::signWithPrevious()` mit manueller
  Predecessor-Kette.
- ISB MAJOR-4: Loader-Fixer Audit-Log enthielt nur Count-Deltas →
  jetzt vollständiger Metadata-Diff (name, version, description,
  applicable_industry, regulatory_body, scope_description) vor vs.
  nach Loader-Run.

**UX-Specialist Phase 2 — Process findings (ohne 4-Augen)**
- `DataRepairController`: audit-logging auf 5 verbleibende Write-
  Routen + `fixTenantMismatches` mit Reason-Pflicht (≥20 Zeichen)
- `HealthAutoFixService`: Optional `AuditLogger` injiziert, alle 10
  Methoden via `audit()`-Helper. `runComposerInstall` mit Symfony
  Process-Output-Persistenz.
- Schema-Update UI 2-Phasen-Flow: `<details>`-Preview mit Warning-
  Alert + Pflicht-Checkbox "Backup geprüft" vor Submit.

**Docs**
- `docs/DB_REPAIR_REVIEW_ISB.md` (215 Z.) + `.../CONSULTANT.md` (165 Z.)
- `docs/JUNIOR_IMPLEMENTER_WALKTHROUGH.md` (370 Z.) — Top-10 Findings
- `docs/UX_JUNIOR_RESPONSE.md` (679 Z.) — Interventions-Matrix +
  12 UXC-Findings über Junior hinaus
- `docs/CM_JUNIOR_RESPONSE.md` (261 Z.) — FTE-Overlay + 10 CM-eigene
  Findings

#### Persona-Audit Sprint (2026-04-18 / 04-19)

Kompletter Durchlauf der vier Persona-Analysen
(`docs/audit/{ism,risk,bcm,compliance_manager}_analysis.md`) und der
drei `.claude/` Pläne (IMPROVEMENT_PROJECTS, FORM_UX_IMPROVEMENTS,
KPI_IMPROVEMENT_PLAN). Alle HIGH/KRITISCH-Items sind umgesetzt.

**ISO 27001 Zertifizierungs-Readiness**
- **H-01** Strukturierte Audit-Findings + Korrekturmaßnahmen (ISO 27001
  Clause 10.1):
  - Neue Entities `AuditFinding` (4 Typen, 4 Severities, 5-Stufen-Status)
    und `CorrectiveAction` (inkl. Ursachenanalyse + Wirksamkeitsprüfung)
  - CRUD unter `/audit-finding` und `/corrective-action`, Tenant-Isolation,
    AuditLogger-Einbindung, Workflow-Links zwischen Findings und Aktionen
- **H-04** ISO 27001 Clauses 4–10 als ComplianceRequirements
  (`app:load-iso27001-clauses`) — 28 Requirements: Context, Leadership,
  Planning, Support, Operation, Performance Evaluation, Improvement
- **AUD-02** HMAC-SHA256-Chain für `audit_log` (NIS2 Art. 21.2
  Tamper-Evidence) mit `app:audit-log:verify` + `app:audit-log:resign`

**Risk- & Vulnerability-Management**
- **VUL-01** `Incident ↔ Vulnerability` ManyToMany mit idempotenter
  FK-Migration und Inverse-Relation auf beiden Seiten
- **Risk-Linking** `Risk.threatIntelligence` und
  `Risk.linkedVulnerability` im FormType, Freitext bleibt als Ergänzung
- **Schutzbedarfsvererbung** (BSI 3.6 Maximumprinzip):
  - `Asset.dependsOn` Self-ManyToMany + `AssetDependencyService`
    (BFS-Traversierung, zyklensicher, driver-asset pro CIA-Dimension)
  - Widget auf `asset/show.html.twig`, das erhöhte geerbte C/I/A-Werte
    inkl. Verursacher-Asset anzeigt

**Form-UX Plan (.claude/FORM_UX_IMPROVEMENTS.md) vollständig**
- **Pattern A Dual-State Owner** — 7 Entities (Asset, BusinessContinuity-
  Plan, BusinessProcess, Control, Incident, Risk, Training) bekommen eine
  optionale `ManyToOne User`-Relation neben dem bisherigen Freitext:
  - Migration `_user_id`-Spalten + Backfill via Name/E-Mail-Match
    (tenant-scoped, case-insensitive, nicht-destruktiv)
  - `getEffective*()` Helper (User → fullName, sonst Legacy-String)
  - FormTypes mit EntityType(User) + Legacy-TextType als Fallback
  - Templates nutzen `effective*` in Anzeige (Asset, BusinessProcess,
    BCM, Incident-Preview, Training)
- **Pattern B TomSelect** — 6 Native-Multi-Selects
  (`Incident.affectedAssets`, `BusinessProcess.supportingAssets`/
  `identifiedRisks`, `Control.protectedAssets`, `Training.coveredControls`/
  `complianceRequirements`) nutzen neuen `tom-select` Stimulus-Controller
- **Pattern C Help-Texte** — BCPlanType + 13 DORA/GDPR-Felder der
  SupplierType bekommen Help-Strings + DE/EN-Übersetzungen
- **Pattern D Progressive Disclosure** — `conditional_fields_controller`
  um Negation (`data-depends-on-negated`) und Select-Trigger
  (`data-depends-on-value`) erweitert. Angewendet auf
  `DataBreach.noSubjectNotificationReason`, `DataSubjectRequest.
  identityVerificationMethod`, `ProcessingActivity.specialCategoriesDetails`
  und `.automatedDecisionMakingDetails`
- Kleinere Form-UX Fixes: `IncidentType.crossBorderImpact` nicht mehr
  `required`, Resolution-Hinweistexte, CIA-Skalen mit 1-5-Erklärung,
  DataBreach `severity` vs. `risk_level` klargestellt

**WCAG 2.2 AA Compliance**
- `aria-live="polite"` + `role="status"` auf Flash-Messages- und
  Toast-Containern
- `role="dialog"` + `aria-modal` + `aria-labelledby` auf Quick-View-
  und Notification-Panel
- 314 `<th>`-Elemente in 40 Templates mit `scope="col"` versehen

**KPI-Plan (.claude/KPI_IMPROVEMENT_PLAN.md) vollständig**
- Phase 1 Bug-Fixes: MTTR-Divisor, `supplier_assessment_rate` null
  statt 100 % wenn keine kritischen Lieferanten, `asset_classification_
  rate` mit UND- statt ODER-Logik über alle CIA-Werte
- Phase 2 High-Value: gewichtete Control-Compliance
  (implemented=1.0, partial=0.5), per-Framework-Compliance (A1)
- Phase 3 Strategic: Risk-Appetite-Compliance (A2), MTTR nach Severity
  (kritisch/hoch), **ISMS Health Score** (A4) als Composite aus
  Compliance 40 % / Risk 25 % / Incidents 20 % / Asset-Classification 15 %,
  Residual Risk Exposure (A3)
- Phase 4 Management: `days_since_management_review` (ISO-27001-Jahres-
  schwelle), `oldest_overdue_item_age`, Gap-Count nach Priorität
- Phase 5 Advanced: Control-Reuse-Ratio (A5), Regulatory-Deadline-
  Tracker (A9, 30-Tage-Horizont), Implementation-Readiness-Checklist
  (A10, 8-Punkt-Composite), Raw-Totals-Demotion via `tier`-Flag,
  **KpiThresholdConfig** Entity + `/admin/kpi-thresholds` Admin-UI
  für tenant-spezifische Good/Warning-Schwellen

**BCM**
- **BC-Plan-Templates-Seeder** `app:seed-bc-plan-templates <tenant-id>`
  mit 5 Standard-Szenarien: IT-Ausfall, Pandemie/Personalausfall,
  Datenschutzverletzung (DSGVO 72h), Gebäude-/Standort-Ausfall,
  Lieferkette/ICT-Dienstleister (DORA Art. 28 Exit-Strategie)

**Compliance-Kataloge**
- **Seeder-Idempotenz** für 7 Load-Commands (DORA, NIS2, KRITIS, KRITIS-
  Health, TISAX, DiGAV, GxP) mit `--update`-Flag + create/update/skip-
  Statistiken (Best-Practice-Muster aus NIST CSF übernommen)
- **GDPR** +8 Artikel (Art. 6, 9, 13, 14, 21, 22, 26, 36)
- **CIS Controls** Kategorie-Labels v7 → v8 IG1/IG2/IG3
- **KRITIS** Rechtsgrundlage von §8a BSIG auf NIS2UmsuCG (seit
  2025-12-05 in Kraft) aktualisiert
- **NIS2** Art. 21.2.f Title korrigiert ("Basic cyber hygiene and
  cybersecurity training"), neuer `LoadNis2UmsuCGRequirementsCommand`
  für das deutsche Umsetzungsgesetz
- **Absicherungsstufen-Filter-UI** (BSI IT-Grundschutz basis/standard/
  kern) auf `/compliance/requirement/` inklusive Anforderungstyp-
  Filter (MUSS/SOLLTE/KANN)

**Security & Data Quality**
- TOTP-Secret: Base32-Encoding (RFC 6238) statt raw binary — behebt
  MySQL utf8mb4-Insert-Fehler
- Asset-Delete erlaubt Parent-Tenant-inherited Assets (Bypass des
  `tenant_filter` nur für diese Route, danach `canEditAsset`-Check)

**Tests & CI**
- PHP 8.5: `ReflectionProperty::setAccessible()` + `imagedestroy()`
  entfernt, `Length(['min', 'max'])` → named args, `isType('array')`
  → `isArray()` — Test-Suite läuft unter `failOnDeprecation="true"`
  auf exit 0
- Suite: 3,919 Tests, 10,827 Assertions, 0 Fehler, 0 Failures

#### Data-Reuse Plan v1.1 — WS-1 … WS-8 vollständig
- **WS-1 Mapping-basierte Vererbung mit Review-Pflicht**
  - `ComplianceInheritanceService` erstellt Vorschläge → `FulfillmentInheritanceLog`
    (Status `pending_review`, niemals direkter Schreibzugriff auf den Erfüllungsgrad)
  - Reviewer-Pflicht (bestätigen / ablehnen / überschreiben) mit
    Mindestzeichenlänge laufzeitkonfigurierbar
  - 4-Augen-Workflow für `implemented`-Transitions via `FourEyesApprovalService`
  - Feature-Flag `COMPLIANCE_MAPPING_INHERITANCE_ENABLED` für Dark-Launch
- **WS-2 Import Guardrails** mit konfigurierbaren Grenzwerten
  (Upload-Limit MB, 4-Augen-Zeilenschwelle)
- **WS-3 DORA-Lieferantenregister** — 14 Zusatzfelder am `Supplier`
  (LEI, ICT-Kritikalität, Substituierbarkeit, Exit-Strategie, AV-Vertrag)
- **WS-4 Portfolio-Ampel** mit konfigurierbaren Schwellwerten (Grün/Gelb)
- **WS-5 Cross-Framework Mappings** — 461 Mappings über 22 Frameworks
  (ISO 27001/27002/27005/27701/22301, NIS2, DORA, TISAX, BSI, C5, EU AI Act …)
- **WS-6 Gap-Report + Quick-Wins** mit Aufwand-Perzentil & Min-Gap-Prozent
  (konfigurierbar), pro-Requirement `adjustedEffortDays` Override
- **WS-7 Scheduled Portfolio-Reports** (`ScheduledReport::TYPE_PORTFOLIO`)
- **WS-8 Setup-Wizard: existierende Frameworks** mit Reuse-Heuristik
  `daysPerRequirement` laufzeitkonfigurierbar
- **Admin-Tool: Framework Loader-Fixer** (`/admin/loader-fixer`) —
  idempotente Re-Exekution aller 22 Framework-Loader
- **Admin-UI: Compliance-Policy-Einstellungen** (`/admin/compliance/settings`)
  - 13 Laufzeit-Konfigurationsparameter (inheritance / four_eyes /
    portfolio / setup / import / ui / gap_report)
  - Werte in `system_settings` (Kategorie=compliance), YAML als Fallback
  - Jede Änderung wird auditiert, pro-Key-Reset auf Default möglich
- **7 Persona-Skills** (Junior-Implementer, ISB, CISO, Auditor, Senior-Consultant,
  Risk-Owner, Compliance-Manager) für realistisches Tool-Feedback

### 🔧 Changed
- `ComplianceInheritanceService` und `FourEyesApprovalService` lesen
  Schwellwerte jetzt zur Laufzeit über `CompliancePolicyService` —
  Admin-Änderungen wirken sofort ohne Deployment
- `ComplianceMapping` um `source`, `version`, `validFrom`, `validUntil`
  erweitert (Versionierung von Mapping-Quellen)

### Planned
- JWT Authentication for REST API
- Advanced API filters and search
- Real-time notifications via WebSocket
- Phase 8B: Dashboards & Analytics

---

## [2.6.0] - 2025-12-20

### ✨ Added

#### PWA Advanced Features (Phase 8A Complete)
- **Push Notifications**
  - PushSubscription entity for storing web push subscriptions
  - WebPushService for sending notifications with VAPID authentication
  - API endpoints for subscribe/unsubscribe/test notifications
  - Automatic VAPID key generation and storage
  - Device detection and naming from User-Agent
  - Failure tracking with auto-disable after 3 failures

- **Background Sync**
  - IndexedDB storage for offline requests
  - Automatic sync when connection is restored
  - Support for incidents, risks, and general form submissions
  - Client notification on successful sync
  - Periodic sync for dashboard data prefetching

- **Share Target API**
  - Web Share Target for receiving shared content
  - Smart content analysis for suggested actions
  - File handler for JSON and CSV imports
  - Protocol handler for web+isms:// links
  - Multi-action support (create incident, risk, document, or note)

- **Translations**
  - Push notification translations (DE/EN)
  - Background sync translations (DE/EN)
  - Share target translations (DE/EN)

### 🔧 Changed
- Updated Service Worker with:
  - Enhanced push notification handling
  - IndexedDB-based offline request queue
  - Message passing for client-service worker communication
  - Periodic background sync support

- Updated manifest.json with:
  - Share target configuration
  - File handlers for ISMS imports
  - Protocol handlers for deep linking

---

## [2.5.2] - 2025-12-19

### ✨ Added

#### Role Documentation & UX Improvements
- **Role Help Component** (`_role_help.html.twig`) with comprehensive role explanations
  - Visual role hierarchy chain: User → Auditor → Manager → Admin → Super Admin
  - Side-by-side comparison of system roles vs custom roles
  - Collapsible help section explaining role differences
  - Warning about same-name roles (system vs custom) being different entities
- **Role Tooltips** on user form checkboxes with role descriptions
  - Hover over any role checkbox to see what that role can do
  - Translated descriptions in German and English
- **Improved User Management Forms**
  - Role help component integrated into create/edit user pages
  - Enhanced help text explaining role inheritance

#### Progressive Web App (Phase 8A)
- **Web App Manifest** for installable PWA
- **Service Worker** with offline support
- **PWA Icons** (72px to 512px)
- **Offline Page** with cached page list

#### Translations
- Complete role descriptions for all 5 system roles (DE/EN)
- Role hierarchy explanations
- System vs custom role feature comparisons
- Common UI translations (show/hide details)

### 🐛 Fixed
- Various CI test improvements for compliance wizard tests

---

## [2.7.0] - 2026-04-17

### ✨ Added

#### Phase 8J: Standards Compliance & UX Improvement (7 Sprints, 67+ Massnahmen)

**Neue Entities & Features:**
- DataSubjectRequest (GDPR Art. 15-22 Betroffenenrechte) — Entity, Service, Controller, Templates
- ElementaryThreat (BSI 200-3, 47 Gefaehrdungen G 0.1-G 0.47)
- KpiSnapshot (taegliche KPI-Snapshots, 30d daily + 12m monthly Retention, konfigurierbar)
- RiskAggregationService (Portfolio-View, korrelierte Risiken, Heatmap)
- BCMService (BIA-Analyse, Plan-Readiness, Exercise-Schedule)
- GrundschutzCheckService (Baustein-Level Soll/Ist, Absicherungsstufen-Compliance)
- Board One-Pager PDF (RAG-Status + Top-Risiken + Framework-Compliance auf einer Seite)
- Connected Demo Data Command (ERP→Buchhaltung→2 verknuepfte Risiken)
- Conditional Fields Stimulus Controller (Progressive Disclosure)

**KPI-System (CISO/Board):**
- ISMS Health Score (gewichtetes Composite aus 6 KPI-Kategorien)
- Per-Framework Compliance % (jedes aktive Framework als eigene KPI-Zeile)
- Risk Appetite Compliance Score, Residual Risk Exposure
- MTTR nach Severity (Critical/High separat)
- Control Reuse Ratio, Days Since Last Management Review
- Oldest Overdue Item Age, Compliance Gaps by Priority
- Implementation Readiness Checklist, Trend-Pfeile auf allen KPIs

**Compliance-Kataloge:**
- 3 neue Frameworks: NIS2UmsuCG (15 Req), BDSG (12 Req), EU AI Act (10 Req)
- GDPR +7 Artikel, NIST CSF +17 Subcategories, GxP Subpart C + GAMP5/ICH/PIC/S
- DiGAV 2020→2024 + BSI TR-03161, KRITIS→NIS2UmsuCG, CIS IG-Labels
- 8 Frameworks im Setup Wizard hinzugefuegt (ISO 22301, SOC2, CIS, NIST CSF, etc.)

**Onboarding & Navigation:**
- First Steps Checklist auf Dashboard (5 Schritte, dismissbar)
- ISO 9001→27001 Bridge Page + ISMS Glossar (20 Begriffe)
- Modul-Presets im Setup ("ISO 27001 Starter" / "Vollstaendig")
- Reports-Kategorie, Spezial-Dashboards, Hilfe & Ressourcen im Mega Menu
- Rollen-bewusste Navigation (AUDITOR Read-Only, Audit-Log fuer ISB)
- Granulare Modul-Sichtbarkeit (Item-Level)

### 🔧 Changed

**KPI-Berechnungen korrigiert:**
- MTTR: korrekter Divisor + Minuten, Severity-Segmentierung
- control_compliance: gewichtet (implemented*1.0 + partial*0.5)
- risk_treatment_rate: nur mit echten Massnahmen (accept=formell, mitigate=mit Controls)
- supplier_assessment_rate: N/A statt falsche 100%
- asset_classification_rate: alle 3 CIA-Werte noetig (AND statt OR)
- Count-basierte Thresholds normalisiert (%-basiert)
- Raw Totals zu Detail-Level demoted

**Form UX (90+ Felder mit Help-Texten):**
- BCPlanType 23, SupplierType 22, BCExerciseType 22 Felder mit ISO-Referenzen
- RiskType, IncidentType, ControlType, TrainingType, DataSubjectRequestType vervollstaendigt
- Progressive Disclosure fuer ProcessingActivityType + RiskType GDPR-Sektion
- Incident: reportedBy Auto-Fill, crossBorderImpact + treatmentStrategy optional
- CIA/RTO/RPO/MTPD/Impact Skalen vollstaendig erklaert

**BCM/Risk i18n:**
- business_process/show, bcm/index, bc_exercise Templates komplett uebersetzt
- Raw Status/Type Values in BCM Templates durch Translation Keys ersetzt
- DataBreach Index: 25+ hardcoded English → Translations
- Asset Chart Labels uebersetzt

**Seeder & Kataloge:**
- 7 Seeder idempotent, BSI Frameworks konsolidiert
- NIS2 Title-Fixes, KRITIS→NIS2UmsuCG Rechtsgrundlage
- TISAX 6.0.3→6.0.4, CIS v8→v8 IG1/IG2/IG3

**Setup Wizard:**
- Frameworks werden jetzt tatsaechlich geladen (war komplett broken)
- Cross-Framework-Mappings nach Import generiert
- Modul-Empfehlungs-Keys korrigiert, Debug-Variablen entfernt
- Industrie-Checkboxen, Progress-Bars korrigiert, Login-Credentials angezeigt

**Tenant/Context:**
- "Organisation Context" → "Organisation Profile" (ISO-Namenskollision behoben)
- ISMSContext Validierung: hardcoded German → Translations
- Context ↔ Interested Parties Cross-Links
- Completeness-Berechnung konsistent (Service statt Template)
- Setup-Fortschritts-Checklist auf Tenant-Detailseite

### 🐛 Fixed

**Tenant-Isolation:** TenantFilter global aktiviert, 29 Caller gefixt
**Security:** CSRF auf SearchController, WorkflowController, UserManagement, CorporateStructure, AdminModuleController; switch_user restricted; Open Redirect gefixt
**Entity Cascades:** Risk/ComplianceRequirement/Document CASCADE→SET NULL
**Runtime:** IncidentRiskFeedback params, Reflection entfernt, Rekursionsschutz
**Incident Status-Mismatch:** Template + Controller korrigiert
**Admin DB-Fix:** DataIntegrityService auf 95%+ (15 Entity-Typen, Status-Validierung, COUNT-Queries)

**Package Updates:** stimulus-bundle 3.0, ux-turbo 3.0, ux-chartjs 3.0, migrations-bundle 4.0, monolog-bundle 4.0

### 📚 Documentation
- docs/ Cleanup: 115→73 aktive Docs (38 geloescht, 21 archiviert)
- BSI-Specialist Skill + 8 Referenzdateien, alle Specialist-References aktualisiert
- WCAG 2.1→2.2, NIS2UmsuCG, ISO 22313:2023, ISO 27701:2025
- IMPROVEMENT_PROJECTS.md, FORM_UX_IMPROVEMENTS.md, KPI_IMPROVEMENT_PLAN.md

---

## [2.5.1] - 2025-12-15

### 🐛 Bug Fixes

#### Form DateTime Handling
- **Fixed DateTime/DateTimeImmutable type mismatch** in forms with DATETIME_IMMUTABLE columns
- Affected forms: RiskAppetiteType, ConsentType, CrisisTeamType, MfaTokenType, WorkflowInstanceType
- Added `'input' => 'datetime_immutable'` option to DateTimeType fields

#### Console Commands (PHPStan Fixes)
- **SendNotificationsCommand** - Removed redundant variable assignments
- **RiskTreatmentPlanMonitorCommand** - Fixed undefined variable error
- **Nis2NotificationCommand** - Fixed undefined variable error
- **SendReviewRemindersCommand** - Added early return for breachesOnly mode
- **AnalyzeMappingQualityCommand** - Fixed `$lowQuality` variable name
- **GenerateIsoProceduresCommand** - Removed redundant assignment

#### Controller Fixes
- **ComplianceController** - Initialize `$fulfillment` variable before conditional block

---

## [2.5.0] - 2025-12-15

### 🚀 Phase 7: Management Dashboard & Compliance Wizard

Major release introducing management-level reporting, compliance assessment wizards, and DORA compliance tracking.

### ✨ Added

#### Compliance Wizard
- **Interactive assessment wizards** for ISO 27001, TISAX AL2/AL3, BSI IT-Grundschutz
- **Category-based assessments** with progress tracking
- **Real-time compliance scoring** with radar charts
- **Gap analysis** with prioritized recommendations
- **PDF export** of assessment results
- **Framework comparison** tool for multi-framework environments

#### Management Reports
- **Executive Summary Report** - High-level KPIs for C-level management
- **Risk Management Report** - Detailed risk analysis with trends
- **BCM Report** - Business continuity metrics and RTO/RPO analysis
- **Compliance Report** - Framework status and gap overview
- **Audit Report** - Audit findings and follow-up status
- **GDPR Report** - Data protection compliance metrics
- **Asset Report** - Asset inventory and criticality analysis
- **PDF & Excel export** for all reports

#### DORA Compliance Dashboard
- **EU-DORA regulation tracking** (Digital Operational Resilience Act)
- **ICT Risk Management** module status
- **Incident Reporting** timeline tracking (24h/72h deadlines)
- **Third-Party Risk** management overview
- **Resilience Testing** schedule and results
- **Information Sharing** requirements status

#### Dashboard Improvements
- **Compliance Status Widget** with framework breakdown
- **Management KPIs Widget** with trend indicators
- **Quick action buttons** for common tasks

### 🔧 Changed

#### Setup Wizard Enhancements
- **Loading spinners** on all Step 3 forms for visual feedback
- **Timeout prevention** for database operations (schema creation, migrations, backup restore)
- **Improved error handling** with detailed logging

#### Translation System
- Fixed compliance translations (moved `dashboard` and `mapping_quality` to correct namespace)
- Added loading state translations for setup wizard
- Fixed inconsistent translation key formats

### 🐛 Fixed
- Route errors in compliance wizard (`app_admin_module_index` → `admin_modules_index`)
- Route errors in management reports (`app_control_index` → `app_soa_index`)
- Translation domain errors (`risks` → `risk`)
- YAML duplicate key errors in compliance translation files

### 📊 Statistics
- **22 new template files** for wizards and reports
- **8 new translation files** (wizard, dora, kpi, management_reports)
- **375 templates** validated without errors
- **0 missing translations** for German locale

---

## [2.2.4] - 2025-12-10

### 🌍 Internationalization - Complete i18n Cleanup

Major effort to eliminate all hardcoded text and fix translation domain issues across the entire application.

### ✅ Fixed

#### Translation Domain Corrections
- **56 instances** of `'audits'` → `'audit'` domain
- **2 instances** of `'controls'` → `'control'` domain
- Created new `notifications.{de,en}.yaml` translation files

#### Hardcoded Text Translations (5 Templates)
- `user_management/import.html.twig` - 14 German texts translated
- `data_management/export.html.twig` - 12 English texts translated
- `role_management/compare.html.twig` - 8 German texts translated
- `business_process/index.html.twig` - 16 German texts translated
- `compliance/mapping_quality/dashboard.html.twig` - 6 texts translated

#### Accessibility Improvements
- **21 instances** of hardcoded `aria-label="Actions"` replaced with `trans()`
- Added translated aria-labels to mapping_quality dashboard buttons

### 🔧 Changed
- Updated `check_translation_issues.py` script with `setup`, `notifications` domains
- Translation issues reduced from **215 → 70** (remaining are false positives)

### 📊 Statistics
- **44 files changed** (32 templates, 12 translation files)
- **0 HARDCODED_TEXT** issues remaining
- **0 INVALID_DOMAIN** issues remaining

---

## [2.2.3] - 2025-12-09

### 🌍 Internationalization - PDF, Email & Setup Templates

### ✅ Fixed
- PDF templates fully internationalized (dashboard, risks, incidents, trainings)
- Email templates translated (review_reminder, data_breach_deadline_reminder)
- Audit checklist templates (`_checklist_items.html.twig`, `checklist.html.twig`)
- Setup wizard templates (`step1-5`) with new `setup.{de,en}.yaml` files
- Extended `window.translations` in base.html.twig for JavaScript i18n

### 📊 Statistics
- **34 files changed** (1147 insertions, 363 deletions)

---

## [2.2.2] - 2025-12-08

### 🐛 Bug Fixes

#### CI/CD Pipeline Fixes
- Fix PHPUnit test environment setup
- Create `isms_test` database with root privileges
- Add missing environment variables for tests
- Create `setup_complete.lock` to bypass setup wizard in tests

#### Dependency Updates
- Bump PHP dependencies to latest compatible versions
- Update composer.lock

---

## [2.2.1] - 2025-11-29

### 🚀 Proactive Compliance Monitoring

First release with automated review reminders and visual risk slider component.

### ✨ Features
- **ReviewReminderService** - Tracks overdue reviews across all ISMS entities
- **SendReviewRemindersCommand** - Cron-compatible with `--dry-run`, `--breaches-only` options
- **Risk Slider Component** - Interactive 5x5 matrix with quick presets
- **Dashboard Widget** - Shows overdue items with direct links

### 🐛 Bug Fixes
- Symfony 7.4 compatibility for console command Option attributes

---

## [2.2.0] - 2025-11-29

### 🚀 Major Features: Automated Review Reminders & Visual Risk Slider

This release introduces proactive compliance monitoring with automated review reminders for GDPR, ISO 27001, and ISO 22301 requirements, plus an interactive risk slider component for enhanced UX.

### ✨ New Features

#### Automated Review Reminder System
- **ReviewReminderService** - Central service tracking overdue reviews across all ISMS entities
  - Risk reviews (ISO 27001 Clause 6.1.3.d)
  - BC Plan reviews and tests (ISO 22301)
  - Processing Activity reviews (VVT/ROPA - GDPR Art. 30)
  - DPIA reviews (GDPR Art. 35.11)
  - Data Breach 72h notification deadlines (GDPR Art. 33)
- **SendReviewRemindersCommand** - Cron-compatible console command
  - `--dry-run` - Preview without sending
  - `--include-upcoming` - Include items due in next 14 days
  - `--breaches-only` - Hourly check for 72h breach deadlines
  - `--stats-only` - Show statistics without notifications
- **Email Templates** - Professional notification emails
  - `review_reminder.html.twig` - Generic review reminders
  - `data_breach_deadline_reminder.html.twig` - Urgent 72h GDPR breach alerts with color-coded urgency
- **Dashboard Widget** - `_overdue_reviews_widget.html.twig`
  - Shows overdue items by category
  - Highlights urgent data breaches
  - Direct links to affected entities

#### Interactive Risk Slider Component
- **risk_slider_controller.js** - Stimulus controller for visual risk scoring
  - Interactive sliders for probability and impact (1-5 scale)
  - Real-time risk score calculation
  - Color-coded risk levels (ISO 27005 aligned)
  - Clickable 5x5 risk matrix for direct value selection
  - Quick preset buttons (Low, Medium, High, Critical)
  - Bilingual labels (German/English)
- **_risk_slider.html.twig** - Reusable Twig component
  - Customizable via parameters (show_matrix, show_presets, compact mode)
  - Hidden form fields for form submission
  - Accessible with ARIA labels

### 🐛 Bug Fixes

#### Symfony 7.4 Compatibility
- **Fixed Option attribute syntax** - Removed invalid `mode` parameter in console commands
  - `SendReviewRemindersCommand.php` - Fixed 4 Option attributes
  - `AuditLogCleanupCommand.php` - Fixed 2 Option attributes
- Symfony 7.4's `#[Option]` attribute only supports: `description`, `name`, `shortcut`, `suggestedValues`

### 🌍 Internationalization

#### New Translations (DE/EN)
- **dashboard.*.yaml** - Widget translations for overdue reviews
- **risk.*.yaml** - Risk slider labels (probability, impact, level, presets, matrix_help)

### 📊 Statistics
- **7 New Files** created
- **5 Files** modified
- **~1,700 Lines** of new code
- **Compliance Coverage:**
  - GDPR Art. 33 (72h breach notification)
  - GDPR Art. 35.11 (DPIA review)
  - ISO 27001 Clause 6.1.3.d (risk review)
  - ISO 22301 (BC plan testing)

### 🕐 Recommended Cron Setup
```bash
# Daily at 8 AM for general review reminders
0 8 * * * php bin/console app:review:send-reminders

# Hourly for urgent 72h breach deadline checks
0 * * * * php bin/console app:review:send-reminders --breaches-only
```

---

## [2.1.1] - 2025-11-28

### 🚀 Major Improvements: Code Quality, i18n & Stability

This release includes 39 commits with comprehensive code quality improvements, internationalization completion, and numerous bug fixes.

### ✨ New Features

#### Admin Settings Overhaul
- **Standardized Admin Forms** - All admin settings now use FormTypes with `_auto_form` component
- **Consistent UI** - Bootstrap 5.3 floating labels across all admin forms

#### Audit Module
- **PDF Export** - New professional PDF export template for internal audits
- **Improved Templates** - Removed non-existent auditType field references

#### Workflow Improvements
- **RiskTreatmentPlan Approval** - Complete approval workflow service
- **Document Approval** - Full document approval workflow implementation

### 🔧 Code Quality (Rector)

#### PHP 8.4 & Symfony 7.4 Best Practices
- Applied comprehensive Rector code quality improvements
- Added type declarations to all repository methods
- Type hints added to closures across controllers

#### Doctrine Entity Mappings
- Fixed `mappedBy` references after Rector property renames:
  - ThreatIntelligence, ComplianceMapping, ComplianceFramework, Tenant
- Added explicit `JoinColumn(name: ...)` to preserve database column names (14 entities)

### 🌍 Internationalization (i18n)

#### Translation Completion (~95%)
- **Added all missing translation keys** across all 49 domains
- **Consolidated duplicate translation files** and fixed structural issues
- **Improved translation domain configuration** across templates
- **Added `{% trans_default_domain %}** to templates needing it

### 🐛 Bug Fixes

#### Controller & Routing
- Fixed DataBreachController route naming duplication
- Fixed locale prefix duplication in routes
- Added locale prefix to keyboard navigation URLs

#### Templates
- Fixed BCM templates: entity properties, badge classes, route names
- Fixed workflow templates: WorkflowInstance property names
- Fixed compliance and change_request route names
- Fixed user_management/show.html.twig undefined routes
- Replaced deprecated `app.request.get()` with proper methods
- Aligned templates with controller variables

#### Forms
- Fixed IncidentType: `getIncidentNumber()` instead of `getReferenceNumber()`
- Corrected entity property mismatches across forms

#### Database
- Made `cvss_score` nullable for vulnerabilities without formal scoring
- Made `cve_id` nullable for internal vulnerability findings
- Renamed 'references' column to avoid MySQL reserved keyword

#### Miscellaneous
- Added graceful fallback for missing ext-zlib extension in backup
- Removed non-existent app_incident_assets route
- Fixed WorkflowInstanceRepository `currentStep` → `workflowStep` association

### 🧪 Testing
- Updated deprecated PHPUnit `isType('string')` to `isString()`
- Updated deprecated PHPUnit `isType('array')` to `isArray()`

### 📊 Statistics
- **39 Commits** since v2.1.0
- **49 Translation Domains** × 2 Languages = 97 YAML files
- **i18n Completion:** ~95%

---

## [2.1.0] - 2025-11-27

### 🚀 Major Features: Automated Workflows & Form System Overhaul

This release introduces comprehensive automated workflow capabilities for GDPR compliance, incident management, and approval processes, along with a complete overhaul of the form system.

### 🔄 Automated Workflows

#### GDPR Breach Wizard
- **72h Notification Timeline** - Visual countdown for mandatory GDPR breach notifications
- **Automatic Risk Assessment** - Severity classification based on data types and affected subjects
- **Notification Checklist** - Step-by-step guidance for DPA notification requirements
- **Stimulus Controller** - Interactive wizard with real-time validation

#### Incident Escalation Workflows
- **Auto-Escalation Rules** - Configurable triggers based on severity and category
- **Escalation Preview Panel** - Preview escalation path before triggering
- **Email Notifications** - Automatic alerts to escalation contacts
- **Workflow Integration** - Seamless connection with existing workflow engine

#### Approval Workflows
- **Risk Treatment Plan Approval** - Multi-step approval process for risk treatments
- **Document Approval** - Review and approval workflows for documents
- **Workflow Auto-Trigger** - Automatic workflow initiation on entity creation/update

#### Dashboard Integration
- **Workflow Widget** - Overview of pending workflow tasks on home dashboard
- **Quick Actions** - Direct links to approve/reject pending items
- **Status Indicators** - Visual representation of workflow progress

### 🔧 Form System Overhaul

#### Auto-Form Component
- **Unified Form Rendering** - All forms now use `_auto_form.html.twig` component
- **Consistent Styling** - Bootstrap 5.3 floating labels across all forms
- **Translation Integration** - Automatic domain-aware translations

#### Entity Property Fixes
- **Training Module** - Fixed property mismatches (duration→durationMinutes, trainer type)
- **Audit Module** - Corrected audit number generation timing
- **Management Review** - Fixed participants collection display
- **Admin Dashboard** - Corrected module statistics table names

### 🐛 Bug Fixes

#### Forms & Validation
- Fixed audit number generation (now generated before form validation)
- Fixed Training form fields to match entity properties
- Fixed Management Review participants Collection-to-String error
- Corrected TrainingController `getMandatory()` → `isMandatory()`

#### Templates
- Fixed audit/show.html.twig non-existent property errors
- Fixed management_review/index.html.twig Collection display
- Corrected training templates to use proper property names
- Fixed user_management/show.html.twig invalid route

#### Admin Dashboard
- Fixed module statistics with correct database table names:
  - `assets` → `asset`, `risks` → `risk`, `controls` → `control`
  - `incidents` → `incident`, `audits` → `internal_audit`
  - `compliance_requirements` → `compliance_requirement`
  - `trainings` → `training`

#### Translations
- Added missing audit index table translations
- Fixed training translation key conflicts (type/status)
- Added missing status translations for various modules

### 📊 Statistics
- **Tests:** 1689 tests, 5066 assertions (100% passing)
- **New Services:** 6 (GdprBreachAssessment, IncidentEscalationWorkflow, RiskTreatmentPlanApproval, DocumentApproval, WorkflowAutoTrigger, EmailNotification)
- **New Stimulus Controllers:** 2 (gdpr_breach_wizard, incident_escalation_preview)
- **New Templates:** 8 (workflow widget, breach wizard modal, escalation preview, email templates)

---

## [2.0.0] - 2025-11-26

### 🎉 Major Release: Complete UI/UX Overhaul & Internationalization

This major release represents a complete redesign of the user interface and navigation system, along with comprehensive internationalization support. Version 2.0 introduces breaking changes in the navigation structure and requires a fresh session after upgrade.

### 🚀 Major Features

#### Navigation & UI Revolution
- **Two-Level Mega Menu Navigation** - Complete redesign with primary and secondary navigation
  - Icon-based category navigation (Dashboard, ISMS Core, Assets & Risk, BCM, Privacy, Operations, Compliance, Admin)
  - Hover and click interactions with accessible keyboard navigation
  - Mobile-responsive with sidebar toggle
  - Integrated with Stimulus controllers for smooth interactions
- **Breadcrumb Navigation** - Consistent navigation across all pages
  - Added to 150+ templates (index, show, edit, new)
  - Home → Section → Page navigation pattern
  - Improves user orientation and navigation efficiency
- **Bootstrap 5.3 Floating Labels** - Modern form design across all forms
  - Automatic floating labels for text, email, password, number, textarea, select fields
  - Global Symfony form theme (`bootstrap_5_floating.html.twig`)
  - Applies to all 286+ form usages automatically
  - Dark mode support with proper color contrast
  - Maintains full WCAG 2.1 AA accessibility (ARIA, error handling)
- **Dark Mode** - Professional dark theme implementation
  - Theme-aware color system with CSS variables
  - Bootstrap 5.3 dark mode integration
  - Proper contrast ratios for WCAG AA compliance (100% on interactive elements)
  - Consistent styling across all components (cards, tables, forms, alerts)
  - Custom dark mode for mega menu, tables, compliance pages, and floating labels

#### Internationalization (i18n)
- **97 Translation Domains** - Organized by functional area instead of monolithic files
  - Domain-specific: `nav`, `mfa`, `tenant`, `assets`, `risks`, `controls`, etc.
  - Better organization and faster lookups
  - Reduced merge conflicts
- **3,290+ Translation Keys** - Complete German and English coverage
  - All UI elements, forms, and messages translated
  - Validation messages in dedicated `validators` domain
  - Navigation in dedicated `nav` domain
- **147 Templates Updated** - Added `trans_default_domain` to all templates
- **35 FormTypes Updated** - Replaced hardcoded placeholders and labels
- **Translation Quality Tools** - `scripts/quality/check_translation_issues.py`
  - Detect missing translations
  - Find hardcoded text
  - Validate translation domains

### 🔧 Technical Improvements

#### UI Components
- **Card Component Enhancement** - Added `titleDomain` parameter for flexible translations
- **Table Styling** - Comprehensive dark mode support with proper contrast
- **Alert Components** - Dark mode text visibility fixes
- **Bootstrap Variable Overrides** - `.bg-white` and `.bg-light` work correctly in dark mode

#### Code Quality
- **Removed Deprecated Navigation** - Cleaned up old navigation components
- **Unified Sidebar** - Single navigation system across entire application
- **Stimulus Controller** - Modern JavaScript with Stimulus for mega menu
- **CSS Organization** - Separated concerns (dark-mode.css, mega-menu.css, app.css)

### 🐛 Bug Fixes

#### Navigation
- Fixed mega menu z-index stacking issues (panel now renders at body level)
- Fixed route name mismatches in mega menu links
- Resolved duplicate Stimulus controller instances
- Fixed breadcrumb route names for admin dashboard

#### Dark Mode
- Fixed table background colors (proper contrast in dark mode)
- Fixed text visibility in alert-info components
- Fixed compliance page card backgrounds
- Fixed framework header gradients
- Overridden Bootstrap's bg-white/bg-light for dark mode compatibility

#### Translations
- Fixed missing `admin.nav.licensing` translation
- Fixed `admin.dashboard.*` title translations
- Removed duplicate/placeholder translations in admin.en.yaml
- Fixed card title translation domain issue

### 🔄 Breaking Changes

⚠️ **Navigation Structure Changed**
- Old sidebar navigation completely removed
- New mega menu structure requires session refresh
- Bookmarked URLs remain compatible
- Users need to clear browser cache for optimal experience

⚠️ **Translation Domain Changes**
- Moved from monolithic `messages.*.yaml` to domain-specific files
- Custom code using translations must specify domains explicitly
- Example: `{{ 'text'|trans({}, 'nav') }}` instead of `{{ 'text'|trans }}`

⚠️ **CSS Variable Changes**
- Dark mode now uses Bootstrap 5.3 CSS variables
- Custom themes may need adjustments
- `--bs-tertiary-bg`, `--bs-secondary-bg` redefined for better dark mode

### 📊 Statistics

- **Commits:** 20+ commits focusing on UI/UX
- **Files Changed:** 200+ files
- **Lines Changed:** 10,000+ lines
- **Translation Keys:** 3,290+ across 97 domains
- **Templates Updated:** 147 templates with proper i18n
- **Dark Mode Classes:** 50+ CSS rules for comprehensive coverage

### 🎯 Migration Guide

1. **Clear Sessions:** Run `php bin/console clear-sessions` after upgrade
2. **Clear Browser Cache:** Users should hard-refresh (Ctrl+Shift+R)
3. **Review Custom Translations:** Update to use new domain structure
4. **Test Dark Mode:** Verify custom styles work with new dark mode variables

### 🙏 Acknowledgments

Special thanks to all contributors who helped shape this major release through testing, feedback, and issue reports.

---

## [1.10.1] - 2025-11-21

### Fixed
**Critical Hotfix: Admin Login After Database Reset**

- **ROLE_ADMIN Tenant Deadlock** - Admin can now access system without tenant assignment
  - Changed tenant checks from `ROLE_SUPER_ADMIN` to `ROLE_ADMIN` in 4 controllers
  - Prevents "No tenant assigned to user" error after `./reset-database.sh`
  - Admin can manage tenants and assign themselves without deadlock
- **CSRF Token Errors After Updates** - Auto-clear sessions after `composer update`
  - Added `clear-sessions` script to composer.json
  - Prevents "Invalid CSRF token" errors after database reset/updates
  - Sessions automatically cleaned on composer post-update-cmd

### Changed
- HomeController: Allow ROLE_ADMIN without tenant (empty review/treatment data)
- ComplianceController: Allow ROLE_ADMIN without tenant (empty fulfillments)
- ComplianceFrameworkController: Allow ROLE_ADMIN without tenant (empty statistics)
- ComplianceRequirementController: Allow ROLE_ADMIN without tenant (read-only access)

### Technical
- Composer script: `clear-sessions` removes all session files
- Backward compatible: Regular users still require tenant assignment
- Admin behavior: Can view system but sees no tenant-specific data until assigned

---

## [1.10.0] - 2025-11-20

### Added

#### Risk Management (6 Priorities)

#### Priority 1.4: Risk Owner Mandatory (ISO 27001 Compliance)
- **Mandatory Risk Owner** - All risks must have an assigned owner (ISO 27001 requirement)
- **Validation** - Form validation ensures risk owner is set
- **Migration Support** - Existing risks updated to require owner

#### Priority 1.5: Periodic Review Workflow (ISO 27001 Clause 6.1.3.d)
- **Automated Review Tracking** - Periodic risk review reminders
- **Review Date Management** - Track last and next review dates
- **Overdue Notifications** - Alert on overdue risk reviews
- **Dashboard Widgets** - Overdue and upcoming review visibility

#### Priority 2.1: Risk Acceptance Workflow
- **Risk Acceptance Process** - Formal risk acceptance workflow
- **Acceptance Documentation** - Track acceptance decisions and justifications
- **Management Approval** - Workflow for management sign-off
- **Audit Trail** - Complete acceptance history

#### Priority 2.2: GDPR/DSGVO Risk Fields (High Impact)
- **Personal Data Flags** - Track if risk involves personal data
- **Special Category Data** - Flag for Art. 9 GDPR special categories
- **DPIA Requirement** - Automatic DPIA requirement detection
- **Risk Categories** - Financial, operational, compliance, strategic, reputational, security

#### Priority 2.3: Risk Assessment Guidance
- **Interactive Guidance** - Step-by-step risk assessment help
- **Best Practices** - ISO 27005 aligned guidance
- **Context Help** - Field-level tooltips and explanations

#### Priority 2.4: Treatment Plan Monitoring
- **Treatment Plan Widgets** - Dashboard monitoring for treatment plans
- **Overdue Tracking** - Identify overdue treatment plans
- **Progress Monitoring** - Track treatment plan completion
- **Approaching Deadlines** - 7-day warning for due plans

#### Multi-Subject Risk Support
- **Extended Risk Relationships** - Risks can now be linked to:
  - Assets (existing)
  - Persons (new)
  - Locations (new)
  - Suppliers (new)
- **Flexible Risk Modeling** - Better real-world risk representation
- **Template Updates** - All risk templates support multi-subject display

#### GDPR/DSGVO Features

#### CRITICAL-06: ProcessingActivity (VVT/ROPA - Art. 30 GDPR)
- **Complete VVT/ROPA Implementation** - Article 30 GDPR compliance
- **ProcessingActivity Entity** - Full data processing registry
- **VVT Controller** - CRUD operations for processing activities
- **VVT Service** - Business logic for Art. 30 compliance
- **PDF Export** - Professional VVT/ROPA reports
- **UI Integration** - Complete user interface

#### CRITICAL-07: DPIA (Data Protection Impact Assessment)
- **DPIA Entity** - Complete DPIA implementation
- **DPIA Workflow** - Structured assessment process
- **Risk Scoring** - Automated DPIA risk calculation
- **Threshold Triggers** - Automatic DPIA requirement detection
- **DPIA Templates** - Index, show, and form templates
- **Integration** - Links to ProcessingActivity and Risks

#### CRITICAL-08: DataBreach (72h Notification Requirement)
- **DataBreach Entity** - Art. 33/34 GDPR compliance
- **72-Hour Tracking** - Automatic deadline calculation
- **Severity Assessment** - Impact and likelihood scoring
- **Notification Management** - Track authority and data subject notifications
- **DataBreach Service** - Business logic and deadline tracking
- **Complete UI** - Index, show, create, edit templates

#### Business Continuity Management
- **Incident ↔ BusinessProcess** - Link incidents to affected processes
- **Impact Analysis** - Calculate incident impact on BCM
- **IncidentBCMImpactService** - Automated impact assessment
- **RTO/RPO Impact** - Track recovery time/point objectives

#### UI/UX Improvements

**Badge Standardization** (Issues 5.1 & 5.2)
- **BadgeExtension Twig Helper** - Centralized badge rendering
- **Consistent Styling** - Standardized colors and icons across all modules
- **Dark Mode Support** - All badges work in light and dark themes
- **32+ Table Migrations** - All major tables migrated to standardized components
  - Batch 3-27 completed (Admin, GDPR, BCM, Compliance, Risk, Asset, Audit, Workflow, etc.)

**Accessibility** (WCAG 2.1 AA)
- **Form Accessibility** - Complete form migration to WCAG 2.1 AA (Issues 2.1 & 2.3)
- **Skip Links** - Keyboard navigation improvements (Issue 13.1)
- **Screen Reader Support** - Proper ARIA labels and descriptions
- **Keyboard Navigation** - Full keyboard accessibility

**Navigation**
- **10 Missing Menu Entries** - Added navigation for implemented features
- **Privacy Hub** - New GDPR/Privacy central navigation point
- **Breadcrumb Fixes** - Improved navigation breadcrumbs
- **Menu Organization** - Better feature discoverability

#### Internationalization
- **Risk Module** - 94+ new translations (CRITICAL-04)
- **Risk Matrix Translations** - Complete German support
- **Person & Risk Appetite Pages** - Fully translated
- **Risk Treatment Plan** - All form sections translated
- **Risk Show Page** - Replaced all hardcoded English text

### Fixed
- **Null Tenant Checks** - Prevent TypeError when user has no tenant (4 controllers)
- **Security Event Handling** - Fixed null passport in login failures
- **PDF Template Fixes** - Corrected Risk entity property references
- **Form Type Fixes** - Control entity method corrections
- **GDPR Template Fixes** - VVT, DPIA, DataBreach template corrections

**Database & Migrations**
- **ProcessingActivity Migration** - Fixed missing table (deployment blocker)
- **Sequential Migration Fix** - Migrations now run cleanly from empty database
- **Reset Script Reliability** - Improved table detection (COUNT vs SHOW TABLES)
- **Foreign Key Order** - Correct entity dependency ordering

**Backup & Restore**
- **Risk Field Defaults** - Backward compatibility for old backups
  - Default category: 'operational'
  - Default GDPR flags: false
- **No Data Loss** - Old backups restore successfully with sensible defaults

**Testing**
- Test coverage improved to 60% (1618 tests, 4711 assertions)
- Fixed 32 test errors (non-existent entity method mocks)
- Suppressed expected warnings in backup/restore tests

### Changed
- Badge styling standardized across all modules (32 batches)
- Forms migrated to WCAG 2.1 AA accessibility standard
- Navigation menu expanded with 10 previously missing entries

### Technical Details
- 119 commits since v1.9.1
- 6 Risk Management priorities completed
- 3 GDPR features completed (VVT, DPIA, DataBreach)
- 94 German translations added
- All changes backward compatible

### Notes
Versions 1.8.x - 1.9.1 were not documented in CHANGELOG. This release consolidates those undocumented changes.

---

## [1.7.1] - 2025-11-17 - Critical Hotfix: Backup Restore Functionality

### Fixed

#### Critical Restore Issues
- **Foreign Key Constraint Violations** - Disabled FK checks during restore (SET FOREIGN_KEY_CHECKS = 0)
- **Entity ID Preservation** - Original IDs from backup are now preserved using AssignedGenerator
- **DateTime Type Mismatches** - Automatic conversion between DateTime and DateTimeImmutable
- **Lifecycle Callback Conflicts** - Disabled PrePersist/PreUpdate listeners during restore
- **Entity Dependency Ordering** - Fixed Asset, Supplier, InterestedParty to load before Risk

#### Entity DateTime Fixes
- **Supplier Entity** - Fixed updateTimestamps() to use DateTime instead of DateTimeImmutable
- **InterestedParty Entity** - Fixed updateTimestamps() to use DateTime instead of DateTimeImmutable

#### Password Security
- **Admin Password Option** - Option to set admin password during restore (passwords not stored in backups for security)
- **Password Warning** - Clear warning when no admin password is provided
- **Setup Wizard Support** - Required admin password field in setup wizard restore

### Added

#### User Experience
- **Admin Password Field** - Form field to set admin password during restore
- **Security Information** - Clear explanation why passwords are not in backups
- **Automatic Password Setting** - First admin user gets password set after restore

---

## [1.7.0] - 2025-11-17 - Backup/Restore System Overhaul & Setup Wizard Integration

### Added

#### Backup Restore in Setup Wizard
- **Setup Step 9 Integration** - Restore backups directly during initial setup
- **Migration Support** - Easy migration from other Little ISMS Helper instances
- **Clear Before Restore Option** - Clean restore for consistent database state
- **File Upload Support** - Upload .json or .gz backup files during setup

#### Enhanced Restore Service
- **ManyToOne Relation Support** - Associations are now restored (not just scalar fields)
- **Unique Constraint Detection** - Prevents duplicate key errors for Role, Permission, User, Tenant, ComplianceFramework, Control, ComplianceRequirement
- **Entity Dependency Ordering** - 30+ entities ordered by foreign key dependencies
- **Clear Before Restore** - Option to delete all existing data before restore
- **Robust Error Recovery** - EntityManager state checks and safe rollback

#### Project Support
- **BuyMeACoffee Link** - Support development via donations (README.md)

### Fixed

#### Backup Modal Display Issues
- **Custom Modal Pattern** - Replaced Bootstrap Modal JS with custom CSS-based modals
- **Consistent with Global Search** - Same pattern as working command palette
- **Proper Scrolling** - Modal body scrolls, header/footer fixed
- **Backdrop and ESC Handling** - Click outside or ESC to close

#### Database Restore Reliability
- **EntityManager Close Prevention** - Checks if EM is open before operations
- **Safe Rollback Logic** - Handles closed EntityManager during rollback
- **Flush Error Handling** - Catches and logs constraint violations without crashing
- **Transaction Safety** - Proper transaction management throughout restore

#### Unique Constraint Conflicts
- **ID + Unique Field Lookup** - Finds existing entities by both primary key and unique constraints
- **Conflict Detection** - Warns when backup ID wants a value owned by different existing ID
- **Skip with Warning** - Gracefully skips conflicts instead of crashing

### Changed

#### CLAUDE.md Optimization
- **Pre-Commit/Push Checklist** - Mandatory quality checks before commits
- **Token Efficiency** - Reduced from ~254 to 134 lines (47% reduction)
- **Common Pitfalls Section** - Documents solved issues for future reference
- **Focused Content** - Essential information only, external docs when needed

#### RestoreService Improvements
- **Entity Priority Map** - Extended to 30+ entities with proper dependency order
- **Association Restoration** - Uses `getReference()` for ManyToOne relations
- **Better Logging** - Debug logs for conflict detection and entity processing
- **Statistics Tracking** - Tracks cleared, created, updated, skipped, error counts

### Security

- **Backup File Validation** - Only .json and .gz files accepted
- **CSRF Protection** - All restore forms protected
- **Tenant Isolation** - Backup/restore respects multi-tenant boundaries
- **Audit Logging** - All restore operations logged

### Statistics
- **~800 new lines of code** in RestoreService
- **~200 new lines** in DeploymentWizardController
- **~120 lines** of custom modal CSS
- **6 files modified** (RestoreService, AdminBackupController, DeploymentWizardController, backup.html.twig, step9_sample_data.html.twig, README.md)
- **1 file optimized** (CLAUDE.md - 47% smaller)
- **909 tests passing** (2573 assertions)

### Documentation
- Updated CLAUDE.md with pre-commit/push quality checklist
- Added common pitfalls and troubleshooting guide
- Documented Modal and Turbo patterns
- Security checklist for new features

---

## [1.6.4] - 2025-11-16 - Compliance Framework CRUD & Workflow Builder

### Added - Phase 6C & 6D Complete

#### Compliance Framework CRUD (Phase 6D)
- **ComplianceFrameworkController** - Full CRUD operations for frameworks
- **Framework Index Page** - List, search, and manage compliance frameworks
- **Framework Show Page** - Detailed view with requirements and mappings
- **Framework Create/Edit** - Form-based framework management
- **Cache Invalidation Subscriber** - Automatic cache clearing on framework changes
- **ComplianceExtension Twig** - Helper functions for compliance templates

#### Workflow Builder (Phase 6C)
- **Visual Workflow Builder** - Drag-and-drop step management
- **WorkflowStepApiController** - RESTful API for workflow steps (554 lines)
- **WorkflowStepType Form** - Comprehensive step configuration
- **Sidebar Dropdown Controller** - Interactive navigation for workflows
- **workflow_builder_controller.js** - Frontend logic (494 lines)

#### Enhanced Service Tests (~5,000 new test lines)
- **AssetServiceTest** (282 lines)
- **AuditLoggerTest** (420 lines)
- **AutomatedGapAnalysisServiceTest** (309 lines)
- **ComplianceAssessmentServiceTest** (481 lines)
- **ControlServiceTest** (258 lines)
- **CorporateStructureServiceTest** (280 lines)
- **DashboardStatisticsServiceTest** (228 lines)
- **DocumentServiceTest** (300 lines)
- **ISMSContextServiceTest** (486 lines)
- **ISMSObjectiveServiceTest** (289 lines)
- **MfaServiceTest** (428 lines)
- **RiskServiceTest** (331 lines)
- **SecurityEventLoggerTest** (473 lines)
- **SupplierServiceTest** (399 lines)
- **WorkflowServiceTest** (333 lines)
- **WorkflowStepApiControllerTest** (459 lines)

#### Navigation & UX Improvements
- **Navigation UX Analysis** - Comprehensive documentation
- **Navigation Patterns Quick Reference** - Best practices guide
- **Improved Breadcrumb Component** - Better hierarchy display
- **Enhanced Page Headers** - Consistent styling

### Fixed

#### Test Infrastructure (59 failures resolved)
- **Symfony 7 Compatibility** - Exception handling updates
- **PHPUnit 10 Compatibility** - Test method signatures fixed
- **Entity Validation** - Constraints aligned with controller expectations
- **Mock Object Improvements** - Better test isolation

#### Security Improvements
- **CSRF Protection** - Added to all form submissions
- **XSS Protection** - Input sanitization for all user data
- **Entity Validation** - Server-side validation for all entities
- **Transaction Management** - Database operations wrapped in transactions
- **Robust Error Handling** - Graceful failure modes

#### Critical Runtime Fixes
- **Null Safety Checks** - Prevent runtime errors
- **AuditLog Property Names** - Corrected to `userName` in templates
- **ISMS Context Tenant Isolation** - Respects current user's tenant
- **MfaToken Entity** - Added missing properties
- **WorkflowStep Entity** - Extended with new fields

### Changed
- **ISMSContextService** - Improved tenant awareness (36+ lines)
- **MfaService** - Enhanced token validation (24+ lines)
- **AuditLogger** - Better error handling
- **BC Exercise Templates** - Improved forms (edit/new)
- **Business Continuity Plan Templates** - Better UX
- **Compliance Mapping/Requirement Templates** - Enhanced forms

### Statistics
- **~11,000+ new lines of code**
- **83 files modified**
- **16 new service test files** (~5,000 test lines)
- **4 new Compliance Framework templates**
- **1 new Workflow Builder UI**
- **59 test failures fixed**
- **137 new translation keys** (DE/EN each)

### Documentation
- [Navigation UX Analysis](NAVIGATION_UX_ANALYSIS.md)
- [Navigation Patterns Quick Reference](NAVIGATION_PATTERNS_QUICK_REFERENCE.md)
- [Updated ROADMAP - Phase 6C & 6D complete](ROADMAP.md)

---

## [1.6.2] - 2025-11-15 - ARM64 Support & CI/CD Fixes

### Added
- **ARM64/ARM Support** - Multi-architecture Docker builds (linux/amd64 + linux/arm64)
- **QEMU Integration** for cross-platform compilation in CI/CD
- Support for Raspberry Pi, Apple Silicon, and other ARM-based systems

### Fixed
- **Trivy SARIF Upload** - Added security-events permission for vulnerability scan uploads
- **Docker Build Timeout** - Increased to 60 minutes for multi-architecture builds
- **Docker Hub Logo Upload** - Automated logo upload on release tags

### Changed
- Build timeout increased from 30 to 60 minutes for multi-arch support
- CI/CD pipeline now properly uploads security scan results to GitHub Security tab

### Technical Details
- Uses docker/setup-qemu-action@v3 for ARM64 emulation
- Uses docker/build-push-action@v5 with platforms: linux/amd64,linux/arm64
- Permissions block added: security-events: write, contents: read, actions: read

---

## [1.6.0] - 2025-11-15 - Enterprise Features ✅

### Added - Multi-Tenancy & Enterprise Management

#### Multi-Tenancy System
- **Corporate Structure Management** with parent-subsidiary relationships
- **Tenant Management UI** with logo upload and configuration
- **Corporate Governance System** with granular rules per control/scope
- **3-Level View Filters** (Own/Inherited/All) across all modules
- **Inheritance Indicators** showing data origin (parent/subsidiary)
- **Subsidiary View Support** in all repositories
- Automatic tenant isolation and data segregation
- Tenant-aware statistics (own/inherited/subsidiaries breakdown)

#### Unified Admin Panel
- **Admin Dashboard** with system overview and health metrics
- **System Configuration UI** with 50+ configurable settings
- **Tenant Management** (CRUD operations, logo upload)
- **User & Access Management** (user impersonation, session tracking)
- **Data Management** (backup, export, import functionality)
- **System Monitoring & Health Checks** with auto-fix capabilities
- **Module Management** with dependency-aware activation
- Vertical sidebar navigation for improved admin UX

#### Security & Access Control
- **Session Management System** with user_sessions table
- **Multi-Factor Authentication (MFA)** with TOTP and backup codes
- **Granular Permission System** with 100+ specific permissions
- **User Impersonation** for troubleshooting (audited)
- **Security Event Logging** to AuditLog database
- Enhanced CSRF protection and session security
- Comprehensive audit trail for all admin actions

#### German Compliance Frameworks
- **BSI IT-Grundschutz** (Security baseline for German organizations)
- **BaFin BAIT/VAIT** (Banking and insurance IT requirements)
- **DSGVO/GDPR** (Data protection compliance)
- **KRITIS** (Critical infrastructure security)
- **NIS2 Directive** (Network and information security)
- **TISAX** (Automotive industry security)
- **EU-DORA** (Digital operational resilience)
- **ISO 27701:2025** with 2019 ↔ 2025 version mapping

#### Compliance Enhancements
- **Module Dependency System** for compliance frameworks
- **Framework Version Support** (e.g., ISO 27701:2019 vs 2025)
- **Incremental Cross-Framework Mapping** generation
- **Click-Through Workflow** for framework compliance
- **Framework Comparison** with bidirectional coverage analysis
- **Gap Analysis** with priority-weighted risk scoring
- **Transitive Compliance** with impact scoring and ROI analysis
- **Mapping Quality Analysis** with Chart.js visualizations

#### Internationalization (i18n)
- **Complete German Translations** (~5,000 translation keys)
- **Complete English Translations** (~5,000 translation keys)
- **Validator Translations** for all form fields (DE/EN)
- **Message Translations** for all UI components (DE/EN)
- Translation verification tools and reports
- Zero missing translations across all modules

#### Accessibility (WCAG 2.1 AA)
- **WCAG 2.1 AA Compliant Forms** across all modules
- **Accessible Bulk Delete Dialogs** with confirmation
- **Table Scope Attributes** and ARIA labels
- **Keyboard Navigation** support throughout
- **Screen Reader Optimization** for all interactive elements
- Semantic HTML and proper heading hierarchy

#### Testing & Quality Assurance
- **60%+ Test Coverage** (up from 40%)
- **400+ Unit Tests** across all services
- **Entity Tests** for all 31 entities
- **Service Tests** for critical business logic
- Comprehensive validation and security tests
- CI/CD with automated test runs

#### Reports & Exports
- **Professional PDF Reports** with clean layout
- **Excel Exports** with multi-tab support
- **CSV Exports** for all compliance modules
- Framework comparison reports
- Gap analysis with root cause analysis
- Data reuse insights with ROI calculations

#### UI/UX Improvements
- **Vertical Sidebar Navigation** replacing horizontal menu
- **Intensified Cyberpunk Fairy Theme** with enhanced effects
- **Clean CSS** - removed 500+ inline styles
- **Responsive Design** improvements for mobile
- **Advanced Filters** on all major modules
- **Audit History Integration** on detail pages
- Standardized page headers and layouts
- Improved table readability and sorting

#### Database & Infrastructure
- **Database Setup Wizard** with web-based installation
- **Automatic Directory Creation** on install/update
- **Health Monitoring** with auto-fix scripts
- **Migration System** for tenant_id setup (31 entities)
- Rollback scripts and comprehensive upgrade guides
- Idempotent migrations with safety checks

#### Docker & DevOps
- **Docker Health Checks** with HTTP redirect support
- **Dedicated /health Endpoint** for container monitoring
- **CI/CD Improvements** - Docker builds only on release tags
- **Docker Hub Integration** with automated logo upload
- **Security Hardening** and data persistence guarantees
- **Trivy Vulnerability Scanning** on all images
- Simplified deployment with wizard integration

### Changed
- **Navigation Structure** - Horizontal menu → Vertical sidebar
- **Admin Features** - Centralized in unified admin panel
- **Module Management** - Moved to admin panel with dependencies
- **Framework Management** - Centralized in admin compliance section
- **CI/CD Pipeline** - Docker builds only on version tags (not every PR)
- **License Compliance** - Enhanced with graceful error handling
- **Session Security** - SameSite=Lax for better compatibility

### Fixed
- **500+ Critical Bug Fixes** from community testing
- **Docker Compose CI/CD** pipeline issues resolved
- **CSRF Token Issues** on login page (cache-control headers)
- **Migration Timestamp** ordering for proper execution
- **Duplicate Translation Keys** (100+ duplicates removed)
- **YAML Syntax Errors** in translation files
- **Null Safety** in SQL queries and entity relationships
- **Doctrine DBAL 4.x** compatibility (replaced deprecated methods)
- **Open Basedir Restrictions** for session storage
- **EntityManager Detached Entity** errors in batch processing
- **Workflow Step 3** field reference (statistics.fulfilled)
- **User Form** - Pre-fill roles and status when editing
- **Risk/Asset Forms** - Improved validation and error handling

### Security
- **Session Hijacking Prevention** with session fingerprinting
- **MFA/TOTP** for enhanced account security
- **Granular Permissions** replacing coarse ROLE_* system
- **Audit Logging** for all security-relevant events
- **CVE Analysis** and mitigation for all dependencies
- **License Compliance** enforcement in CI/CD
- **Rate Limiting** and secrets management
- **HTTPS Support** with automatic HTTP redirect

### Statistics
- **~25,000 new lines of code**
- **300+ new/modified files**
- **31 entities** with multi-tenancy support
- **7 new German compliance frameworks**
- **100+ granular permissions**
- **5,000+ translation keys** (DE/EN)
- **60%+ test coverage**
- **400+ unit tests**
- **🎉 Enterprise-Ready** - Multi-tenancy, MFA, comprehensive admin panel!

### Documentation
- [Corporate Structure Documentation](docs/corporate-structure/)
- [Multi-Tenancy Setup Guide](docs/multi-tenancy/)
- [Admin Panel User Guide](docs/admin-panel.md)
- [MFA/TOTP Setup](docs/mfa-setup.md)
- [Translation System](docs/i18n-system.md)
- [WCAG 2.1 AA Compliance](docs/accessibility.md)
- [License Compliance Report](docs/reports/license-report.md)
- [Phase 6 Implementation](docs/PHASE6_*)

### Upgrade Guide

**From 1.5.x to 1.6.0:**

⚠️ **BREAKING CHANGES** - This is a major update with multi-tenancy!

**1. Database Migration:**
```bash
# Backup your database first!
php bin/console doctrine:migrations:migrate

# Add tenant_id to all entities (automated)
php bin/console app:migrate-tenant-columns
```

**2. Initial Tenant Setup:**
```bash
# Create your first tenant (required!)
php bin/console app:setup-tenant --name="Your Company" --code="COMPANY"
```

**3. Setup Granular Permissions:**
```bash
# Migrate from role-based to permission-based access
php bin/console app:setup-permissions
```

**4. MFA Setup (Optional):**
```bash
# Enable MFA for enhanced security
# Users can activate TOTP in their profile settings
```

**5. Load German Frameworks (Optional):**
```bash
php bin/console app:load-framework bsi
php bin/console app:load-framework bafin
php bin/console app:load-framework dsgvo
# ... etc
```

**6. Clear Cache:**
```bash
php bin/console cache:clear
```

**New Routes:**
- `/admin` - Unified admin panel
- `/admin/dashboard` - Admin dashboard
- `/admin/tenants` - Tenant management
- `/admin/system` - System configuration
- `/admin/monitoring` - Health checks
- `/health` - Docker health endpoint

---

## [1.5.0] - 2025-11-07 - Phase 5 Complete ✅

### Added - Reporting & Integration

#### PDF/Excel Export System
- Professional PDF reports for 5 core modules (Dashboard, Risk Register, SoA, Incidents, Training)
- Excel exports with styled headers, zebra striping, and auto-sized columns
- ReportController with 11 export endpoints
- PdfExportService using Dompdf 3.1.4
- ExcelExportService using PhpSpreadsheet 5.2.0
- Color-coded risk levels and progress bars in reports

#### Notification Scheduler
- Automated email notifications via cron command (`app:send-notifications`)
- 5 notification types: Upcoming Audits, Trainings, Open Incidents, Controls Nearing Target Date, Overdue Workflows
- Configurable notification windows (--days-ahead, --type, --dry-run)
- Professional HTML email templates with responsive styling
- EmailNotificationService with 6 notification methods

#### REST API
- API Platform 4.2.3 integration
- 30 CRUD endpoints across 6 resources (Assets, Risks, Controls, Incidents, Internal Audits, Trainings)
- OpenAPI 3.0 specification with interactive documentation
- Swagger UI and ReDoc interfaces at `/api/docs`
- Session-based authentication with role-based security
- Pagination (30 items per page, customizable)
- JSON-LD and JSON format support

#### Premium Features - Paket B (Quick View & Global Search)
- Global Search with Cmd+K/Ctrl+K shortcut
- Quick View modal with Space shortcut on list items
- Smart filter presets for quick data filtering
- Search across all entities (Assets, Risks, Controls, Incidents, Trainings)
- Keyboard navigation support

#### Premium Features - Paket C (Dark Mode & User Preferences)
- Dark Mode with automatic system preference detection
- Theme toggle with LocalStorage persistence
- User Preferences system (view density, animations, keyboard shortcuts)
- Notification Center with in-app notifications and history
- Export/Import preferences functionality

#### Premium Features - Paket E (Drag & Drop Interactions) ✨ NEW!
- **Dashboard Widget Drag & Drop** with native HTML5 API
  - Widget reordering via drag and drop
  - Visual drag feedback with CSS animations
  - LocalStorage persistence of widget order
  - Automatic restoration on page load
  - Extended dashboard_customizer_controller.js (+120 lines to 276 total)
- **File Upload Drag & Drop** for Document Management
  - Modern drag & drop zone with visual feedback
  - Multi-file upload support (upload multiple files simultaneously)
  - File type validation (PDF, Word, Excel, Images, Text)
  - File size validation (max 10MB per file)
  - File preview list with MIME-type icons
  - Remove individual files before upload
  - Error toast notifications
  - Dark mode support
  - Mobile responsive design
  - New file_upload_controller.js (346 lines)
  - New document/new_modern.html.twig template (378 lines)

#### Bulk Actions Integration
- Bulk Actions for 4 modules: Asset, Risk, Incident, Training Management
- Select All checkbox with individual item selection
- Floating action bar (appears on selection)
- Bulk operations: Export (CSV), Assign, Delete
- Confirmation dialogs for destructive actions
- Success notifications

#### Audit Log Timeline View
- Timeline component with vertical timeline visualization
- Tab navigation between Table and Timeline views
- Grouped entries by date
- Color-coded action markers:
  - 🟢 Create (Green #28a745)
  - 🟡 Update (Yellow #ffc107)
  - 🔴 Delete (Red #dc3545)
  - 🔵 View (Blue #17a2b8)
  - ⚫ Export/Import (Gray/Purple #6c757d / #6f42c1)
- User attribution and entity links
- Dark mode compatible
- Mobile responsive

### Changed
- Document Management foundation laid (Entity and Repository only, full implementation deferred)
- DocumentController now uses modern templates (index_modern.html.twig, new_modern.html.twig)

### Statistics
- **~3,500 new lines of code** (Phase 5 total including Drag & Drop)
- **21 new/modified files**
- **30 API endpoints**
- **10 report types (5 PDF + 5 Excel)**
- **5 notification types**
- **2 new Stimulus controllers** (file_upload_controller.js, dashboard_customizer extended)
- **🎉 100% Feature Complete** - All planned Phase 5 features implemented!

### Technical Highlights
- **Zero Heavy Dependencies** - Native HTML5 Drag & Drop APIs only
- **Progressive Enhancement** - Works without JavaScript fallback
- **Dark Mode Support** - All features dark mode compatible
- **Mobile Responsive** - Touch-optimized for mobile devices
- **LocalStorage Persistence** - Client-side state management

### Documentation
- [Phase 5 Final Features](docs/PHASE5_FINAL_FEATURES.md) - **100% Complete!**
- [Phase 5 Completeness Report](docs/PHASE5_COMPLETENESS_REPORT.md)
- [Phase 5 Paket B Documentation](docs/PHASE5_PAKET_B.md)
- [Phase 5 Paket C Documentation](docs/PHASE5_PAKET_C.md)
- [API Setup Guide](docs/API_SETUP.md)

---

## [1.4.0] - 2025-11-06 - Phase 4 Complete ✅

### Added - CRUD & Workflows

#### Form Types with Validation
- InternalAuditType.php (163 lines) - ISO 27001 Clause 9.2 compliant
- TrainingType.php (198 lines) - Comprehensive training management
- ControlType.php (179 lines) - ISO 27001:2022 Annex A control management
- ManagementReviewType.php (180 lines) - ISO 27001 Clause 9.3 compliant
- ISMSContextType.php (151 lines) - ISO 27001 Clause 4 compliant

#### Controllers
- TrainingController.php (103 lines) - Full CRUD operations
- AuditController.php (143 lines) - Migrated to form-based architecture
- ManagementReviewController.php (113 lines) - Full CRUD operations
- ISMSObjectiveController.php (135 lines) - KPI tracking with progress visualization
- WorkflowController.php (197 lines) - Complete workflow management
- ContextController.php (65 lines) - Extended with edit functionality

#### Workflow Engine
- Workflow.php Entity (147 lines) - Workflow definitions
- WorkflowStep.php Entity (158 lines) - Individual workflow steps
- WorkflowInstance.php Entity (230 lines) - Running workflow instances
- WorkflowService.php (243 lines) - Workflow execution logic
- WorkflowRepository & WorkflowInstanceRepository with custom queries
- Support for approval/rejection/cancellation with audit trail

#### Risk Assessment Matrix
- RiskMatrixService.php (213 lines) - 5x5 matrix visualization
- Color-coded risk levels (Critical/Red, High/Orange, Medium/Yellow, Low/Green)
- Risk statistics and aggregations
- Matrix cell color calculation

#### Templates
- 30+ professional Bootstrap 5 templates
- Training templates (4 files, ~2,400 lines)
- Audit templates (3 files, ~2,800 lines)
- Management Review templates (4 files, ~2,500 lines)
- ISMS Objectives templates (4 files, ~1,200 lines)
- Turbo integration for real-time updates

### Fixed
- Security import compatibility with Symfony 7 (`Symfony\Bundle\SecurityBundle\Security`)
- API Platform routes deactivated (bundle not installed)

### Statistics
- **~15,000 new lines of code**
- **40+ new/modified files**
- **7 controllers (3 new, 4 updated)**
- **5 form types**
- **30+ templates**

### Documentation
- [Phase 4 Completeness Report](docs/PHASE4_COMPLETENESS_REPORT.md)

---

## [1.3.0] - 2025-11-05 - Phase 3 Complete ✅

### Added - User Management & Security

#### Authentication & Authorization
- Multi-provider authentication (Local, Azure OAuth 2.0, Azure SAML)
- User Entity with Azure AD integration
- Custom authenticators for OAuth and SAML flows
- Remember Me functionality (1 week)
- User impersonation for Super Admins (switch_user)

#### Role-Based Access Control (RBAC)
- Role Entity with system and custom roles support
- Permission Entity with granular access control
- 5 system roles: SUPER_ADMIN, ADMIN, MANAGER, AUDITOR, USER
- 29 default permissions across all modules
- Role hierarchy implementation
- UserVoter for fine-grained access control

#### Audit Logging
- AuditLog Entity with comprehensive change tracking
- AuditLogListener for automatic change detection
- Captures: entity type, entity ID, action, user, IP, user agent, old/new values
- AuditLogController with 5 views (index, detail, entity history, user activity, statistics)
- Audit log UI with filtering and search
- Automatically logs changes to 14+ entity types

#### Multi-Language Support
- Translation system for German (DE) and English (EN)
- 60+ translations in messages.de.yaml and messages.en.yaml
- Language switcher in navigation
- Route-based locale management

#### User Management UI
- UserManagementController with full CRUD (190 lines)
- 4 professional templates (47KB total)
- User activation/deactivation
- Role assignment (system + custom roles)
- Statistics dashboard
- Delete confirmation modals

### Statistics
- **~5,000 new lines of code**
- **25+ new files**
- **4 new entities (User, Role, Permission, AuditLog)**

### Documentation
- [Phase 3 Completeness Report](docs/PHASE3_COMPLETENESS_REPORT.md)
- [Authentication Setup Guide](docs/AUTHENTICATION_SETUP.md)
- [Audit Logging Documentation](docs/AUDIT_LOGGING.md)
- [Audit Logging Quickstart](docs/AUDIT_LOGGING_QUICKSTART.md)

---

## [1.2.0] - 2025-11-05 - Phase 2 Complete ✅

### Added - Data Reuse & Multi-Framework Compliance

#### Business Continuity Management (BCM)
- BusinessProcess Entity with BIA data (RTO, RPO, MTPD)
- Business impact scoring (financial, reputational, regulatory, operational)
- Process criticality assessment
- BCM → Asset protection requirements data reuse
- BusinessProcessController with full CRUD (208 lines)
- BusinessProcessType form (180 lines)
- 9 BCM templates with Turbo integration

#### Multi-Framework Compliance
- ComplianceFramework and ComplianceRequirement entities
- Hierarchical requirements (core → detailed → sub-requirements)
- TISAX (VDA ISA) - 32 requirements loaded
- EU-DORA - 30 requirements loaded
- LoadTisaxRequirementsCommand and LoadDoraRequirementsCommand

#### Cross-Framework Mappings
- ComplianceMapping Entity with bidirectional mappings
- Mapping types: weak, partial, full, exceeds (with percentages)
- ComplianceMappingService for data reuse analysis
- ComplianceAssessmentService for fulfillment calculations
- Transitive compliance calculation

#### Flexible Audit System
- InternalAudit with flexible scope types (full_isms, framework, asset, location, department)
- AuditChecklist Entity with verification status
- Compliance-framework-specific audits
- Asset-scoped audits

#### Entity Relationships (Data Reuse)
- Incident ↔ Asset (affectedAssets, Many-to-Many)
- Incident ↔ Risk (realizedRisks, Many-to-Many)
- Control ↔ Asset (protectedAssets, Many-to-Many)
- Training ↔ Control (coveredControls, Many-to-Many)
- BusinessProcess ↔ Risk (identifiedRisks, Many-to-Many)

#### Automatic KPIs
- Asset: getRiskScore(), getProtectionStatus()
- Risk: wasAssessmentAccurate(), getRealizationCount()
- Control: getEffectivenessScore(), getTrainingStatus()
- Training: getTrainingEffectiveness()
- BusinessProcess: getProcessRiskLevel(), isCriticalityAligned()
- Incident: getTotalAssetImpact(), hasCriticalAssetsAffected()

#### Progressive Disclosure UI
- Tab-based navigation in framework dashboards
- Collapsible sections for hierarchical requirements
- Circular SVG progress charts with color coding
- Always-visible stats bar
- Filter panels (hidden by default)
- Reduced button clutter (~70% reduction)

#### Symfony UX Integration
- Stimulus controllers: toggle, chart, filter, modal, notification, csrf_protection, turbo
- Turbo Drive for fast navigation
- Turbo Frames for lazy loading
- Turbo Streams for real-time updates
- Auto-dismiss notifications

### Statistics
- **~1,600 new lines of code**
- **15+ new entities and services**
- **10 features fully implemented**

### Documentation
- [Phase 2 Completeness Report](docs/PHASE2_COMPLETENESS_REPORT.md)
- [Data Reuse Analysis](docs/DATA_REUSE_ANALYSIS.md)
- [UI/UX Implementation Guide](docs/UI_UX_IMPLEMENTATION.md)

---

## [1.1.0] - 2025-11-04 - Phase 1 Complete ✅

### Added - Core ISMS

#### Core Entities
- Asset Entity with CIA (Confidentiality, Integrity, Availability) ratings
- Risk Entity with likelihood/impact assessment and residual risk calculation
- Control Entity for ISO 27001:2022 Annex A controls (93 controls)
- Incident Entity with severity levels and data breach tracking
- InternalAudit Entity for ISO 27001 Clause 9.2
- ManagementReview Entity for ISO 27001 Clause 9.3
- Training Entity for awareness and competence tracking
- ISMSContext Entity for organizational context (Clause 4)
- ISMSObjective Entity for measurable security objectives

#### Controllers & Views
- AssetController with CRUD operations
- RiskController with risk assessment functionality
- StatementOfApplicabilityController for control management
- IncidentController with incident lifecycle tracking
- HomeController with KPI dashboard
- Basic Twig templates for all modules

#### Commands
- `isms:load-annex-a-controls` - Loads all 93 ISO 27001:2022 Annex A controls

#### Infrastructure
- Symfony 7.3 project setup
- PostgreSQL/MySQL database configuration
- Doctrine ORM with migrations
- Bootstrap 5 UI framework
- Twig templating

### Statistics
- **~8,000 lines of initial code**
- **9 core entities**
- **6 controllers**
- **Basic templates for all modules**

### Documentation
- Initial README.md
- Installation instructions
- Basic usage guide

---

## [1.0.0] - 2025-11-01 - Project Initialization

### Added
- Initial project structure
- Symfony 7.3 framework setup
- Basic configuration files
- Git repository initialization

---

## Version History Summary

| Version | Date | Phase | Status | LOC Added | Major Features |
|---------|------|-------|--------|-----------|----------------|
| 1.5.0 | 2025-11-07 | Phase 5 | ✅ Complete | ~2,050 | Reports, API, Notifications, Premium Features |
| 1.4.0 | 2025-11-06 | Phase 4 | ✅ Complete | ~15,000 | CRUD, Workflows, Risk Matrix |
| 1.3.0 | 2025-11-05 | Phase 3 | ✅ Complete | ~5,000 | User Management, RBAC, Audit Logging |
| 1.2.0 | 2025-11-05 | Phase 2 | ✅ Complete | ~1,600 | BCM, Multi-Framework, Data Reuse |
| 1.1.0 | 2025-11-04 | Phase 1 | ✅ Complete | ~8,000 | Core ISMS Entities |
| 1.0.0 | 2025-11-01 | Init | ✅ Complete | - | Project Setup |

**Total Lines of Code:** ~31,650+ lines

---

## Upgrade Guide

### From 1.4.x to 1.5.0

**New Dependencies:**
```bash
composer require "api-platform/core:^4.0"
composer require dompdf/dompdf
composer require phpoffice/phpspreadsheet
```

**Database Changes:**
```bash
# No new migrations, API attributes added to existing entities
php bin/console cache:clear
```

**New Routes:**
- `/reports` - Report dashboard
- `/reports/*/pdf` - PDF exports
- `/reports/*/excel` - Excel exports
- `/api` - REST API base
- `/api/docs` - API documentation

**Cron Setup:**
```bash
# Add to crontab for notification scheduler
0 8 * * * php /path/to/bin/console app:send-notifications --type=all
```

### From 1.3.x to 1.4.0

**Database Migrations:**
```bash
php bin/console doctrine:migrations:migrate
```

**New Entities:**
- Workflow
- WorkflowStep
- WorkflowInstance

**New Features:**
- Form-based CRUD for all modules
- Workflow approval system
- Risk assessment matrix

### From 1.2.x to 1.3.0

**Database Migrations:**
```bash
php bin/console doctrine:migrations:migrate
```

**Setup Permissions:**
```bash
php bin/console app:setup-permissions
php bin/console app:setup-permissions --admin-email=admin@example.com --admin-password=SecurePassword123!
```

**New Entities:**
- User
- Role
- Permission
- AuditLog

### From 1.1.x to 1.2.0

**Database Migrations:**
```bash
php bin/console doctrine:migrations:migrate
```

**Load Compliance Frameworks:**
```bash
php bin/console app:load-tisax-requirements
php bin/console app:load-dora-requirements
```

**New Entity:**
- BusinessProcess
- ComplianceFramework
- ComplianceRequirement
- ComplianceMapping
- AuditChecklist

---

## Contributors

Special thanks to all contributors who have helped shape Little ISMS Helper!

- Development led by the project maintainers
- Built with assistance from Claude AI (Anthropic)

---

## License

Proprietary - All rights reserved

---

**Maintained by:** moag1000
**Documentation:** [README.md](README.md) | [CONTRIBUTING.md](CONTRIBUTING.md)
**Support:** Open an issue on GitHub
