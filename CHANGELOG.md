# Changelog

Alle wesentlichen Aenderungen an diesem Projekt werden in dieser Datei dokumentiert.
Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

## [Unreleased]

_Noch keine Aenderungen._

## [3.3.2] — 2026-04-30

### Fixed
- **HTTP-Deployments hinter Reverse-Proxy**: prod-Config `cookie_secure: true` (hardcoded) brach Sessions ueber HTTP — jeder Request neue Session, CSRF-Token immer fail, Setup-Wizard-POST→302→Loop. Jetzt `'auto'` + trusted_proxies-Config fuer X-Forwarded-Proto.
- **Skip-Restore Race-Condition**: Symfony-Messenger Doctrine-Transport (`auto_setup: true`) legte parallel `messenger_messages` an waehrend `runFreshSchemaInstall` lief → Bulk-Batch crash "already exists". Idempotenter Batch via `CREATE TABLE IF NOT EXISTS` Munging.
- **Async-Job-Pattern**: `detachAndContinue()` Helper mit explizitem `ob_end_flush + flush` vor `fastcgi_finish_request()`. 5 Call-Sites umgestellt — POST-Hang gegen `output_buffering=4096` default behoben.
- **Step 8 Compliance-Frameworks**: Mandatory-Frameworks jetzt korrekt `disabled` gerendert + force-included server-side (DOM-Tampering-safe). Recommended bleibt pre-checked aber abwaehlbar.
- **`::placeholder` Ueberlagerung in Floating-Labels**: Aurora-Override hatte Bootstrap-Default ueberschrieben → Placeholder + Label uebereinander unleserlich. Jetzt transparent in `.form-floating`-Wrappern.
- **Schema-Drift Detection**: `SchemaExceptionSubscriber` redirected jetzt auch bei Schema-Drift ohne pending Migration (Column-Mismatch nach fehlgeschlagener Migration zeigte vorher 500).

### Added
- **Quick-Fix Reconcile-UI**: Drift-Branch mit SQL-Preview + Apply-Button (nur additive Statements; destructive blockiert mit CLI-Hinweis). Neuer Endpoint `/quick-fix/reconcile`.
- **ISMSContext Wizard-Seeding**: Step-6-Daten werden jetzt in den ISMS-Kontext (Clause 4) uebernommen — Organisationsname identisch zum Tenant, Scope-Starter aus Branchen/Land/Mitarbeiterzahl, Beschreibung in `internalIssues`. Idempotent — User-Edits werden nicht ueberschrieben.

### Changed
- nginx `fastcgi_read_timeout 1800s` + `fastcgi_buffering off` als Safety net fuer langsame Hardware (Pi/SD-Card-I/O).

## [3.3.1] — 2026-04-30

### Fix: Docker-Build wieder auf PHP 8.5 + Setup-Wizard ~36 % schneller

`v3.2.8` hatte das Docker-Image vorübergehend auf PHP 8.4 zurückgerollt,
weil der `docker-php-ext-install`-Pfad auf Debian Trixie + 8.5 brach
(`cp: modules/* No such file or directory`). Lösung: Build-Pfad auf das
gepflegte `mlocati/install-php-extensions:2`-Helper-Image umgestellt; das
löst die apt-Build-Deps + Cleanup automatisch und kommt mit PHP 8.5 klar.

Gleichzeitig wurde der Setup-Wizard-Pfad „Step 3 → Option 2 (Skip / Neu-
Installation)" merklich beschleunigt — aus ~62 Sekunden wurden ~40 Sek.
Die verbleibenden 40 s sind disk-bound (`innodb_flush_log_at_trx_commit=1`
des MariaDB-Defaults); ein DB-User mit SUPER-Recht halbiert das nochmals
(best-effort `SET GLOBAL innodb_flush_log_at_trx_commit=2`).

#### Docker

- **PHP 8.4 → 8.5.5** (`php:8.5-fpm-trixie@sha256:7d1586e8…`).
- **`docker-php-ext-install` → `mlocati/install-php-extensions:2`**: ein
  COPY + ein RUN, statt zerbrechlicher Configure-Compile-Cleanup-Dance.
- **Apt-Liste -8 Pakete**: `libzip-dev`, `libonig-dev`, `libpng-dev`,
  `libfreetype6-dev`, `libjpeg62-turbo-dev`, `libxml2-dev`, `libicu-dev`,
  `libmariadb-dev` werden vom Installer transient gezogen und nach
  Compile wieder entfernt → Image bleibt kompakt.
- **Dev-Stage**: `pecl install xdebug` + `linux-headers-generic` raus,
  `install-php-extensions xdebug` rein.
- Lokal verifiziert: `docker build --target production` 4 m 17 s,
  Container startet `healthy` in 6 s, Symfony 7.4.8 prod-boot OK.

#### Setup-Wizard Step 3 / Option 2

- **Redundanter `dropAndRecreateDatabase`-Call** (per-Table-Loop auf
  separater PDO-Verbindung, ~125 RTTs) entfernt — `runFreshSchemaInstall`
  macht den Drop bereits batched.
- **DROP DATABASE / CREATE DATABASE** statt 125 × `DROP TABLE` (Drop-
  Phase: 14.9 s → 8.2 s). Fallback auf per-Table-Loop, falls dem Setup-
  User die `DROP/CREATE DATABASE`-Privilegien fehlen.
- **`SET UNIQUE_CHECKS = 0`** während des CREATE-Bulks (Create-Exec-
  Phase: 46.0 s → 28.4 s).
- **Best-effort `SET GLOBAL innodb_flush_log_at_trx_commit = 2`** für die
  Dauer des Bulks; greift nur, wenn der Setup-User SUPER hat. Ohne SUPER
  kein Schaden.
- **Timing-Diagnose**: `runFreshSchemaInstall` schreibt jetzt eine
  `timings`-Map ins Resultat (metadata, drop, create_sql_gen, create_exec,
  migrations_register, total) — Logger-Eintrag in beiden Pfaden
  (`step3CreateSchema`, `step3RestoreBackupSkip`).
- **Alva-Hilfetext** kommuniziert jetzt offen die ~40 Sekunden Wartezeit
  und den SUPER-Recht-Tipp (DE + EN).

#### Tooling

- Neuer Konsolenbefehl `app:bench-schema-install` benchmarkt den exakt
  gleichen Code-Pfad gegen die aktuelle DB und gibt die Per-Phasen-
  Timings aus (DESTRUCTIVE — Test-DB only).

## [3.3.0] — 2026-04-29

Erstes Minor-Release nach `3.2.x` — bringt zwei substantielle neue Module
(Generic-SSO, Framework-Baselines), den GSTOOL-XML-Import-Pfad sowie i18n
für die MRIS-Baselines. Keine Breaking-Changes, alle Migrationen sind
additiv und idempotent.

### Feature: Generic SSO (OIDC/OAuth2)

Multi-IdP-Login mit Admin-Modul. Login-Seite zeigt Buttons nur für
aktive Provider; Tenant-scoped + globale IdPs koexistieren; Domain-
Bindung filtert Sichtbarkeit per E-Mail-Domain.

- OAuth2 Authorization-Code-Flow mit PKCE (S256), `state`-Schutz via
  `hash_equals`, Session-gestützte Nonce-Verwaltung.
- ID-Token-Verifikation gegen JWKS via `web-token/jwt-library`
  (RS256/RS384/RS512/PS256/ES256), Issuer/Audience/Exp/Iat-Checks.
- Discovery-Doc + JWKS-Cache (1h, Auto-Refresh bei unbekanntem `kid`).
- Client-Secret AEAD-verschlüsselt at-rest (XChaCha20-Poly1305-IETF mit
  BLAKE2b-abgeleitetem Schlüssel aus `kernel.secret`).
- JIT-Provisioning mit Approval-Queue (Default: Admin freigibt; opt-in
  Auto-Approve), domain-bound Account-Linking, Default-Rollen-Vergabe.
- Admin-UI `/{locale}/admin/sso` (CRUD, Toggle, Discovery-Test, Delete)
  + `/admin/sso/approvals` (Approve/Reject mit Begründung).
- ROLE_ADMIN für Tenant-IdPs, ROLE_SUPER_ADMIN für globale IdPs.
- Migration `Version20260429210000_generic_sso` legt
  `identity_provider`, `sso_user_approval` und
  `users.sso_external_id`/`users.sso_provider_id` an.

### Feature: Framework-Baselines (Industry-Maturity-Targets)

35 vorkonfigurierte Reife-Soll-Pakete pro Branche × Framework. Anwenden
setzt nur `maturityTarget` — Self-Assessments (Ist-Werte) bleiben
unangetastet.

| Framework | KRITIS | Finance | SaaS | Manufacturing | Healthcare |
|-----------|--------|---------|------|---------------|------------|
| ISO 27001:2022 (47 Annex-A) | ✓ | ✓ | ✓ | ✓ | ✓ |
| BSI IT-Grundschutz (113 Anf.) | ✓ | ✓ | ✓ | ✓ | ✓ |
| BSI C5:2020 (24 Kriterien) | ✓ | ✓ | ✓ | ✓ | ✓ |
| NIS2 Art. 21.2 (10 Maßnahmen) | ✓ | ✓ | ✓ | ✓ | ✓ |
| DORA (15 Artikel) | ✓ | ✓ | ✓ | ✓ | ✓ |
| TISAX/VDA-ISA (99 Controls) | ✓ | ✓ | ✓ | ✓ | ✓ |
| GDPR (16 Artikel) | ✓ | ✓ | ✓ | ✓ | ✓ |

Reasons referenzieren konkrete Aufsichtserwartungen: BSIG §8a/§8b,
KRITIS-Verordnung, BAIT, MaRisk, B3S-Gesundheit, KHZG (§ 75c SGB V),
MDR (EU 2017/745), DSGVO Art. 9, IEC 62443, TISAX VDA-ISA, BSI
TR-02102.

- Service `IndustryBaselineService` framework-agnostisch, locale-aware
  (DE/EN via `*_en`-Suffix), path-traversal-geschützt.
- Admin-UI `/{locale}/admin/industry-baselines` mit Framework-Listing,
  Per-Framework-Detail, Manager-gated Apply mit Dry-Run-Vorschau und
  Audit-Log-Eintrag (`compliance.baseline.apply`).
- 7 Unit-Tests (Loader, Locale, Dry-Run, Path-Traversal, alle 35
  YAMLs gegen Schema).

### Feature: GSTOOL-XML-Import (5 Phasen + Admin-UI)

Vollständiger Migrationspfad für GSTOOL/Verinice-Profile (Edition 2023).

- **Phase 1**: Zielobjekte → `Asset` (mit Schutzbedarf-Mapping
  vernachlässigbar/normal/hoch/sehr-hoch → 1..5).
- **Phase 2**: Modellierung → `Asset.dependsOn` (Abhängigkeitsgraph für
  BSI-3.6-Maximumprinzip).
- **Phase 3+4**: Bausteine + Maßnahmen → `ComplianceRequirement` +
  `Control` mit ISO-27001-Mapping.
- **Phase 5**: Risikoanalyse (BSI 200-3) → `Risk` mit
  Eintrittshäufigkeit/Schadenshöhe-Mapping (4-stufig BSI → 5-stufig
  Tool, Wert 3 übersprungen).
- Admin-UI `/admin/gstool-import` mit Upload + Tabbed-Preview (Bausteine/
  Maßnahmen/Risiken) + Commit-Button.
- XSLT-Wrapper für reale Verinice-Exporte (decoupling von Schema-
  Varianten).
- 1 neuer XML-Import-Command + Importer-Service + Tests.

### Feature: MRIS-Baselines i18n + 11 neue Branchen

19 MRIS-Branchen-Baselines bilingual (DE/EN via `*_en`-Suffix-Felder),
`MrisBaselineService` jetzt locale-aware via `RequestStack`.

Neue Branchen (zu den 8 bestehenden): Pharma, Telekommunikation,
Manufacturing-OT, Logistics, Retail, Education, Legal/Tax, Defense,
MSP, IT-Systemhaus, Software-Developer.

Hilfetext "Was tut eine MRIS-Baseline?" als Collapse-Element auf der
Baselines-Seite (DE/EN).

### Feature: Audit-Certification-Bundle-Export

`CertificationBundleExporter`-Service + Controller exportiert ein
Audit-fertiges Beweis-Bundle (Evidence-Collection inkl. Dokumente,
Audit-Logs, Compliance-Status) für externe Prüfer.

### Feature: Small-Business-Accessibility (<50 FTE)

7 vereinfachte Maßnahmen-Sets für KMUs unter 50 Mitarbeitenden — runtime-
gehärtet gegen `null` Tenant-Settings in `resolveCompanySize`.

### Feature: Onboarding-Journey + Community-Profile

- Unified Guidance-System: ISMS-Journey-Widget + reichere Empty-States
  in allen ISMS-Modulen.
- GitHub-Community-Profile auf 100 % (SECURITY.md, CODE_OF_CONDUCT.md,
  Issue-Forms, PR-Template) — `SECURITY.md` unter `.github/` für
  Auto-Detection.

### Fix: SSO-Hardening (Post-Audit)

- `users.created_at` jetzt in JIT-User-Provision gesetzt (NOT-NULL-
  Constraint hätte `INSERT` zerlegt).
- Anonyme Login-Visitor sehen tenant-scoped IdPs nur über matching
  Email-Domain (kein IdP-Leak); Slug-Resolution fällt auf
  `findOneBySlugAnywhere` zurück.
- BLAKE2b-Key-Derivation korrigiert (CTX-Tag war kürzer als
  16 Bytes — `sodium_crypto_generichash` lehnte ab).

### Fix: Composer-Pin web-token/jwt-library

`web-token/jwt-library` war im `composer.lock` aber nicht in
`composer.json require` → PHPStan/Code-Quality-Job meldete jede
`Jose\Component\*`-Klasse als "not found". Jetzt explizit auf `^4.0`
gepinnt.

### Fix: MRIS-Baseline-Service-Test

`MrisBaselineService::__construct` bekam in 3.2.x einen `RequestStack`-
Parameter vor `$projectDir` — der Test rief das alte Signatur-Layout
auf. Test injiziert jetzt `new RequestStack()` an Position 5.

## [3.2.8] — 2026-04-29

### Fix: PHP 8.4 base image (revert) für Docker-Build

v3.2.7 ist getaggt, hat aber **kein Docker-Image** — Docker-Build brach an
`docker-php-ext-install` (gd/pdo/mysqli-Extension-Chain) auf dem PHP-8.5-
fpm-Base. Fehler: `cp: cannot stat 'modules/*': No such file or directory`.
Upstream-`docker-php-ext` Helper-Skripte unterstützen den 8.5er Module-Build
noch nicht zuverlässig.

Zurück auf `php:8.4-fpm-trixie@sha256:eec2a132…` für jetzt. Tests, Code-Quality,
Security-Checks waren auf v3.2.7 alle grün — der Code selbst läuft mit beiden
PHP-Versionen. v3.2.8 ist v3.2.7 mit funktionierendem Docker-Build.

PHP-8.5-Bump wird in eigenem Sprint nach `php:8.5.5+` Image-Release nachgeholt.

## [3.2.7] — 2026-04-29

### Refactor: Property/Getter-Alignment in 17 Entities

17 latente Landminen behoben — Properties die nicht zum Getter-Namen passten
(z.B. `private $user` mit `getUploadedBy()`). Twig-Magic-Resolution braucht
`entity.foo` → `getFoo()` Korrespondenz; Mismatches werfen "Neither the
property X nor methods getX/isX/hasX exist". Eines davon (`complianceFramework`)
hatte uns zur Laufzeit getroffen, der Audit hat 16 weitere gefunden.

JoinColumn-`name=` ist überall gepinnt → keine DB-Migration nötig.

In drei Tiers gemerged:

* **Tier A** (8 isolierte Entities): RiskAppetite/Document/ManagementReview/
  CorporateGovernance/MappingGapItem/RiskTreatmentPlan/AuditChecklist/
  ComplianceRequirementFulfillment
* **Tier B** (DQL-touched): WorkflowInstance.workflowStep→currentStep,
  ComplianceRequirement.complianceFramework→framework + parent self-ref
* **Tier C** (high-fanout): InternalAudit, Incident.threatIntelligence,
  ThreatIntelligence.user, Risk.user

Folge-Fixes nach erstem CI-Lauf:

* 3 inverse `mappedBy`-Refs auf Owning-Side angepasst
  (ComplianceFramework→requirements, ComplianceMapping→gapItems,
  ThreatIntelligence→resultingIncidents)
* `findBy()`-Criteria-Arrays in src/ + tests/ massweise umbenannt
  (`'complianceRequirement' => …` etc.)
* BsiProfileXmlImporter-Test Bracket-Access aktualisiert

### Fix: Post-v3.2.6 Stabilisierung (PHP 8.5 + Templates + Setup)

13 Folge-Fixes nach dem v3.2.6-Tag, allesamt punktuelle Laufzeit- und
Template-Reparaturen aus den Spezialisten-Reviews. Kein Schema-Bruch,
keine API-Änderungen.

#### PHP 8.5 strict type-coercion (Fortsetzung von v3.2.6)

* **Repository-Scalars an der Quelle gecastet** — Aggregations-Queries
  (`COUNT`, `SUM`, `AVG`) lieferten je nach Treiber `int|string`. Statt an
  jeder Aufrufstelle zu casten, normalisieren die Repositories jetzt direkt
  auf `int`/`float` zurück. Schließt eine Klasse von TypeError-Restbügeln,
  die nur unter PHP 8.5 sichtbar wurden.
* **`SupplierType::finishView` + Repository-Scalars** — analoge
  Coercion-Bügel im Form-Layer (Form-Type liest gecastete Werte direkt).
* **Vier Template-Runtime-Issues aus dev.log** — implizite Float→Int-Casts
  in vier Twig-Aufrufpfaden (Render-Layer, nicht Service-Layer).

#### Templates / UI

* **`fix(charts+batch-analysis)`** — Chart.js 4 Colors-Plugin korrekt
  registriert (war v3-API-Stub). `.d-none`-Visibility für Empty-State auf
  Batch-Analysis-Karten + ein Offset-Off-by-One in der Pagination.
* **`fix(mapping-quality)`** — Twig referenzierte `complianceFramework`,
  Entity-Property heißt seit v3.2.0 nur noch `framework`. Drei Templates
  angepasst.
* **`fix(role-management)`** — `~`-Concat-Operator in Twig ist
  string-only; Array-Merge braucht den `|merge`-Filter.
* **`fix(data-breach)`** — `followUpActions` ist ein strukturiertes
  Array (Action + Owner + Due-Date), Template hatte es als String
  ausgegeben (`Array to string conversion`-Notice).

#### Setup / MRIS

* **`fix(mris+quick-fix)`** — `bc_exercise` heißt im Schema so, nicht
  `bc_exercises`. Der Quick-Fix-Subscriber prüfte den Plural-Tabellennamen
  und leitete deshalb auch auf intakten Schemata zur Quick-Fix-Seite um.
* **`fix(mris)`** — analoger Tabellennamen-Bug für `mfa_tokens` (heißt
  `mfa_token`) und `users` (heißt `user`) in Raw-SQL-Konstanten.

#### Industry Baselines

* **`feat(industry-baseline)`** — One-Click-Seed-Button auf der
  Baseline-Übersicht, wenn der Katalog leer ist. Erspart frischen
  Installationen den Konsolen-Befehl.

#### Repo-Hygiene

* **`chore(git)`** — `node_modules/` in `.gitignore` (war seit
  stylelint-Einführung untracked aber nicht ignored).
* **`chore(deps)`** — `package-lock.json`-Name auf Lower-Case normalisiert
  (npm flippte ihn bei jedem Install hin und her).

### Schema-Reconciliation (post-Migrations-4.0)

`doctrine:schema:validate` zeigte nach dem Bundle-4-Bump drei harmlose
Pre-Existing-Inkonsistenzen — alle in einer reversiblen Migration
behoben:

* `RiskAppetite#reviewBufferMultiplier`: `DECIMAL(4,2)` → `FLOAT`. PHP-Property war `float`, DBAL liefert für `DECIMAL` aber `string` — implizite Casts in Arithmetik. Werte 1.0–3.0 mit 2 Nachkommastellen sind FP32-präzise.
* `incident.severity`: nullable enforce (matched ORM-Mapping).
* DPIA: Index-Rename auf Doctrine-Convention (rein kosmetisch).

`migrations/Version20260429110455.php`. `schema:validate` jetzt grün auf
beiden Sektionen.

### Tests

* **DeploymentWizardControllerTest setUp/tearDown** — Lock-Backup +
  Restore. Vorher entfernte das setUp den `setup_complete.lock` global
  im PHPUnit-Prozess; Folge-Tests in anderen Klassen wurden danach von
  `SetupRequiredSubscriber` zur Setup-Seite redirected (CI: 3 → 193
  Failures-Spike). Backup im setUp, Wiederherstellung im tearDown.
* **CSRF-Token via Session-Save** — `bulk-delete`-Tests brauchen aktive
  Session bevor `getToken()` aufgerufen wird (`security.csrf.token_manager`
  schreibt in die Session, ohne Save-Call greift der Submit-Read den Token
  nicht).

### Skills

* `pentester-specialist`-Skill für OWASP/PTES/NIST-800-115/OSSTMM-aligned
  Security-Reviews. Treiber für PT-001 (MFA-Bypass) und PT-003
  (TOTP-Klartext) während der v3.2.5-Welle.

## [3.2.6] — 2026-04-29

### Fix: PHP 8.5 strict type-coercion auf Dashboard-KPIs

PHP 8.5 enforces stricter type-coercion: `round()` returnt `float`, kann
nicht mehr implizit in einen `int`-typed Parameter gecastet werden. Mit dem
Base-Image-Bump auf `php:8.5-fpm-trixie` in v3.2.5 (Tag-Build wurde wegen
genau dieses Bugs vor Docker-Push gecancelt — **v3.2.5 hat kein
Docker-Image**) brach `DashboardStatisticsService::getStatus()` zur Laufzeit
beim Aufruf mit `round()`-Argumenten:

```
TypeError: getStatus(): Argument #1 ($value) must be of type int, float given
```

Fünf Call-Sites in `DashboardStatisticsService` mit explizitem `(int)` Cast
versehen:

* `$treatmentRate` (Zeile 1134) — Risk-Treatment-Rate-KPI
* `$classificationRate` (Zeile 1240) — Asset-Classification-KPI
* `$biaCoverage` (Zeile 1377) — BIA-Coverage-KPI
* `$completionRate` (Zeile 1444) — Training-Completion-KPI
* `$assessmentRate` (Zeile 1534) — Supplier-Assessment-KPI
* `$reportingCompliance` (Zeile 1718) — Incident-4h-Reporting-KPI

Audit über die gesamte `src/`-Codebase (96 Dateien mit `round(`, 344
Vorkommen): **keine weiteren akuten PHP-8.5-strict-coercion-Bugs**. Die
Codebase nutzt durchgängig diszipliniertes `(int) round(...)`-Pattern an
allen kritischen int-Boundaries (Entity-Setter, Method-Returns mit `: int`,
KPI-Threshold-Vergleiche).

### Hinweis zu v3.2.5

Tag `v3.2.5` existiert auf GitHub aber **wurde nicht released** — der
Docker-Build wurde gecancelt, sobald der Bug auffiel. Kein gepushtes
Docker-Image, keine GitHub-Release-Seite. v3.2.6 enthält alle v3.2.5
Inhalte plus diesen Fix. Der Tag bleibt als historischer Marker bestehen.

Alle v3.2.5-Inhalte (TOTP-Encryption, PHP-8.5, Doctrine-Migrations-4.0,
PHPUnit-13.1, Turbo-8, Chart.js-4, stylelint-17, GitHub-Actions-Bumps,
Aurora-T3-Sprint, Dependabot/Pre-commit/Codecov-Config, Hadolint-Smell-Fixes,
Repo-Cleanup) gelten in v3.2.6 weiterhin.

## [3.2.5] — 2026-04-29

### Security

* **TOTP-Secrets at-rest verschlüsselt** (CVSS 6.5, T1-7) — MFA-Tokens
  speichern Geheimnisse jetzt verschlüsselt in der DB. Alte Plaintext-Secrets
  werden beim ersten Zugriff transparent migriert (Auto-Heal-Pattern, kein
  User-Action nötig). Verhindert Disclosure bei DB-Backup-Diebstahl.
  **Deployment-Hinweis:** Optional `MFA_ENCRYPTION_KEY` in `.env` setzen für
  unabhängige Key-Rotation (Fallback: APP_SECRET). Bulk-Migration aller Secrets:
  `php bin/console app:encrypt-mfa-secrets --dry-run` dann
  `php bin/console app:encrypt-mfa-secrets`.
* **CSRF-Token-Persistierung in Tests** — `generateCsrfToken()` ruft jetzt
  `$session->save()` auf, weshalb die 4 zuvor `SessionNotFoundException`-
  betroffenen `AssetControllerTest::testBulkDelete*` jetzt grün laufen.
  Test-seitig — keine Produktions-Auswirkung.

### Dependencies (Major-Bumps)

Major-Bumps in der Liste — alle CI-validiert (Tests + Code-Quality + Docker):

* **PHP 8.4 → 8.5** Base-Image (`php:8.5-fpm-trixie@sha256:7d1586e8…`).
  Extension-Build-Issues aus früheren 8.5-Versionen sind in 8.5.4+ resolved.
* **Doctrine Migrations Bundle** 3.7 → 4.0
* **PHPUnit** 12.5 → 13.1
* **Hotwired Turbo** 7.3.0 → 8.0.23 (CVE-Fix)
* **Chart.js** 3.9.1 → 4.5.1
* **stylelint** 16.26.1 → 17.9.1
* **stylelint-config-standard** 36.0.1 → 40.0.0
* **GitHub Actions**: docker/setup-qemu 3→4, docker/build-push 5→7,
  actions/cache 4→5, actions/setup-node 4→6, actions/upload-artifact 4→7

### UX (Aurora-Sprint T3)

* `feat(ux)` T3-10: locale-aware date formatting via Twig extension
* `refactor(ux)` T3-2 + T3-6: KPI cards migrated + empty states consolidated
* `refactor(ux)` T3-3: 5 modules standardized on `_search_filter_form`
* `fix(ux)` T3-8: client-side search added to 4 index pages

### CI/CD-Workflow

* **Dependabot** aktiviert (`/.github/dependabot.yml`) — wöchentliche
  Auto-PRs für composer, npm, github-actions, docker. Gruppiert
  symfony/* und doctrine/* zu Sammel-PRs.
* **Pre-commit-Hooks** (`/.pre-commit-config.yaml`) — trailing-whitespace,
  large-file-guard, JSON/YAML-Lint, PHP -l, Hadolint, Symfony Twig-Lint,
  Symfony YAML-Lint, GitLeaks Secret-Scan. Install via
  `pip install pre-commit && pre-commit install`.
* **Codecov-Config** (`/.codecov.yml`) — Coverage-Trend-Range 60-90%, Tests
  + Vendor + Migrations ignored, project + patch status informational.
  Codecov-Action war bereits gewired; jetzt mit Repo-Config-Datei auswertbar.
* **Hadolint Dockerfile-Smells** behoben — DL3059 (consecutive RUN) +
  2× SC2015 (`A && B || C`-Pattern). Lint-clean lokal.
* **Repo-Labels** angelegt (`dependencies`, `composer`, `javascript`,
  `docker`, `github-actions`) — Dependabot kann Labels auf PRs nun setzen
  ohne Fehler-Comment.

### Repository-Cleanup

3 obsolete Branches gelöscht:

* `claude/symfony-best-practices-review-…` (PR #264 längst gemerged)
* `feat/mris-integration` (Integration-Plan-Doc; MRIS-Schema längst in main)
* `feat/phase10-workflows` (10 regulatory Workflows längst in main via
  andere Routen)

## [3.2.4] — 2026-04-29

### Docker-Hardening + Source-Updates

#### Supply-Chain-Transparenz (ISO 27001 A.5.21 / BSI C5 DEV-08)

* **SBOM (SPDX) als OCI-Attestation** — `docker/build-push-action` ruft jetzt mit `sbom: true` ein. Jeder gepushte Image-Tag bringt eine signierte Software-Bill-of-Materials in den Manifest-Index. Audit-Nachweis aller eingebauten Pakete (PHP-Extensions, Debian-Packages, Composer-Deps, NPM-Importmap) ohne `docker run --rm IMAGE list-packages`.
* **SLSA-Build-Provenance** — `provenance: mode=max` erzeugt eine signierte Attestation, die belegt: *welcher* GitHub-Actions-Workflow hat das Image aus *welchem* Commit gebaut. Schließt typische Supply-Chain-Angriffsvektoren (CI-Übernahme, Tag-Hijacking).

#### Build-Performance

* **BuildKit Cache-Mounts** im Dockerfile für `apt-get` (`/var/cache/apt` + `/var/lib/apt`) und `composer install` (`/root/.composer/cache`). Warmer Build: 40-60% schneller. Cache landet nicht im finalen Image-Layer.
* **`# syntax=docker/dockerfile:1.7`** als Frontline-Direktive aktiviert die für Cache-Mounts nötige Frontend-Version.

#### Reproducible Builds

* **Pinned Base-Image-Digest**: `php:8.4-fpm-trixie@sha256:eec2a132…` statt nur Tag. Schützt gegen Tag-Rollover (z.B. wenn Upstream das Tag während eines Builds neu pusht). Kommentar im Dockerfile dokumentiert wie der Digest aktualisiert wird.

#### Code-Quality-Gates

* **Hadolint** als CI-Job — Dockerfile-Linter, der typische Smells fängt (`apt install` ohne `--no-install-recommends`, fehlende Pinning-Versionen, root-as-default-User). Aktuelle Konfiguration: `failure-threshold: error`, `continue-on-error: true` — Warnings werden gemeldet aber blocken Build noch nicht (Soft-Launch). Drei Regeln auf Allowlist (DL3008/DL3015/DL3018) — Stable-Distro-Pakete und Pip-Setup-Pattern den unsere Setup explizit nutzt.

#### Source-Updates

* **PHPStan** 2.1.51 → 2.1.53 (Patch).
* **Bootstrap** 5.3.3 → 5.3.8 (Minor — Bug-Fixes, kein API-Bruch).
* **SortableJS** 1.15.3 → 1.15.7 (Patch).
* **Keine Security-Advisories** im aktuellen Composer-Tree.

#### Bewusst nicht aktualisiert (eigener Sprint nötig)

* **`@hotwired/turbo` 7.3.0 → 8.0.23** — Major-Bump mit potentiellen Stimulus/Turbo-Convention-Änderungen, eigene QA-Phase nötig.
* **`chart.js` 3.9.1 → 4.5.1** — Major-Bump mit substantiellen Konfigurations-API-Änderungen.

Beide für v3.3.0 vorgesehen.

#### Bekannte Test-Failures aus v3.2.3 weiter offen

Die 4 `AssetControllerTest::testBulkDelete*` Errors (`SessionNotFoundException`) sind weiter offen — Test-seitig, nicht produktions-seitig. Wird parallel adressiert.

## [3.2.3] — 2026-04-28

### Quick-Fix-Fallback für Schema-Mismatch nach Composer-Upgrade

Nach `composer install` / `git pull` ohne Container-Neustart konnte ein
fehlendes Schema-Update (`Doctrine\DBAL\Exception\TableNotFoundException`,
`MappingException`, `Unknown column …`) nur einen 500er produzieren — auf
shared-hosting Setups ohne SSH-Zugriff praktisch nicht behebbar ohne
Anleitungen, die User händisch befolgen.

Neuer Fallback:

* **SchemaExceptionSubscriber** (`kernel.exception`, priority 64) fängt
  TableNotFound / Mapping-Exceptions ab und leitet auf `/quick-fix` statt
  500 — locale-prefix-frei, weil der Schema-Fehler den Locale-Resolver
  selbst brechen kann.
* **Quick-Fix-UI** (`/quick-fix`) zeigt minimalen Diagnostic-Output (nur
  Anzahl pending Migrationen, keine Tabellen-/Spalten-Namen) + Button
  "Migrationen jetzt anwenden" → POST `/quick-fix/apply` ruft den
  bestehenden `SchemaMaintenanceService::executePendingMigrations()`. UI
  ist standalone (keine `base.html.twig`-Abhängigkeiten), funktioniert
  auch wenn Sidebar/Locale-Resolver kaputt sind.
* **QuickFixGuard** mit 4 Settings unter `quick_fix.*` Kategorie:
  - `fallback_ui_enabled` (default true) — Master-Schalter, off → Standard-500
  - `require_installer_token` — Token-Match gegen `var/setup-token`
  - `allow_in_dev_only` — nur erreichbar wenn `APP_ENV=dev`
  - `ip_allowlist` — Komma-Liste erlaubter Client-IPs
  Defaults sind Docker-Self-Hosting-tauglich (alle Toggles aus).
  Composer-Installs schreiben automatisch via post-install-cmd ein
  64-Hex-Token nach `var/setup-token` für späteres Aktivieren.
* **Admin-Settings-UI** unter `/admin/quick-fix-settings` (ROLE_ADMIN) +
  Eintrag im Admin-Dashboard-Quick-Actions.
* **Locked-Page** (Token-Mode + Guard-Block) mit Inline-Token-Eingabe-Form
  und Cookie-Persist (sodass POST-Apply nicht erneut Token braucht).

### Aurora-Error-Pages für 429 + 503

Bisher fielen `429 Too Many Requests` und `503 Service Unavailable` auf
das generische `error.html.twig` Template. Jetzt eigene Aurora-Templates
mit `Retry-After`-Anzeige (wenn vom Listener mitgegeben), Alva-Mood
`warning`, Reload + Home-Buttons. Pattern matcht 403/404/500.

### Test-Coverage

* `QuickFixGuardTest` — 10 Tests (default-open, 3 Toggles, fail-closed bei
  fehlender Settings-Tabelle, Token-Cookie + Query-Param)
* `SchemaExceptionSubscriberTest` — 6 Tests (TableNotFound, MappingException,
  Recursion-Guard, Disabled-Mode, Previous-Chain-Unwrap)

### Versionsanzeige

`composer.json` Version-Feld auf `3.2.3` gebumpt — wurde bei v3.2.2 vergessen,
sodass Footer-Branding (`AppVersionExtension`) und Email-Templates noch
v3.2.1 anzeigten.

## [3.2.2] — 2026-04-28

### Patch-Release: Test-Suite grün nach Enum-Migration

v3.2.1 wurde von kaputtem CI-Lauf getaggt — 3 Errors + 4 Failures aus laufender
String→BackedEnum-Migration für `IncidentStatus` / `RiskStatus`. v3.2.2 bringt
genau diese Fixes nach.

#### Enum-Vergleiche in Service-Layer

* `DashboardStatisticsService::computeDashboardStatistics()` — Open-Incident-
  Filter verglich `getStatus() === 'open'`. `Incident::getStatus()` liefert
  jetzt `IncidentStatus`-Enum, nie String → Filter immer false. Ersetzt durch
  `in_array($i->getStatus(), [Reported, InInvestigation, InResolution], true)`.
  Zweite Stelle in der Backlog-Score-Komponente identisch gefixt.
* `ReviewReminderService::getOverdueRiskReviews()` /
  `getUpcomingReviews()` — Closed/Accepted-Ausschluss verglich
  `in_array($risk->getStatus(), ['closed','accepted'], true)`. Risk liefert
  `RiskStatus`-Enum → Vergleich nie wahr → akzeptierte/geschlossene Risiken
  sind als overdue durchgerutscht. Auf `[RiskStatus::Closed,
  RiskStatus::Accepted]` umgestellt.

#### Route-Namen korrigiert

Drei Stellen referenzierten den nicht-existenten Routen-Namen
`app_business_continuity_plan_edit/_show`. BC-Plan-Routen heißen
`app_bc_plan_*`. Betroffen:

* `templates/admin/data_repair/index.html.twig` (Edit-Link in BC-untested-Tabelle)
* `templates/home/_overdue_reviews_widget.html.twig` (Show-Link im Widget)
* `src/Service/ReviewReminderService::generateUpcomingReviewLinks()` (E-Mail-Reminder)

DataRepairController-Test-Render brach an Stelle 1, die anderen warfen erst zur
Laufzeit beim Rendering der jeweiligen View.

#### Integration-Test-Helpers an Enum-Schema angepasst

* `IncidentRepositoryIntegrationTest::createIncidentRaw()` schrieb status via
  raw DBAL-`UPDATE`-Statement mit Legacy-Strings (`'open'`, `'investigating'`,
  `'in_progress'`). Nach Enum-Migration warf `IncidentStatus::from('open')`
  beim Rehydrate `ValueError`. Durch sauberes Mapping legacy → enum-case
  ersetzt — keine raw-DBAL-Update mehr nötig.
* `RiskRepositoryIntegrationTest`: fehlender `use DateTime;` Import.

#### Test-Daten-Bereinigung

* `DashboardStatisticsServiceTest` + `SiemExportServiceTest`:
  `IncidentStatus::tryFrom('open')` (lieferte `null`) → `IncidentStatus::Reported`.

**Lokale Suite:** 4155 Tests, 12185 Assertions, 0 Errors, 0 Failures.

## [3.2.1] — 2026-04-27

### Patch-Release: Sample-Data-Import komplett überarbeitet + kritischer TenantFilter-Bug behoben

v3.2.0 hatte zwei strukturelle Issues, die das Sample-Data-Modul für reale Nutzer
unbenutzbar machten und potentiell andere tenant-gefilterte Bereiche beeinflussten.
v3.2.1 bringt 47 Folge-Commits aus einem ausgedehnten Diagnose- und Fix-Sprint zusammen.

**v3.2.0 ist als kaputt markiert und wurde aus den Releases entfernt.**

#### TenantFilter — kritischer SQL-Filter-Bug (5cd4ab5f)

`Doctrine\ORM\Query\Filter\SQLFilter::getParameter()` liefert den Wert bereits
quotiert vom Connection-`quote()`. Der Sentinel-String `'null'` (vom
`TenantFilterSubscriber` für super-admins ohne Tenant gesetzt) kam darum als
SQL-Fragment `'null'` zurück, nicht als nackter String. Der bisherige Vergleich
`=== 'null'` schlug fehl, der Filter generierte:

```sql
WHERE tenant_id = 'null'
```

Das matcht nie eine Integer-Spalte. Konsequenz: jeder authentifizierte User
ohne explizites Tenant (oder im Default-Tenant-Fallback) bekam Tenant-gefilterte
Tabellen leer zurück — nicht nur Sample-Data, sondern potentiell auch
Risiken/Audits/Schulungen-Listen je nach User-Setup. Im Sample-Data-Index hieß
das: alles als „nicht importiert" markiert, Aktionen-Spalte leer.

Fix: outer Quotes vor der Sentinel-Prüfung trimmen, leeren String als zweite
Bypass-Form akzeptieren. CLI war nie betroffen (kein Subscriber → kein
Parameter → InvalidArgumentException-Branch greift sauber).

#### Sample-Data-Import — vollständig überarbeitet

Der ursprüngliche Import-Service hatte mehrere stille Failure-Modes, die in
unterschiedlichen Kombinationen sichtbar wurden. Alle gefixt:

* **Date-Type-Detection** (55c7cdef): Setter-Reflection allein reicht nicht —
  Doctrine-Column-Type wird jetzt aus `ClassMetadata::fieldMappings` gelesen.
  `DATE_MUTABLE` → `DateTime`, `*_immutable` → `DateTimeImmutable`. Brach
  Sample 8 (Schulungen).
* **Enum-String-Konversion** (71461740): YAML liefert Strings (`'high'`,
  `'critical'`), Setter erwartet `BackedEnum`. Reflection auf Setter, dann
  `$enumClass::tryFrom($value)`. Brach Sample 5 (Incidents).
* **Idempotenz: Merge statt Skip** (4782cca2): Bestehende Entities mit gleichem
  Natural-Key werden jetzt mit YAML-Daten gemerged statt verworfen. Verhindert
  „orphan-with-NULL-asset"-Szenarien aus früher fehlgeschlagenen Imports.
* **Singular-Aliase + Plural→Singular-Map** (b88a56d9): YAML-Top-Level-Keys
  sind plural (`data_breaches`, `people`, `processing_activities`),
  `referenceNaturalKeys()` keys singular. `rtrim('s')` brach bei irregulären
  Plurals → Idempotenz-Check fehlte → Duplicate-Key-Constraint. Brach Sample 11
  (Datenschutzverletzungen) und 20 (Personen).
* **EM-Reset zwischen Samples** (71461740): `ManagerRegistry::resetManager()`
  nach Constraint-Violation, Tenant + User auf frischem EM neu binden.
  Verhindert Cascade-Failure nach einem fehlgeschlagenen Sample.
* **Idempotente Tracking-Rows** (429fe47f): Re-Import des selben Samples
  erzeugt nicht mehr neue Tracking-Records pro Klick (vorher: 130 Rows für
  10 Assets). Lookup vor `persist`.
* **Backfill-Pass für Refs** (cd487aa5): Nach jedem `importSampleData()`
  iteriert alle bisherigen Tracking-Rows, lädt das zugehörige YAML, setzt
  vorher unauflösbare `ref:`-Felder jetzt auf, falls Ziel-Entity inzwischen
  importiert wurde. User kann Samples in beliebiger Reihenfolge importieren
  und Beziehungen werden nachträglich geknüpft.
* **Unresolved-Refs im Flash** (169471c3): Wenn `ref:asset:X` nicht aufgelöst
  werden kann, taucht das jetzt explizit im Result-Message auf statt still
  in der Log-Datei.

#### Sample-Data-Purge — robuste Cleanup-Pipeline

Der Purge-Pfad hatte ähnliche Cascade-Issues + FK-Order-Probleme:

* **Reverse-Index-Reihenfolge** (c7e75a68): BCPlans (Sample 15) vor
  BusinessProcess (Sample 2) löschen, sonst FK-Violation.
* **Per-Entity-Flush + EM-Reset auf Remove** (283e0fad): Single FK-Failure
  reißt nicht mehr alle übrigen Entities mit.
* **Cascade-Delete für Orphan-FK-Blocker** (da465f3d): FK-Violation-Message
  wird geparst, das blockierende Child-Row direkt per raw-SQL gelöscht,
  dann Retry. Mehrstufige FK-Ketten werden in bis zu 3 Iterationen abgebaut.
* **Orphan-Tracking-Cleanup** (ce40005c): Tracking-Rows die auf nicht mehr
  existierende Entities zeigen, werden nach jeder Purge-Pass entfernt.
  Verhindert die Sample 2 = 15-statt-10 Inflation aus mehrfachen Purge-Läufen.
* **Retry-Pass** (94fe6f27): Failed `Class#Id` aus den Per-Sample-Errors
  parsen, am Ende erneut versuchen wenn alle Dependencies durchgelaufen sind.

Neuer Console-Command `app:sample-data:purge` exposiert die komplette Pipeline
mit Dry-Run-Option. Hidden Diagnose-Command `app:debug:sample-data-status`
zeigt die UI-Sicht aus Repository-Perspektive zur Verifikation.

#### TISAX/DORA — Status + UI-Removal

Command-basierte Sample-Loader (`app:load-tisax-requirements`,
`app:load-dora-requirements`) schreiben keine Tracking-Rows. Die UI zeigte
sie darum permanent als „nicht importiert", auch wenn 114 TISAX- + 131
DORA-Anforderungen längst geladen waren.

Fix:

* Status (f24fd051, c1c273b3): Command-Sample → Framework-Code-Lookup
  (`'TISAX'`, `'DORA'`) → ComplianceRequirement-Count → Badge zeigt
  „114 importiert".
* Removal (d19666a1): UI-Entfernen-Button für Command-Samples, Action-Route
  cascade-deletet das Framework (`cascade: ['remove']` auf der OneToMany).

#### UI-Hilfsmittel

* **Select-All-Checkbox** (92686b86): Master-Checkbox in der ersten Spalte
  toggelt alle aktiven Sample-Checkboxen. Eigener Stimulus-Controller
  `select_all_controller.js` mit defensiv resolvierter `this.element`-Referenz
  (umgeht eine Stimulus-Build-Quirk in dieser App).
* **Entfernen-Button bei jedem Sample mit Tracking-Rows** (17592146): Vorher
  nur sichtbar wenn `imported=true` — das versteckte den Button bei
  Status-Drift-Szenarien. Jetzt: Button erscheint sobald `count > 0`.
* **Turbo-Cache disabled** (b30e2d6e): Status-Badges hängen vom Live-DB-Stand
  ab, nicht von Turbo-Snapshot vor dem Import.
* **Defensive Int-vs-String-Lookup** (b71dcb1e): Doppelte Lookup-Tabellen
  für `$importedCounts` (int- und string-keyed) damit DB-Driver-Quirks
  keine UI-„nicht importiert"-Fehlanzeige produzieren.

#### Admin Health-Checks

`b229bc70a` und `fb5cb724` bringen 8 weitere Health-Checks ins Data-Repair-
Modul (Duplicate-Merge, Risk-Health, GDPR/ISO Compliance-Checks). `e2549576`
ergänzt Tier 2+3 Checks und räumt offene TODOs auf.

#### Bug-Fixes (kleinere)

* `5cd4ab5f` TenantFilter: siehe oben (kritisch)
* `c6848b44`, `ee46bd05`, `3dfc40d3`, `f8dcddd6`: temporäre Diagnose-
  error_logs zur Bug-Hunt — wieder entfernt.
* `303347c2`, `6c05ab0e`: zwei i18n-Tippfehler im `admin.de.yaml`,
  YAML-Parse-Fehler verursacht.
* `b0a7a44f`: Patch-Show + Help-Sidebars + RiskStatus-Enum-Cases
  re-apply nach Linter-Revert.
* `d3599cad`: 27 Templates für PHP-Enum-Integration aktualisiert.

#### Verifikation

End-to-End Test gegen frische dev-DB (Mordor Inc.):

| Sample | YAML | DB |
|---|---|---|
| Beispiel-Assets | 10 | 10 ✓ |
| Beispiel-Risiken | 10 | 10 ✓ |
| Beispiel-Geschäftsprozesse | 10 | 10 ✓ |
| TISAX Requirements | — | 114 ✓ |
| DORA Requirements | — | 131 ✓ |
| Beispiel-Incidents | 7 | 7 ✓ |
| Beispiel-Dokumente | 9 | 9 ✓ |
| Beispiel-Schulungen | 8 | 8 ✓ |
| Beispiel-Management-Reviews | 4 | 4 ✓ |
| Beispiel-Verarbeitungstätigkeiten | 8 | 8 ✓ |
| Beispiel-Datenschutzverletzungen | 5 | 5 ✓ |
| Beispiel-Einwilligungen | 6 | 6 ✓ |
| Beispiel-DPIAs | 4 | 4 ✓ |
| Beispiel-Betroffenenanfragen | 6 | 6 ✓ |
| Beispiel-BC-Pläne | 5 | 5 ✓ |
| Beispiel-BC-Übungen | 6 | 6 ✓ |
| Beispiel-Krisenstäbe | 3 | 3 ✓ |
| Beispiel-Lieferanten | 10 | 10 ✓ |
| Beispiel-Standorte | 5 | 5 ✓ |
| Beispiel-Personen | 8 | 8 ✓ |
| Beispiel-Interessierte Parteien | 8 | 8 ✓ |
| Beispiel-ISMS-Ziele | 6 | 6 ✓ |
| Beispiel-Risikoappetit | 4 | 4 ✓ |

Risk → Asset Verknüpfungen: 9/10 (1 Risk ist semantisch person-basiert).

## [3.2.0] — 2026-04-26

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

### Aurora v4.1 Final-Wellen — Sprints D, E1-E6, F, G, H, J1-J3, K1-K3, M1-M2, N1-N2, P1-P2, Q + Icon-System

**v4.1-Compliance app-weit erreicht — null Bootstrap-Color-Utilities, null bi-* Icons, null hardgecodete Hex-Farben außerhalb der Token-SoT.**

Mehrwöchige Mass-Migration aller noch verbliebenen Bootstrap-/Bootstrap-Icons-/Inline-Style-Reste auf Aurora-Native-Komponenten. Über 600 Templates angefasst, 18 Sprints (D bis Q) abgeschlossen. Aurora ist damit nicht mehr „Bridge auf Bootstrap", sondern eigenständige UI-DNA.

**Icon-System (Sprint A + Sprint Q + Folge-Wellen):**

- **89 neue Icons** aus design_system v4.1 in App-Tree gezogen (Sprint Q): `nav/`, `ui/`, `util/` Domains
- **20 weitere Icons** in Folge-Welle: `clock`, `calendar`, `lightbulb`, `flag`, `shield-check`, `arrow-*`, etc. — **186 SVGs total** im Aurora-Icon-Set
- **Mass-Migration `bi-*` → `fa-icon--{nav,ui,util}-*`** über ~380 Sites (Sprint Q + Folge)
- **39 broken Icon-Refs** repariert, mass-mapped auf existierende Klassen
- **21 ungenutzte Compliance-Icons adoptiert** (Δ `bi-*` 431→394) — kein toter Code mehr
- Icon-Size-Utilities (.fa-icon--xs/sm/md/lg/xl) ersetzen alle inline `font-size`-Styles auf `bi-*` Glyphen

**Komponenten-Robustheit (Sprint E + N + P):**

- **`_fa_alert` embed-block-scope-Fix** — 29 Templates: `render(body: '{{ … }}')` funktionierte in Twig 3 nicht zuverlässig, jetzt durchgängig auf `embed` mit `block body` umgestellt
- **`_fa_empty_state` + `_fa_hero` Twig-3 macro-scope-Fix** für Alva-Render — Macro-internes `{% set %}` mit `props is defined`-Guard
- **`fa-cyber-btn` safety-net via `:where()`** — variantless Buttons (ohne `--md`/`--ghost`-Modifier) bekommen jetzt brauchbares Default-Padding/Size, statt unsichtbar zu rendern
- **TomSelect-Override mit `!important`** — Aurora-Tokens schlagen jetzt zuverlässig die Lib-CSS der TomSelect-Library
- **2 unfertige Twig-Conditionals beim P1-Migrate** repariert

**Mass-Migrationen (Sprint K2 + J + M + N + P):**

- **`alert alert-*` → `_fa_alert` / `fa-alert`** über **100 Templates** (Sprint K2)
- **`btn-*` / `btn-outline-*` / `btn-link` → `fa-cyber-btn`** in mehreren Wellen (Sprint J2, K1, M1, M2, P1 final, plus `btn-link` → `fa-cyber-btn--ghost`)
- **`bi-*` → `fa-icon--*`** in Domain-Audits (Sprint E1-E5, P2 final): home/dashboards/admin, asset/incident/document, ISMS-Domain
- **Badge-Mass-Migration** → `_badge` / `fa-status-pill` (Sprint J1)
- **`.kpi-card` / `variant: 'kpi'`** auf CISO-Dashboard durch `_fa_feature_card` mit Icon-Chip ersetzt
- **Inline-Style-Cleanup + Hex-Cleanup Round 2** (Sprint J3, E6) — Hex-Farben außerhalb Token-SoT eliminiert
- **`fa-aurora-surface` flächendeckend** als Opt-in-Page-Atmosphäre (Sprint C)
- **Card-Konsolidierung Round 2 + 3** (Sprint G, K3) — duplizierte Card-Header-Regeln entfernt, Aurora-Spec gewinnt durchgehend per Source-Order

**Governance / CI:**

- **Stylelint-Hex-Verbot in 14 Color-Properties** app-weit (Sprint H, Phase 11) + Audit-Tooling
- **Allow-List**: `fairy-aurora.css` (Token-SoT), `alva.css` (SVG-Brand-Fills), Vendor-Bootstrap.css

### fa-entity-card + fa-entity-badge Komponente — NEU

**Wiederverwendbare Listen-Item-Card für 7 Entity-Types als Aurora-Native-Alternative zu Ad-hoc-Card-Markup.**

Listen-Item-Card-Component mit Entity-Icon (links), Title, Meta-Zeile, Status-Pill (rechts) für die häufigsten Listenseiten. Spezifische Varianten für **finding** / **nonconformity** / **risk** / **control** / **evidence** / **incident** / **audit** mit semantisch passenden Icons + Border-Akzenten. Plus **10 Entity-Badge-Variants** (zusätzlich `asset`, `policy`, `training`).

- **171 CSS-Zeilen** aus Aurora-v4.1-Spec in `fairy-aurora-components.css` portiert
- **2 neue Twig-Macros**: `templates/_components/_fa_entity_card.html.twig` + `_fa_entity_badge.html.twig`
- **Adoption**: `audit_finding/index` + `corrective_action/index` migriert (2 Listen-Pages)
- **Showcase** unter `/dev/design-system` (Live-Preview + Copy-Paste-Snippets)

### Schema- / Migration-Maintenance-UI im Data-Repair — NEU

**Always-on-buttons für Schema-Drift-Recovery aus dem Browser, ohne SSH/CLI.**

Im Data-Repair-Bereich des Admin-Moduls neuer **3-Card-Grid**: Migrations | Schema-Drift | Aktionen. Status-Pills (success / warning / danger) zeigen Drift-Stand auf einen Blick, Buttons sind „always-clickable" auch wenn alles grün ist. Wrappt Doctrine `MigrationStatusCalculator` und reused den existierenden `SchemaHealthService`.

- Neuer **`SchemaMaintenanceService`** wrappt Doctrine + reused `SchemaHealthService`
- **2 neue POST-Routes** (CSRF-guarded, `ROLE_ADMIN`): `app:schema:run-migrations`, `app:schema:reconcile`
- **Destructive-statement-detection** — `DROP TABLE` / `ALTER … DROP COLUMN` werden vor Ausführung markiert und brauchen explizite Bestätigung
- **20 neue Translation-Keys** (DE + EN) im `data_repair`-Domain

### Mapping-Quality-System — NEU

**MQS-Score 0-100, Lifecycle-Tracking, Reciprocity-Check und Provenance-Felder für alle 24 Cross-Framework-Mappings.**

Mapping-Qualität wird ab v3.2.0 nicht mehr „nach Bauchgefühl" bewertet, sondern numerisch über den **Mapping-Quality-Score (MQS)** aus 5 gewichteten Sub-Scores (Coverage, Granularity, Reciprocity, Provenance, Validation). Jedes Mapping hat einen **Lifecycle-State** (draft / review / published / deprecated) und einen Reverse-Mapping-Check, der sicherstellt, dass A→B und B→A sich nicht widersprechen.

- **39 Engineering-Tests grün** (MQS-Service 6 + Validator 7 + Lifecycle 7 + Loader 7 + sonstige)
- **13 Library-Files** mit MQS-Range **71.6–95.9** — NIS2 ↔ ISO **100 % reziprok**
- **Loader-Tests + 3 Reverse-Mappings + CISO-Coverage-View** als ergänzende Wellen
- **Standard-Mappings + 2 weitere Default-Sets** ausgeliefert
- Reciprocity 24/24 = 100 % Coherence (siehe Mapping-Library-Sektion oben)

### 38-Finding UX-Improvement-Sprint

**Sammel-Sprint, der 38 individuelle UX-Findings aus dem Persona-Audit über 27 Module adressiert.**

Findings reichten von „Filter-Chip nicht sichtbar bei aktivem Filter" über „Modal verliert Fokus bei Turbo-Navigation" bis zu „Empty-State zeigt CTA, aber User hat kein Schreibrecht". Commit `b2422287` listet alle 38 Items mit Modul-Bezug.

### Workflow Phase 10 Roadmap

- **14 neue regulatorische Workflows** aus Persona-Audit identifiziert und in `docs/WORKFLOW_REQUIREMENTS.md` Phase 10 dokumentiert
- **Supplier-Workflow auf 5 Steps erweitert** + Reject-Loops zwischen Step 2/3 und Step 4/5

### Compliance-Manager-Audit v2.3 — Score 99/100

**Alle 10 Frameworks erreichen Tool-Status 🟢 (vorher v2.2: 98/100).**

Compliance-Manager-Persona-Audit nach v3.2.0-Featureset re-evaluiert. Fortschritt v2.2 → v2.3:

- **32 FTE-Tage realisiert in 5 Tagen** (durch konsistente Data-Reuse-Architektur über alle neuen Features hinweg)
- **3 genuine Markt-Differenzierung** unter den 10 Frameworks: **EU AI Act**, **ISO 42001**, **MRIS** (kein Wettbewerber hat alle drei out-of-the-box)
- **Top-3 Reuse-Hebel** identifiziert: **MQS** (Mapping-Quality-Score), **MRIS-Reifegrad-Tracking**, **AI-Agent-Inventar** (eine Datenbasis → vier Frameworks)

### Setup-Wizard Performance

**Async-Job-Pattern auf alle Long-Running-Routes ausgeweitet — Wizard fühlt sich auch bei 30s-Schema-Create flüssig an.**

Der Setup-Wizard ist out-of-the-box Erstkontakt mit dem Tool. Lange Spinner ohne Feedback waren ein Ausstiegsfaktor. Mehrere Performance- + UX-Fixes ausgerollt:

- **Async-Job-Pattern** auf allen Long-Running-Routes (`schema-create`, `skip-restore`, `module-save`, etc.) mit Stimulus-Polling-Controller
- **Schema-Create**: transaction-wrap + multi-VALUES-Insert für Migrations-Metadata (~10× schneller bei 80+ Migrations)
- **Bypass Doctrine-Wrapper für DDL** — fixt „no active transaction"-Fehler bei `CREATE TABLE` in MariaDB
- **File-based async-job-status** (Session-Writes nach `fastcgi_finish_request` gehen sonst verloren)
- **`wizard-busy` nutzt `readonly` (nicht `disabled`)** auf Inputs, damit POST-Werte erhalten bleiben
- **Alva-Wait-Animation** auf allen Wizard-Forms (kein blanker Spinner mehr)
- **Stimulus explicit registration** für `async-job` + `wizard-busy` + `alva-dock` (statt nur Auto-Discovery)

### Bug-Fixes

- **`DataIntegrityService`**: `Document.getTitle()` → `getOriginalFilename()` (Title-Property existiert nicht mehr nach Document-Refactor)
- **Sample-Data-YAML-Audit** + snake_case-Resolver für 22 Samples — fixt Inkonsistenz zwischen Fixture-YAML und Entity-Setter-Naming
- **Smart-Setter-Resolver** im Sample-Data-Loader + DateTimeImmutable-Preference (statt mutable `\DateTime`)
- **Compliance-Import**: `form`-Variable an Card-Embed durchgereicht (Twig-Scope-Bug)
- **Docker**: `var/sessions` + `public/uploads` werden jetzt beim Build und Runtime erzeugt — fixt 500er bei frischem Container-Start

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
