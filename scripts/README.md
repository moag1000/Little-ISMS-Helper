# 🔧 Little ISMS Helper - Scripts

Dieses Verzeichnis enthält alle Automatisierungsskripte für Setup, Deployment, Quality Assurance und Tools.

## 📁 Verzeichnisstruktur

```
scripts/
├── setup/              # Setup & Datenbank-Skripte
├── deployment/         # Deployment-Prüfungen
├── quality/           # Qualitätssicherung (Übersetzungen, YAML)
├── tools/             # Hilfs-Werkzeuge (Lizenzen, PRs)
└── README.md          # Diese Datei
```

---

## 🚀 Setup & Datenbank

### Cloud-Threads: PHPStan ohne Docker (B3)

Claude-Cloud-Threads haben PHP 8.3 und erreichen GitHub nur über git: Zipballs von
`api.github.com`/`codeload` beantwortet der Proxy mit 403, `composer install --prefer-source` spiegelt jedes
Repository mit voller Historie (20+ Minuten, `phpstan/phpstan` hat gar keine Quelle im Lock).

```bash
python3 scripts/setup/cloud_vendor.py      # vendor/ aus composer.lock, ca. 30 s (Cache: var/cloud-vendor/)
vendor/bin/phpstan analyse -c phpstan.cloud.neon --memory-limit=2G --no-progress
```

`cloud_vendor.py` holt jeden gesperrten Commit flach per git (`git fetch --depth 1`, 16 parallel), packt ihn mit
`git archive` und installiert daraus ohne Netz — dieselben Versionen wie CI. `phpstan.cloud.neon` = `phpstan.dist.neon`
mit `phpVersion: 80400` (analysiert wie CI auf PHP 8.4, obwohl PHP 8.3 läuft). Einzelne Datei:
`vendor/bin/phpstan analyse -c phpstan.cloud.neon --memory-limit=2G src/Pfad/Datei.php`.

Eine Mutation gegen eine Gate-Regel (T4) läuft so: Datei in `src/` ändern, PHPStan über diese Datei laufen lassen,
der Fehler-Identifier (`gate65.…`) muss erscheinen, dann `git checkout -- src/`.

Regel für eigene PHPStan-Regeln: nur statische Reflexion (`$scope`, `ReflectionProvider`,
`$type->getObjectClassReflections()`), nie `is_subclass_of()`/`class_exists()`/`new \ReflectionClass()` auf
analysierte Klassen — das lädt die Klasse zur Laufzeit, und PHP 8.3 scheitert an PHP-8.4-Syntax.

PHPUnit braucht PHP ≥ 8.4 (PHPUnit 13); dafür bleibt die conda-Anleitung im Projektgedächtnis, CI ist die Referenz.

### setup/validate-setup.sh

**Zweck:** Umfassende Validierung der Installation (18+ Checks)

**Verwendung:**
```bash
cd scripts/setup
chmod +x validate-setup.sh
./validate-setup.sh
```

**Prüft:**
- ✅ PHP-Version (8.4+ erforderlich, 8.5 getestet)
- ✅ PHP-Extensions (pdo, intl, mbstring, xml, etc.)
- ✅ Composer Dependencies
- ✅ Entity-Migration Konsistenz
- ✅ AuditLog Konfiguration
- ✅ Foreign Key Constraints
- ✅ File Permissions

**Dokumentation:** [docs/setup/SETUP_VALIDATION.md](../docs/setup/SETUP_VALIDATION.md)

---

### setup/create-database.sh

**Zweck:** Sichere Datenbank-Erstellung mit interaktiver Einrichtung

**Verwendung:**
```bash
cd scripts/setup
chmod +x create-database.sh
./create-database.sh
```

**Features:**
- ✅ Interaktive Einrichtung mit Bestätigungen
- ✅ Automatische APP_SECRET Generierung
- ✅ Optionaler Admin-User
- ✅ ISO 27001 Controls (93 Controls)
- ✅ Schema-Validierung

**Dokumentation:** [docs/setup/SETUP_TOOLS.md](../docs/setup/SETUP_TOOLS.md#2-create-databasesh)

---

### setup/reset-database.sh

**Zweck:** Datenbank-Reset bei Fehlern (VORSICHT: Löscht alle Daten!)

**Verwendung:**
```bash
cd scripts/setup
chmod +x reset-database.sh
./reset-database.sh
```

**Warnung:** ⚠️ Löscht die komplette Datenbank und erstellt sie neu!

**Features:**
- ✅ Sicherheitsabfrage vor Löschung
- ✅ Vollständiger Reset
- ✅ Neuanlage mit Migrationen
- ✅ Optional: Admin-User & ISO Controls

**Dokumentation:** [docs/setup/SETUP_TOOLS.md](../docs/setup/SETUP_TOOLS.md#3-reset-databasesh)

---

### setup/test-setup.sh

**Zweck:** Test-Setup für Entwicklungsumgebung

**Verwendung:**
```bash
cd scripts/setup
chmod +x test-setup.sh
./test-setup.sh
```

**Features:**
- ✅ Schneller Test-Setup
- ✅ Beispiel-Daten
- ✅ Entwicklungs-Konfiguration

---

## 🚢 Deployment

### deployment/deployment-check.sh

**Zweck:** Produktions-Deployment-Prüfung

**Verwendung:**
```bash
cd scripts/deployment
chmod +x deployment-check.sh
./deployment-check.sh
```

**Prüft:**
- ✅ Produktions-Umgebung (APP_ENV=prod)
- ✅ APP_SECRET Sicherheit
- ✅ Database Connection
- ✅ HTTPS Konfiguration
- ✅ File Permissions
- ✅ Cache Konfiguration

**Dokumentation:** [docs/deployment/DEPLOYMENT_PLESK.md](../docs/deployment/DEPLOYMENT_PLESK.md)

### deployment/seed_every_table.php

**Zweck:** Zwei Zeilen in jede Tabelle einer migrierten Datenbank schreiben, damit die folgenden
Migrationen auf Daten laufen statt auf einem leeren Schema (CI-Job „Upgrade migrations with data“:
letzter Stable-Release → Daten → aktueller Stand). Zeile 1 trägt „leere“ Werte (`''`, `0`), Zeile 2
normale; Fremdschlüssel zeigen auf die gleiche Zeilennummer der Zieltabelle. Hintergrund: Issue #1171,
eine Migration scheiterte im Strict Mode nur, wenn Ziele existierten.

```bash
php scripts/deployment/seed_every_table.php 'mysql:host=127.0.0.1;dbname=isms_upgrade' user passwort
```

Nur gegen Wegwerf-Datenbanken verwenden.

---

## 🔍 Qualitätssicherung

### Gate-Übersicht

Alle Gates laufen im `code-quality`-Job von `.github/workflows/ci.yml`; die Nummer steht im Step-Namen. Die
sicherheitsrelevanten Kernregeln (11, 65, 72, 91, 92-PHP, 93-Code) sind seit T3 PHPStan-Regeln und laufen im Schritt
„Run PHPStan“ (siehe „PHPStan-Gate-Regeln“ unten).
Nicht vergeben: 38, 39, 49, 51, 66, 88. Ohne Nummer laufen dort zusätzlich `check_twig_macro_scope.py`,
`check-dql-mismatches.php` und `check_twig_entity_properties.py`. Nicht in CI (Handwerkzeuge):
`check_translations.py`, `verify_translations_v2.py`, `check_missing_translations.sh`, `check_aurora_icons_only.py`,
`check_aurora_v4.sh`, `audit_eu_mappings.py`.

| Gate | Skript (`scripts/quality/`) | Prüft |
|---|---|---|
| 1 | `check_twig_macro_imports.py` | Twig macro import-order |
| 2 | `check_twig_embed_domain.py` | Embed-block trans_default_domain |
| 3 | `check_migration_reserved_words.sh` | MariaDB-11 reserved-words in migrations |
| 4 | — | entfallen: Gate 20 prüft dieselbe Regel auf allen Migrationen |
| 5 | `check_aurora_anti_patterns.py` | Aurora anti-patterns on cards |
| 6 | `check_missing_translations.py` | Missing translation keys |
| 7 | `check_no_competitor_names.sh` | No competitor names |
| 8 | `check_double_locale_prefix.py` | No double /{_locale}/ prefix in controllers |
| 9 | `check_alva_hint_placeholders.py` | Alva-Hint placeholder consistency |
| 10 | `check_route_wildcard_collisions.py` | No wildcard-vs-literal route collisions |
| 11 | PHPStan `ModuleGateRule` | Module-gating discipline (P-6): regulatory form fields and framework routes |
| 12 | `check_flash_domain.py` | Flash translation-domain (P-5) |
| 13 | `check_freetext_legacy.py` | Freetext-legacy heuristic (P-15) |
| 14 | `check_workflow_configs.sh` | Workflow configs valid |
| 15 | `check_aurora_icon_names.py` | Aurora icon-name validity |
| 16 | `check_no_bi_classes.py` | No Bootstrap-Icons bi-* classes |
| 17 | `check_nested_forms.py` | No nested <form> tags |
| 18 | `check_route_methods.py` | #[Route] requires methods: arg |
| 19 | `check_legacy_route_import.py` | Forbid legacy Routing\\Annotation\\Route import |
| 20 | `check_ddl_transactional.py` | DDL migrations need isTransactional()=false |
| 21 | `check_bool_accessor_usage.py` | Bool-property accessor mismatch |
| 22 | `check_no_generic_throws.py` | Forbid generic exception throws |
| 23 | `check_translation_dynamic_keys.py` | Translation dynamic-key prefix registry |
| 24 | `check_audit_log_tenant.py` | AuditLog must set tenant |
| 25 | `check_god_class_size.py` | God-class size ratchet (baseline may only shrink) |
| 26 | `check_translation_nesting.py` | Translation top-level keys match domain |
| 27 | `check_em_writes_in_controller.py` | No EntityManager writes in controllers |
| 28 | `check_currentuser_test_args.py` | #[CurrentUser] action test-arg count |
| 29 | `check_form_render_completeness.py` | Form-render-completeness (fields flowing past submit) |
| 30 | `check_macro_arg_arity.py` | Twig macro positional-arg arity |
| 31 | `check_nested_twig_in_string.py` | Nested Twig print-expr inside string-literal macro args |
| 32 | `check_version_column_explicit.py` | #[ORM\\Version] columns require explicit type + name |
| 33 | `check_no_prepare_execute_migrations.py` | No PREPARE/EXECUTE dynamic SQL in migrations |
| 34 | `check_raw_json_textarea.py` | No raw TextareaType for JSON+array entity columns |
| 35 | `check_aurora_utility_misuse.py` | No Bootstrap size utilities on Aurora components |
| 36 | `check_route_trailing_slash.py` | #[Route] paths must not have trailing slash |
| 37 | `check_dql_non_portable.py` | No non-portable DQL date functions |
| 40 | `check_auto_form_field_whitelist.py` | _auto_form include requires explicit field whitelist |
| 41 | `check_admin_role_scope.py` | Admin controllers declare consistent role-scope guard |
| 42 | `check_status_enum_yaml_parity.py` | Status-Enum cases match Symfony Workflow YAML places |
| 43 | `check_backup_entity_coverage.py` | Backup entity coverage (every src/Entity/*.php is backed up or has documented exclusion) |
| 44 | `check_translation_issues.py` | Translation issues (hardcoded text / missing domains in Twig) — baseline-gated |
| 45 | `check_form_template_fields.py` | FormType <-> Template field-reference drift |
| 46 | `check_setter_nullability.py` | Entity setter param nullability matches property |
| 47 | `check_notblank_on_not_null.py` | NotBlank/NotNull Assert on NOT NULL scalar columns |
| 48 | `check_entity_reserved_words.py` | No SQL reserved-keyword column names in entities |
| 50 | `check_disabled_mapped_pair.py` | disabled FormField without mapped=>false |
| 52 | `check_twig_unsupported_tags.py` | unsupported Twig 3.x tags (continue / break) |
| 53 | `check_template_entity_getters.py` | renamed entity getters used in templates |
| 54 | `check_nav_area_parity.py` | sidebar main-area highlight parity |
| 55 | `check_breadcrumb_url_key.py` | breadcrumb crumbs must link via url: |
| 56 | `check_template_route_refs.py` | template path()/url() routes must exist |
| 57 | `check_no_direct_job_messenger.py` | controllers must not dispatch ExecuteJobMessage directly |
| 58 | `check_compliance_catalog.py` | compliance-catalog wiring consistency |
| 59 | `check_form_sections.py` | FormType SectionPolicy |
| 60 | `check_fixture_unread_keys.py` | fixture keys no loader reads |
| 61 | `check_design_tokens_sync.py` | CSS tokens in sync with design-system artifact |
| 62 | `check_aurora_legacy_markup.py` | Aurora legacy-markup ratchet |
| 63 | `check_yaml_duplicates.py` | no duplicate keys in translation YAML |
| 64 | `check_json_catch_throwable.py` | JSON actions catch Throwable |
| 65 | PHPStan `CsrfStateChangingActionRule` | state-changing actions need CSRF, GET actions do not flush |
| 67 | `check_native_dialogs.py` | no native browser dialogs, no inline JavaScript |
| 68 | `check_entity_manager_reset.py` | closed EntityManager recovered only via EntityManagerRecovery |
| 69 | `check_unreferenced_ui.py` | no unreferenced templates |
| 70 | `check_settings_readers.py` | every admin setting has a reader |
| 71 | `check_copy_slop.py` | UI copy rules (no 'erfolgreich'/'successfully', no '!', title suffix only in base) |
| 72 | PHPStan `TenantlessRowRule` | no tenant-less rows the TenantFilter hides |
| 73 | `check_list_skeleton.py` | list pages use the list skeleton (_fa_list_page) |
| 74 | `check_kpi_catalog.py` | management KPIs only via the KPI catalog |
| 75 | `check_show_skeleton.py` | detail pages use the detail skeleton |
| 76 | `check_trans_default_masking.py` | no \|trans\|default over a missing key |
| 77 | `check_date_display.py` | shown dates use \|fa_date / \|fa_datetime |
| 78 | `check_page_semantics.py` | page semantics and one status vocabulary |
| 79 | `check_stimulus_wiring.py` | every Stimulus reference resolves, no dead controllers |
| 80 | `check_gdpr_transfer_zones.py` | GDPR transfer zones current and in one place |
| 81 | `check_orm_sort_direction.py` | ORM sort directions use SortDirection |
| 82 | `check_workflow_roles.py` | workflow approvers and recipients are real roles |
| 83 | `check_entity_method_exists.py` | no method_exists() on typed entities |
| 84 | `check_csv_formula_injection.py` | spreadsheet cells go through CsvSanitizer |
| 85 | `check_fulfillment_single_writer.py` | fulfilment values have one writer |
| 86 | `check_requirement_controls_scope.py` | controls behind a requirement come from the tenant-scoped engine |
| 87 | `check_risk_band_literals.py` | risk bands come from the organisation's risk scale |
| 89 | `check_api_module_fields.py` | API fields of an optional module carry #[ModuleField] |
| 90 | `check_job_id_access.py` | every route that reads an async job by id checks its owner |
| 91 | PHPStan `MfaLoginStateRule` | one MFA state per login, no hand-made login, /setup closed while MFA is outstanding |
| 92 | `check_raw_output.py` (Twig) + PHPStan `MarkupOutputRule` (PHP) | output escaped unless reviewed (\|raw allowlist, \|fa_json, DocxDocumentFactory, Markup) |
| 93 | PHPStan `SsoClaimPolicyRule` + `check_sso_claim_policy.py` (config) | every SSO path checks its claims through SsoClaimPolicy |
| 96 | `tests/Security/WritePathParityTest.php` (PHPUnit) | a write rule on the entity (`#[CreateOnly]`, `#[ManagerOrResponsibleField]`, evidence) holds in every form, the import and the API groups |
| 97 | `check_tenant_pin_restore.py` | an organisation pinned in TenantContext is put back (runAs / try-finally) |

Code-Stil: Job „Code Quality“ führt `vendor/bin/php-cs-fixer fix --dry-run` mit `.php-cs-fixer.dist.php` (PSR-12,
`src/` + `tests/`) aus und schlägt fehl, sobald eine Datei abweicht. Ausgenommen sind die Dateien in
`baselines/php_cs_fixer.txt` (Altlast vor Einführung): eine davon mit
`vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php <datei>` richten und die Zeile löschen, nie Zeilen ergänzen.

### Mutationsgegenprobe der Dauertests (T, Welle 7; T2, Welle 8; T3, Welle 9)

Frage je Test und Gate: Wird er rot, wenn man die geschützte Eigenschaft absichtlich bricht? Wer
einen der Tests oder ein Gate ändert, wiederholt die Gegenprobe für seine Änderung. Ein Test, der eine
Lücke festschreibt, wird umgedreht, nicht gelöscht.

| Test / Gate | Gegenprobe (absichtlich gebrochen → rot) | Vorher übersehen |
|---|---|---|
| `HoldingLensAndStructureAuditTest` | Konzernsicht (`HoldingScope::read()`) sieht Vorfälle einer Tochter nur mit `visibleToHolding`, eigene immer; Umhängen und Governance-Regeln über beide Admin-Seiten landen mit Vorher/Nachher im Audit-Log | Filter `HoldingIncidentFilter` nicht scharf geschaltet → vertraulicher Vorfall in Konzernsicht (rot, geprüft) |
| `DeadlineLinkReachabilityTest` | Je Fristquelle eigene Organisation nur mit ihren Modulen, je Empfängerrolle (Rückfallrollen, Inhaber als User/Person) ein Konto, das `ResponsibilityResolver::recipients()` wirklich nennt; GET auf den Link, bis zu 5 Weiterleitungen, rot bei ≠ 200 oder Landung auf Dashboard/Login/MFA/Setup. Selbsttest `checkerSeesEveryKindOfGate`: eigenes Risiko ok; Rolle zu klein, Leitung auf Formular, DSB auf Admin-Seite, fremde Organisation → jeweils rot | `grants()` ließ jedes Voter-Attribut durch, nur GET ohne Weiterleitung, Quellen ohne Link übersprungen, PERSONA_* falsch abgebildet. Fund: 6 Quellen ohne Modul (Link führte bei abgeschaltetem Modul aufs Dashboard). W6: `WITHOUT_LINK` leer (TLPT-Seite), `MAIN_ACTION` schickt bei Review-Fristen (VVT, DSFA, TIA, Risiko, Control) das Hauptformular ab; Funde: VVT/DSFA-Review nur für Manager, TIA ohne Review-Aktion, Risiko-Review 403 für Leitung als Inhaberin, 7 weitere Quellen ohne Modul, Seite „Modul nicht aktiv“ galt als erreichbar, 500 auf der Feststellungsseite |
| `WizardReferencesHitCatalogueTest` | „6.1.1/6.1.2 / 9.99" und zusätzliche Anforderung `ISO27001-6.1.9` → rot | Ein Treffer deckte alle Referenzen eines Checks; `clause_coverage`-Unterchecks und `requirements`-Ids nicht geprüft |
| `ApiEvidenceFieldNamesTest` | Feld des Entity-Formulars mit Nachweisnamen ohne Markierung → rot; `#[ApiReadOnlyEvidence]`-Feld als freies Formularfeld → rot (`evidenceFieldsAreNotEditableInTheEntityForm`) | Formular-Ausnahme verbarg genau die Freigabefelder; nur `src/Entity/*.php` ohne Unterordner. Fund: Risikoakzeptanz (wer/wann) war im Formular frei eintragbar → jetzt schreibgeschützt; 23 weitere Nachweisfelder im Formular als „open since T" gelistet |
| `ApiEvidenceFieldNamesTest` (S12) | Freigabefeld im Änderungsantrag wieder editierbar, Restrisiko-Sperre entfernt, Lieferanten-Checkbox editierbar → rot; dazu `LifecycleEvidenceStamperTest` (Stempel bei approve entfernt), `AcceptedRiskUnchangedValidatorTest` (Constraint vom Risiko entfernt), `IncidentReportingMarkTest` (Rolle zurück auf ROLE_USER, Zeit in der Zukunft erlaubt), `ManagementDecisionServiceTest` (Billigung setzt Flag nicht), `RiskAcceptanceWorkflowServiceTest` (Neubewertung behält Akzeptanz) → je rot (9/9) | Prüfte nur `<Entity>Type` und duldete die 23 Felder „open since T“. Fund: Änderungsantrag-Freigabe, Leitungs-Risikoakzeptanz Lieferant, Akzeptanz-Ablauf/Restwert nach Freigabe, Meldezeitpunkte frei eintragbar → jetzt schreibgeschützt bzw. vom Schritt gestempelt; 7 Felder bleiben mit Grund editierbar |
| `McpReadOnlyToolsWriteNothingTest` (neu) | Jedes Tool mit `readOnlyHint` wird über `/mcp` aufgerufen (Ressourcen, Id, Suchbegriff), ein Doctrine-onFlush-Spion zählt Schreibzugriffe; `theSpySeesAWrite` beweist den Spion | `McpToolClassificationTest` sah nur Quelltext per Regex, Schreiben über aufgerufene Dienste blieb unsichtbar |
| `OutboundUserUrlClientTest` | Guzzle-Brücke muss `NetworkExceptionInterface` mit `TransportException` des Wächters werfen (Positivkontrolle: öffentliche Adresse geht durch); Scan erkennt Guzzle, PSR-18, curl, Sockets, SOAP, Web-Push, Stream-Wrapper auf URLs (`theScanRecognisesEveryKindOfClient`) | Jede `\Throwable` zählte als Abwehr; Regex kannte nur `HttpClientInterface`. Fund: Web-Push (S11 behebt) |
| Gate 82 + `RegulatoryWorkflowRegistryTest` (S17) | `approver_role: ROLE_CAPA_OWNER` in `capa.yaml` → Gate rot; Schritte unter `regulatory_metadata.steps` verschoben → Gate rot; Datenbankschritt auf `ROLE_RISK_MANAGER` gesetzt → `aDriftedRoleIsReportedAndAlignedInPlace` meldet die Abweichung | Gate und Loader lasen `regulatory_metadata.steps`, die YAMLs haben `metadata.steps`: kein Schritt geprüft, Loader lieferte null; Tests nutzten ein Stub im Loader-Layout |
| Gate 82 + `AdminSettingConsumersTest` + `LifecycleConfigResolverTest` (S15) | `submit_for_review` mit `to: published` → Gate rot; `roles: [ROLE_USER]` in `notification_delivery.yaml` → rot (keine Pauschalausnahme mehr); `PUBLIC_ACCESS` auf `consent.revoke` → rot; zweiter Schreiber von `new LifecycleConfig()` oder Override-Schlüssel ohne Leser → Test rot; gespeicherter Override `four_eyes: false` / `roles: [ROLE_USER]` / `ROLE_ADMIN` auf Leitungsübergang → Resolver ignoriert ihn | Gate prüfte Arbeitsschritte nur nach Namen und nahm die Zustellung pauschal aus; Schritt-Overlays unter `/admin/workflows` wurden gespeichert, aber nie angewendet; Override-Seite nahm Freitextrollen und schaltete Vier-Augen ab; Resolver nahm den aktuellen statt den Mandanten des Datensatzes |
| Gate 62, 71–75, 77–81, 83–87, 89, 90 | Je Gate eine Mutation pro Schreibweise (andere Anführungszeichen, umgekehrte Klassenreihenfolge, Macro-Hash, Zeilenumbruch im Aufruf, Yoda-Vergleich, nullsafe/first-class callable, Alias-Variable, Partial statt Seite) | Funde behoben: Seitensemantik/Stimulus/Datumsanzeige in Templates, CSV-Zelle in `TisaxConsolidateCommand`, Risikostufen-Literale in Alva-Hinweisen und Risikoregister-Export (jetzt Skala der Organisation), globale `mappedControls` in 2 Templates, Gate 89 Negationsfehler |
| Gate 91 + `MfaLoginPathsTest` (T2, Welle 8) | `MfaSessionState` nur im Kommentar/`use`, `->setToken ($t)`, `$session->set('_security_main', …)`, `begin()` in einer anderen Methode → Gate rot; zweite Firewall `partner:` mit `form_login` → `noOtherFirewallLogsAPersonIn` rot; `instanceof AzureOAuthAuthenticator`-Ausnahme im `MfaLoginListener` → rot (Ereignis trägt jetzt die echte Authenticator-Klasse) | Kommentar befreite die ganze Datei; nur `main` geprüft; Test nutzte einen Stub-Authenticator |
| Gate 92 (T2) | `{% autoescape false %}`, `use Twig\Markup as Html; new Html(…)`, `'is_safe_callback'` → rot | Block-Abschaltung, Alias und Callback sah die Regel nicht |
| Gate 82 (T2) | `findByRoleInTenant (\n "ROLE_X"` mit Leerzeichen, Zeilenumbruch, `"` → rot | Suche lief zeilenweise |
| Gate 72 + `TenantlessInsertGuard` (T2) | `tenant IN (:t) OR tenant IS NULL`, `new Asset()` in `src/Command/`, veralteter Baseline-Eintrag → rot; Zeile ohne Mandant in einem Request (auch Factory/`clone`/Hilfsdienst) → Flush scheitert im Test (`TenantlessInsertGuardTest`) | Konsolenbefehle ausgenommen; nur Regex über `new`; Baseline durfte veralten. Fund: `isms:load-annex-a-controls` legte Controls ohne Mandant an und setzte Controls anderer Organisationen zurück (jetzt je Organisation, `LoadAnnexAControlsCommandTest`) |
| Gate 65 (T2) | `$ok = isCsrfTokenValid()` ohne Verwendung, nackter Aufruf, `methods: 'POST'` als String, `createForm()` ohne `handleRequest`+`isSubmitted` → rot; veralteter Baseline-Eintrag → rot | Jeder Aufruf zählte, auch mit verworfenem Ergebnis; nur Array-Schreibweise |
| Modul-Gate (T2) | NIS-2-Feld in `isModuleActive('core')`, TISAX-Feld in `privacy`, nacktes `$this->checkModuleActive('privacy');` → rot | Irgendein Modul-If galt als Gate; verworfener Redirect galt als Prüfung |
| Gate 84 (T2) | `getAuthorityReferenceNumber()` (string) roh in `fputcsv`, `fwrite($h, implode(';', [$s->getName()]))` und über eine Variable → rot | Getter auf `…Number`/`…Id` galten als Zahl; handgebaute CSV-Zeilen unsichtbar. `setCellValueExplicit(…, TYPE_STRING)` gilt jetzt als sicher (Textzelle) |
| Gate 78 (T2) | `x.status == 'open' ? 'danger' : (… ? 'success' : 'neutral')` mehrzeilig → `ternary-tone`; `pill.render(x, 'info')` → `pill-tone` | W7: Töne aus `App\Twig\StatusVocabulary` (eine Tabelle; `StatusToneConsistencyTest`); Baseline nur noch die 3 Lieferanten-Kritikalitäts-Templates aus W8 |
| Gates 73, 75, 77, 87, 89, 90 (T2) | `list.html.twig` / `detail.html.twig` / `view.html.twig` ohne Skelett, Marker „no record“ auf einer Seite mit Kommentaren/Status-Band, Datum in `<textarea>`, `$rating >= 20`, `isModuleActive(Modules::PRIVACY)`, `'/var/jobs/'` außerhalb `JobStatusService`/`WorkerHealthService` → je rot | Dateinamen, Marker, Textarea, Variablenname, Fremdkonstante und direkte Jobdateien waren Restlücken |
| `CrossTenantApiContractTest`, `ApiResourceContractTest` (T2) | Ressource in `src/Entity/<Ordner>/`, Ressource ohne `$tenant` ohne Grund, Operation mit weiterer URI-Variable ohne Grund, Custom-`Post /{id}/…` ohne `object`-Abstimmung → rot; Custom-Post mit fremder Id wird geprobt | `glob('src/Entity/*.php')`, Sub-Ressourcen still übersprungen, jeder Post galt als Anlage |
| `CrossTenantMcpContractTest` (T2) | Werkzeug ohne Probe und ohne Grund in `NOT_PROBED` → rot; KPI-Zähler, der Datensätze anderer Organisationen mitzählt → rot (drei weitere fremde Datensätze je Ressource dürfen keine Zahl ändern) | Werkzeuge nur mit `id`/Pflichtparametern fielen durch; Zähler tragen keinen Marker |
| Gate 94 + `StoredFilePathContractTest` (S19) | `AuditFreeze::pdfPath` aus `StoredFilePath::FIELDS` entfernt → Metadaten-Abgleich rot; `isFileName()` lässt `/` zu → Traversal je Spalte rot; Ordnerprüfung in `inDirectory()` entfernt → Symlink-/Traversal-Fall rot; alte Verkettungen in AuditFreeze-, Profil-, Benutzer-, Organisations-Controller und `BackupService` → Gate rot | Pfadspalten waren nie als Daten behandelt: der Organisations-Restore setzte sie frei, fünf Stellen hängten sie an Verzeichnisse |
| Gate 96 + `WriteRulePathsTest` (S22) | `#[AsDoctrineListener]` an `WriteRuleGuard` entfernt → Herabstufen, `detectedAt`, Weitergabe und REST/MCP rot; `PRE_SET_DATA` der `WriteRuleFormExtension` abgeklemmt → Formularparität rot; `ImportEntityWriter::assertValid()` leer → beide Importfälle rot | Regeln standen nur im FormType; Import, REST und MCP umgingen sie |
| `ApiTokenBoundaryTest` (T2) | `str_contains($path, '/api/')` im Matcher → `aTokenIsAcceptedOnlyOnApiPlatformAndMcpRoutes` rot (alle Router-Routen; POST auf die app-eigenen `…/api/`-Endpunkte wird abgewiesen) | Nur vier feste Pfade geprüft |
| `BackupArchiveExtractionTest`, `SpreadsheetUploadReaderTest` (T2) | `document_versions` aus `ARCHIVE_PROTECTED_FOLDERS` entfernt → rot; `new Reader\Xlsx()`, `IOFactory::createReader('Xlsx')`, Reader per `use … as` → rot | Versionen landeten unbemerkt im Web-Root; Scan kannte nur `IOFactory::load`/`createReaderForFile` |
| `TisaxConsolidateCommandTest` (T2, aus S18) | Bewertung einer Organisation auf die gemeinsame Katalogzeile verschoben → rot | Befehl schrieb Upload-Organisation und Reifegrad der ersten Organisation auf die Zeile aller |

**T3 (Welle 9, Bericht 9-mutations):** Alle Mutationen des Berichts sind rot. Für die PHPStan-Regeln steht jede
Mutation als `// expect:`-Zeile in der Fixture der Regel (`tests/PHPStan/Rule/Gate/Fixtures/`), für die Python-Gates
in `scripts/quality/tests/test_w9_gate_mutations.py`, `test_check_raw_output.py`, `test_check_sso_claim_policy.py`.

| Punkt | Test / Gate | Gegenprobe (absichtlich gebrochen → rot) | Vorher übersehen |
|---|---|---|---|
| 1 | Gate 93 (`SsoClaimPolicyRule`) | Alle `SsoClaimPolicy`-Aufrufe aus `AzureOAuthAuthenticator` entfernt; Policy nur im Kommentar/Docblock; neuer Authenticator ohne Eintrag in `CLAIM_CHECKS`; `GenericOidcAuthenticator` ruft den Flow nicht; `processResponse()`/`(null)`; `getHttpHost()`/`getUri()` in SSO-Code; `[...$id, ...$ui]`, `array_replace`, `$ui + $id`; `$allowEmailLinking = TRUE`; `.env` `"true"`/`1` | Name im Docblock von `SsoAccountLinkPolicy` genügte; Authenticatoren nur aus der Blockliste von `security.yaml` |
| 2 | Gates 65, 91, Modul-Gate (PHPStan) | `IsCsrfTokenValid` nur im Docblock/Kommentar; `// #[RequiresModule]`; `->begin(` auf einem anderen Objekt (`$this->stopwatch->begin()`); Marker ohne Grund | Kommentare und Namen zählten als Schutz |
| 3 | `MfaLoginPathsTest`, `ApiTokenBoundaryTest`, `SsoClaimContractTest` | `when@prod: security: firewalls: main: custom_authenticator: …` (Singular) → alle drei rot | Tests lasen die Rohdatei; lesen jetzt die aufgelöste Konfiguration je Umgebung (`ResolvedSecurityConfig`) |
| 4 | Gate 82 | `step_type: review`/`auto_action`/ohne Typ mit `ROLE_USER`; unbekannter Typ; `when@prod`-Workflow; Rolle über Konstante/Variable/benanntes Argument; Seeder mit `setApproverRole('ROLE_USER')` | Nur `approval` galt als Freigabe — echter Fund: Schritt „published“ der Policy-Freigabe stand auf `auto_action` + `ROLE_USER` (behoben, Migration) |
| 5 | `ExportRoutesGatedTest` | `#[IsGranted('PUBLIC_ACCESS')]`/`IS_AUTHENTICATED_*`; csv/xlsx/pdf/print/report im Namen; „export“ nur im Klassenpräfix | Jedes Attribut galt als Gate — 7 Report-Routen ohne Attribut gefunden, tragen jetzt `ROLE_USER` |
| 6 | `PublicLinksFromDefaultUriTest` | Datei erwähnt `PublicUrlGenerator`, baut daneben eine absolute URL | Erwähnung befreite die ganze Datei |
| 7 | `DeadlineLinkReachabilityTest` | Fristquelle, deren Link auf keine Haupt-Action zeigt | feste Liste von 5 Quellen |
| 8 | `CrossTenantApiContractTest`, `CrossTenantMcpContractTest` | Ressource in `src/ApiResource/`; Entity in einem Unterordner ohne MCP-Probe | Entdeckung nur über `src/Entity/*.php` |
| 9 | `TenantlessInsertGuard` | Update setzt `tenant = null` → Flush scheitert im Test | Nur Inserts geprüft |
| 10 | Gates 72, 84, 87, 90, 92 und Dauertests (`RequirementMaturityAccessTest`, `SpreadsheetUploadReaderTest`, `OutboundUserUrlClientTest`, `AdminSettingConsumersTest`, `RegulatoryWorkflowRegistryTest`) | Aliase, Group-Use, Zeilenumbruch nach `new`, `Markup::class`, Konstanten, `sprintf`, `$line = implode(';', …)`, `$s->name`, `> 19`, Alias-Variable eines Risikowerts, privater Job-Helfer, `'/var/'.'jobs/'`, Ternär-Zweig in `trans`-Parametern, `template_from_string()` | Textsuche zeilenweise |

Bekannte Restlücken (bewusst nicht erkannt, je Gate): 78 `<h1>` in Partials und die 3 Lieferanten-Templates der
`ternary-tone`-Baseline; 62 `templates/dev`; 75 ein Marker „no record“ auf einer Datensatzseite ohne Kommentar,
Status-Band, Freigabe oder Historie; 84 Getter-Namen, die in einer Klasse Zahl und in einer anderen Text sind
(gelten als Text); 90 Jobdateien über einen anderen Pfadbau als `var/jobs`.

### quality/check_translations.py

**Zweck:** Überprüfung der Übersetzungskonsistenz (DE/EN)

**Verwendung:**
```bash
cd scripts/quality
python3 check_translations.py
```

**Prüft:**
- ✅ Fehlende Übersetzungsschlüssel
- ✅ Inkonsistenzen zwischen DE und EN
- ✅ Ungenutzte Schlüssel
- ✅ Formatierungs-Probleme

**CI-Integration:** keine (Handwerkzeug).

---

### quality/verify_translations_v2.py

**Zweck:** Erweiterte Übersetzungsverifizierung

**Verwendung:**
```bash
cd scripts/quality
python3 verify_translations_v2.py
```

**Features:**
- ✅ Detaillierte Verifizierung
- ✅ Platzhalter-Prüfung (%placeholder%)
- ✅ HTML-Tag-Konsistenz
- ✅ Parameter-Matching

**CI-Integration:** keine (Handwerkzeug).

---

### quality/check_unreferenced_ui.py (Gate 69)

**Zweck:** Kein totes UI. Ein Template, das nirgends gerendert, eingebunden,
eingebettet, erweitert oder importiert wird, wird bei jedem Design-System-Durchgang
mitgeschleppt und täuscht dem nächsten Leser ein lebendes Feature vor
(UX-Review 2026-11: 20 Templates; 33 weitere, nachdem der eigene
Verwendungs-Kommentar eines Templates nicht mehr als Referenz zählt).
Tote Stimulus-Controller prüft Gate 79.

**Verwendung:** `python3 scripts/quality/check_unreferenced_ui.py --baseline scripts/quality/baselines/unreferenced_ui.txt`.
Ein Template gilt als referenziert, wenn sein Pfad unter `templates/` (mit oder
ohne `.html.twig`) in `src/`, `templates/`, `config/` oder `tests/` außerhalb
des Templates selbst vorkommt; `bundles/` und `emails/` sind ausgenommen.
Dynamisch gebaute Namen gehören mit Begründung in die Baseline.

**CI-Integration:** Gate 69 im `code-quality`-Job.

---

### quality/check_stimulus_wiring.py (Gate 79)

**Zweck:** Stimulus scheitert lautlos: Ein `data-action="click->foo#bar"` ohne
Controller `foo` oder ohne Methode `bar()` tut einfach nichts. Der Durchgang
2026-11 fand so einen unbenutzbaren Import-Upload, Löschformulare ohne
Bestätigung, eine nie erscheinende Konzern-Tochterauswahl im Audit und ein
leeres Trenddiagramm.

**Prüft** in `templates/` und `src/` (Twig-Markup, Twig-Hashes, PHP-`attr`):
jeder Bezeichner in `data-controller`, in Aktionen und in `data-foo-target`
existiert in `assets/controllers/` (oder ist in `stimulus_bootstrap.js` unter
dem Namen registriert), jede Aktionsmethode existiert, jedes Target steht in
`static targets`, jedes `data-foo-x-value` in `static values`; kein Controller
weist `this.scope`/`this.element`/… zu (wirft im Strict Mode, `connect()` stirbt
unsichtbar); jeder Controller wird irgendwo angehängt. Twig-Ausdrücke und
`{# … #}`-Kommentare werden übersprungen.

**Verwendung:** `python3 scripts/quality/check_stimulus_wiring.py --baseline scripts/quality/baselines/stimulus_wiring.txt`.
Baseline-Zeilen: `art  datei  detail  # grund` (Zeilennummer optional), nur für
zur Laufzeit gebaute Namen.

**CI-Integration:** Gate 79 im `code-quality`-Job.

---

### quality/check_settings_readers.py (Gate 70)

**Zweck:** Jede Einstellung unter `/admin/settings` wirkt. Vor 2026-11 speicherte
der Screen 14 Felder, die nirgends gelesen wurden, darunter „MFA erzwingen“.
Jedes Feld aus `ApplicationSettingsType` / `SecuritySettingsType` braucht
außerhalb von `SystemSettingsController` einen Leser: `getSetting('<kategorie>', '<feld>')`,
den Punkt-Schlüssel `'<kategorie>.<feld>'` (TenantSettingResolver) oder ein
`getSetting('<kategorie>', $var)` in einer Klasse, die den Feldnamen als
String nennt.

**CI-Integration:** Gate 70 im `code-quality`-Job, keine Baseline.

---

### quality/check_date_display.py (Gate 77)

**Zweck:** Angezeigte Datumswerte laufen über `|fa_date` / `|fa_datetime`
(Format und Zeitzone aus `/admin/settings/application`; Sekunden für Audit-Spuren
`|fa_datetime(true)`), Uhrzeiten über `|fa_time` (`fa_time(true)` mit Sekunden), Monate über
`|fa_month` („November 2026“ in der Sprache der Anfrage). Jedes fest verdrahtete
`|date('…')` in der Textausgabe ist ein Fund (seit W4 jedes Format, z. B. `'F Y'` mit
englischen Monatsnamen; ausgenommen Maschinenformate `U`, `u`, `c`, ISO), auch in `{% set %}`-/Include-Hashes (`value:`, `subtitle:`,
`generated_at:` …) und in String-Verkettung. In HTML-Attributen, `<script>` und
`path()`/`url()` bleibt `|date` (Maschinenformat); Routen-Parameter oder Gruppierungs-
schlüssel in einem `{% set %}` bekommen in der Zeile davor `{# machine-date: … #}`.
Erkennung in `scripts/quality/date_display.py`; `--fix <template>` ersetzt die Funde.
Ein leeres Datum rendert `—`, also kein `x ? x|fa_date : '—'` mehr.

**CI-Integration:** Gate 77 im `code-quality`-Job, Baseline mit Anzahl je Datei
(`baselines/date_display.txt`, darf nur sinken; seit dem Konsistenz-Sweep 2026-11 leer).

---

### quality/check_page_semantics.py (Gate 78)

**Zweck:** Seitensemantik und ein Status-Vokabular. Regeln: kein eigenes `<main>`
in Templates, die `base.html.twig` erweitern (`nested-main`); höchstens ein `<h1>`
und keines neben dem Page-Header (`extra-h1`); keine lokale Farbtabelle
(`{% set <name>Colors|Tones|Variants = {...} %}` mit Ton-Namen als Werten, seit W4 auch jede
an Ort und Stelle nachgeschlagene Map wie `{draft: 'secondary', …}[x.status]` oder
`{% set status_class = {…} %}` mit zwei Ton-Werten; PDFs und Mails ausgenommen), stattdessen
`pill_tone_severity()` für Schweregrad/Kritikalität/Priorität,
`_components/_status_vocabulary.html.twig` (`pill()` / `tone()`) oder `_fa_status_chip`
(`status-map`; Button-Varianten-Maps mit `btn`/`button` im Namen sind ausgenommen); seit T2 auch keine
Ton-Kette per Ternär auf einem String-Vergleich (`ternary-tone`, Zahl-Schwellen ausgenommen) und keine Pill-Töne
`info`/`secondary` (`pill-tone`); Bootstrap-Tooltips nur auf fokussierbaren Elementen (`tooltip-unreachable`).

**CI-Integration:** Gate 78 im `code-quality`-Job, Baseline `baselines/page_semantics.txt`
(darf nur sinken).

---

### quality/check_trans_default_masking.py (Gate 76)

**Zweck:** `'key'|trans({}, 'domain')|default('Text')` maskiert fehlende
Übersetzungen: Symfony liefert bei fehlendem Key den Key selbst zurück, `default`
greift also nie und der Nutzer sieht den rohen Key. Das Gate prüft jede Stelle
in `templates/` mit literalem Key und literaler Domain und scheitert, wenn der Key in
`translations/<domain>.de.yaml` oder `.en.yaml` fehlt (verschachtelt oder als
flacher Punkt-Key, auf jeder Ebene). Dynamische Keys (`('a.' ~ x)|trans`) sind
nicht prüfbar und werden übersprungen. `--list-missing` gibt die Treffer als TSV aus.

**CI-Integration:** Gate 76 im `code-quality`-Job, keine Baseline.

---

### quality/check_gdpr_transfer_zones.py (Gate 80)

**Zweck:** `config/gdpr_transfer_zones.yaml` ist die eine Länderliste für EU, EWR,
Angemessenheitsbeschlüsse (Art. 45 DSGVO) und das EU-US Data Privacy Framework
(`App\Privacy\GdprTransferZones`). Sie speist die Ebene „Drittland“ der
Standortkarte, die Lieferkettenprüfung, das EWR-Kennzeichen im DORA-Register und
die Framework-Empfehlungen. Das Gate scheitert, wenn `as_of` fehlt oder älter als
12 Monate ist (Liste mit der Seite der EU-Kommission abgleichen, Datum hochsetzen),
wenn ein Code kein ISO-3166-Alpha-2-Code ist oder in zwei Zonen steht, oder wenn
eine PHP-Datei unter `src/` wieder eine eigene EU-Liste (fünf oder mehr EU-Codes
in einem Listen-Literal) enthält.

**CI-Integration:** Gate 80 im `code-quality`-Job, keine Baseline.

---

### quality/check_translation_nesting.py (Gate 26)

**Zweck:** (1) Top-Level-Keys einer `<domain>.<locale>.yaml` müssen zur Domain
passen (Baseline `baselines/translation_nesting.txt`, darf nur schrumpfen).
(2) Kollisionen: Symfony flacht verschachtelte und flache Punkt-Keys zu einem
Katalog ab. Definiert eine Datei denselben Endschlüssel zweimal (z. B. `common: {loading: A}`
und ein flaches `common.loading: B`, auf beliebiger Ebene), gewinnt die spätere
Definition, die frühere ist tot. Das Gate meldet die frühere. Ein Skalar `a.b` neben
einem Teilbaum `a.b.c` ist keine Kollision. Kollisionen werden nicht baselined.

---

### quality/check_show_skeleton.py (Gate 75)

**Zweck:** Jede Detailseite (`templates/**/show.html.twig`) läuft über das eine
Detail-Gerüst: Statusband (`_fa_status_band`: Status, Verantwortlich, nächste
Fälligkeit, laufende Freigabe), Ankerleiste (`_fa_anchor_bar`), Block
„Verknüpfungen" (`_fa_entity_links`, Nachweiskette Asset → Risiko → Control →
Nachweis → Vorfall → Schwachstelle aus `App\Service\Detail\EntityLinks`), dann
die Fachabschnitte, zuletzt Historie (`_fa_show_history`) und Kommentare. Eine
Seite auf dem Gerüst darf die alte `_entity_link_matrix` nicht mehr enthalten.
Noch nicht umgestellte Seiten stehen in `baselines/show_skeleton.txt` und dürfen
nur weniger werden (seit der Umstellung der restlichen Detailseiten leer).

**CI-Integration:** Gate 75 im `code-quality`-Job.

---

### quality/check_orm_sort_direction.py (Gate 81)

**Zweck:** Sortierrichtungen für Doctrine ORM sind `\SortDirection::Ascending` /
`\SortDirection::Descending`, keine Strings. doctrine/orm 3.7 meldet `'ASC'`/`'DESC'`
in `orderBy()`/`addOrderBy()` und in `#[ORM\OrderBy]` als veraltet (Issue 11313),
bei jedem Aufruf bzw. beim Cache-Warmup. Der DBAL-QueryBuilder nimmt weiter Strings;
solche Zeilen tragen `// @dbal-order`. Keine Baseline.

**CI-Integration:** Gate 81 im `code-quality`-Job.

---

### quality/check_workflow_roles.py (Gate 82)

**Zweck:** Freigeber und Empfänger in Workflows sind echte Rollen. Geprüft werden
`config/workflows/**/*.yaml` (Transition-`roles`, `approver_role`, `escalation_role`),
die PHP-Seeder der Workflow-Schritte und Empfänger-Abfragen (`findByRoleInTenant('ROLE_…')`
u. a.) gegen `role_hierarchy` in `config/packages/security.yaml`. Eine unbekannte Rolle
wird nie vergeben: der Schritt wartet auf niemanden, niemand wird benachrichtigt. Außerdem
darf kein Freigabeschritt und keine approve/reject/verify-Transition `ROLE_USER` tragen
(das hat jede angemeldete Person). Ein Eigner am Datensatz ist eine Beziehung, keine Rolle.
Seit S17 liest das Gate die Schritte dort, wo der Loader sie liest (`metadata.steps`), lehnt die
verschachtelte Form `regulatory_metadata.steps` ab und verlangt für jede Datei unter
`config/workflows/regulatory/` Schritte, `title` und `entity_type`.
Seit S15: ein Arbeitsschritt für `ROLE_USER` (start/submit/…) darf weder in einen Endzustand noch in
einen Zustand führen, in den eine Entscheidungs-Transition führt; `PUBLIC_ACCESS`/`IS_AUTHENTICATED*`
zählen wie `ROLE_USER`; keine Pauschalausnahme mehr (Zustellungen sind systemgetrieben, `roles: []`).
Mandanten-Overrides prüft `App\Lifecycle\Config\LifecycleOverridePolicy` in PHP (gleiche Linie).
`PENDING` im Skript nimmt Rollen auf, die eine andere Änderung gerade einführt. Keine Baseline.
Seit T3: Schritttypen sind nur `approval` und `notification`; jeder Schritt, der keine Benachrichtigung ist,
ist eine Freigabe (so behandelt ihn `WorkflowService`) und darf nicht auf `ROLE_USER` stehen, ein unbekannter
`step_type` ist rot. `when@*`-Workflows werden gelesen; Empfänger-Abfragen auch mit Klassenkonstante, Variable
(alle Zuweisungen und Ternär-Zweige), benanntem `role:`-Argument; Seeder über ganz `src/Command`
(`'approverRole' =>`, `approverRole:`, `setApproverRole()`, `'role' =>`). `php_source.py` (kein Gate) ist der
gemeinsame Helfer der Gates 82/84/87/90: Kommentare raus, Aliase und Group-Use aufgelöst, Klammern ausbalanciert.

**CI-Integration:** Gate 82 im `code-quality`-Job.

---

### quality/check_entity_method_exists.py (Gate 83)

**Zweck:** Kein `method_exists()` auf Variablen, die als `App\Entity`-Klasse typisiert
sind (Parametertyp, `@var`, `instanceof`, `new`). Ist die Methode vorhanden, ist der Aufruf
toter Code; fehlt sie, läuft das Feature still nie (so fehlten Findings in „Mein Tag“).
PHPStan meldet nur den „immer wahr“-Fall. Das Gate scheitert auch, wenn
`phpstan-baseline.neon` einen `method_exists`-Fund auf einer Entity schluckt. Keine Baseline.

**CI-Integration:** Gate 83 im `code-quality`-Job.

---

### quality/check_csv_formula_injection.py (Gate 84)

**Zweck:** Jede Stelle in `src/` (seit S6 auch `src/Command/`), die eine Tabellenzelle schreibt
(`fputcsv()`, PhpSpreadsheet `setCellValue*()`/`fromArray()`), schickt Datensatztext durch
`App\Util\CsvSanitizer::sanitize()` (oder `sanitize*()`/`csvCell`/`streamCsvExport`). Sonst läuft
ein Lieferantenname wie `=HYPERLINK(…)` beim Öffnen in Excel als Formel (CWE-1236); so war es beim
DORA-RoI-CSV und beim FTE-Board-CSV. Seit S6 prüft das Gate **jede Schreibstelle** statt jeder
Datei: ein Getter (`->getTitle()`) ohne Sanitizer fällt durch, ebenso eine bloße Variable in einer
Funktion, die nie sanitisiert. Zahlen, Datumswerte (`->format()`), `->count()`, `is*`/`has*` und
Übersetzungen gehen durch. Eine Stelle ohne Datensatztext trägt
`// @csv-sanitize-exempt: <Grund>` (in der Zeile oder darüber; in der Funktion gilt die Marke für
deren bloße Variablen, z. B. Zählerblätter). Keine Baseline.
Seit T3 zählen nur echte Sanitizer (`CsvSanitizer`, `csvCell`, `streamCsvExport`, `sanitizeFormulaInjection`,
nicht `sanitizeHtml`); Eigenschaftszugriffe (`$s->name`) und untypisierte Getter gelten als Text; handgebaute
Zeilen (`implode`/Verkettung/`sprintf`) werden bis zum Statement-Ende gelesen und über `fwrite`/`fputs`,
`file_put_contents`, `setContent`, `new Response(` und `echo` verfolgt (außer `fwrite` nur in Dateien, die
csv/xlsx/Trenner nennen).

**CI-Integration:** Gate 84 im `code-quality`-Job.

---

### quality/check_risk_band_literals.py (Gate 87)

**Zweck:** Die Stufengrenzen der Risikoskala (mittel/hoch/kritisch) gehören seit P8b der
Organisation (ISO 27001 6.1.2 a/b, *Admin > Risikokriterien*, Billigung durch die Leitung).
Gelesen werden sie über `App\Risk\RiskMatrixThresholds` (`classify()`, `criticalMin()`,
`highMin()`, `mediumMin()`), in Twig über `risk_level_band()` / `risk_bands()`, in Stimulus über
`band-*-min`-Values. Das Gate meldet in `src/`, `templates/` und `assets/controllers/` jeden
Vergleich eines Risikowerts mit 20, 12 oder 6. Andere Skalen mit denselben Zahlen stehen mit
Grund in `baselines/risk_band_literals.txt`; die Baseline darf nur schrumpfen.

Zweite Prüfung: Jeder Zugriff auf die Skala nennt die Organisation der Daten. In `src/` braucht
`RiskMatrixThresholds::classify()` zwei Argumente, `criticalMin()`/`highMin()`/`mediumMin()`/
`getBands()` eines (den Tenant); in `templates/` brauchen `risk_level_band()` und `risk_threshold()`
zwei, `risk_bands()` eines (`risk_level_band(score, risk.tenant)`, bei Matrix/Legende die
Organisation, deren Matrix gezeigt wird). Ohne Tenant greift die Organisation im Scope – in
Befehlen, Cron und Hintergrundjobs also 20/12/6, in Holding-Ansichten die Skala des Betrachters.
Wo die Organisation im Scope wirklich richtig ist, steht die Datei mit Grund in
`baselines/risk_scale_without_tenant.txt` (`pfad::grund`, derzeit leer); veraltete Einträge
lassen das Gate scheitern.

Seit T3 auch `> 19`/`> 11`/`> 5` und `<=`-Kanten, Alias-Variablen eines Risikowerts (PHP, Twig `{% set %}`,
JS `const`), `range(20,25)`/`range(12,19)`, Band-Arrays und Aliase/Group-Use von `RiskMatrixThresholds`.

**CI-Integration:** Gate 87 im `code-quality`-Job.

---

### quality/check_requirement_controls_scope.py (Gate 86)

**Zweck:** `ComplianceRequirement` ist globaler Katalog; `getMappedControls()` enthält
die Controls *aller* Organisationen. Im HTTP-Request blendet der Doctrine-Filter fremde
aus, in Jobs, Commands und Workern nicht. Die Controls hinter einer Anforderung kommen
deshalb aus `FulfillmentRuleEngine::controlsFor($requirement, $tenant)` (für die aktuelle
Organisation `ComplianceMappingService::controlsFor()`, Dokumente über
`RequirementEvidence`). Das Gate zählt `getMappedControls()` je Datei außerhalb von
`src/Entity/` und `src/Service/Compliance/Fulfillment/`; neue Dateien oder mehr Aufrufe
scheitern. Baseline `baselines/requirement_controls_scope.txt` darf nur schrumpfen
(`--write-baseline` nach dem Abbau).

**CI-Integration:** Gate 86 im `code-quality`-Job.

---

### quality/check_fulfillment_single_writer.py (Gate 85)

**Zweck:** Prozentwert und Status einer Anforderungs-Erfüllung schreibt nur
`App\Service\Compliance\Fulfillment\FulfillmentDerivationService` (`apply()`, `override()`,
`setManual()`). Das Gate prüft `src/` außerhalb von `src/Service/Compliance/Fulfillment/` und
`src/Entity/` und scheitert an `->setStatus(` auf einer Aufrufkette, die eine Erfüllung nennt
(`$fulfillment->…`, `getOrCreateFulfillment(…)->…`), und an `->setFulfillmentPercentage(` auf
einer solchen Kette oder in einer Datei, die `ComplianceRequirementFulfillment` nennt. Andere
Entities mit eigenem `setFulfillmentPercentage()` (PortfolioSnapshot) bleiben erlaubt. Eine
begründete Ausnahme trägt `// @fulfillment-writer-ok: <Grund>` in der Zeile. Keine Baseline.
Seit S6 auch: Schreiben per Property-Name (`setValue($x, 'fulfillmentPercentage', …)` über
PropertyAccessor/Reflection), rohes SQL auf `compliance_requirement_fulfillments`, und
`RestoreService` muss nach einer Wiederherstellung `refreshTenant()` aufrufen.

**CI-Integration:** Gate 85 im `code-quality`-Job.

---

### quality/check_raw_output.py (Gate 92, Twig-Teil)

**Zweck:** Datensatztext wird nie als HTML ausgegeben (gespeichertes XSS, XML-Injection in Word-Exporten).
Komponenten unter `templates/_components/` geben ihre Text-Slots escaped aus; HTML erreicht sie nur als
`Twig\Markup`: ein `{% set x %}…{% endset %}`-Capture (Datensatztext darin wird escaped), Macro-Ausgabe
oder `'key'|trans|fa_html` für eine geprüfte Übersetzung. JSON in Seiten und Skripten geht durch `|fa_json`.
Regeln:
- **R1:** jedes `|raw` / `|fa_html` ist `'literal.key'|trans(...)` mit escapten Parametern (`|e`, Zahlen,
  `path()`, `fa_date`; bei `a ? b : c`, `?:` und `??` jeder Zweig) oder steht mit Grund in
  `baselines/raw_output_allowlist.txt` (`pfad | ausdruck | grund`).
  `|raw` auf Macro-Ausgabe ist überflüssig, `json_encode|raw` → `|fa_json`, `{% autoescape false %}` verboten.
- **R2:** Komponenten-Argumente enthalten kein HTML-Stringliteral, keine HTML-Übersetzung ohne `|fa_html`,
  keine mit `=` gebaute HTML-Variable, keinen mit `|e` vorab escapten Wert (doppelt escaped), keine
  `|nl2br`/`form_widget()`/`block()`-Strings (Attribut-Maps `attrs`/`card_attrs` sind ausgenommen).
- **R5 (T3):** `template_from_string()` ist verboten (SSTI, Ausgabe als HTML).

Der PHP-Teil (`new PhpWord()` nur in `DocxDocumentFactory`, kein `TemplateProcessor`, Escaping nie aus,
`new Markup`/`Markup::class`/`is_safe` nur mit `// @markup-safe: <Grund>`) ist seit T3 die PHPStan-Regel
`MarkupOutputRule`. Veraltete Allowlist-Einträge lassen das Gate scheitern.
Selbsttest: `scripts/quality/tests/test_check_raw_output.py`; Render-Test `tests/Twig/OutputEscapingTest.php`.

**CI-Integration:** Gate 92 im `code-quality`-Job.

---

### quality/check_job_id_access.py (Gate 90)

**Zweck:** Jede Route, die einen Hintergrundjob über eine Job-ID aus der URL liest
(`JobStatusService::read()/exists()/delete()`), trägt `#[IsGranted(AsyncJobVoter::ACCESS, subject: '<id>')]`
an der Action oder Klasse — nur wer den Job gestartet hat (oder ein Super-Admin) sieht Status und
Ergebnisdatei. Routen mit eigener Zugangsregel (Quick-Fix) tragen `// @job-access-ok: <Grund>`.
Keine Baseline. Selbsttest: `scripts/quality/tests/test_s6_contract_gates.py`, `test_w9_gate_mutations.py`.
Seit T3 liest das Gate alle Methoden (auch private Helfer, Tabs), folgt der Route-Id über `$this->helper($x)` und
lokale Aliase in jeden `Job*`-Dienst, verlangt den Guard bei jedem Platzhalter `{jobId}`/`{job_id}`/`{jobUuid}`/
`{job}` und erkennt `var/jobs`-Pfade auch zusammengesetzt (`'/var/'.'jobs/'`, `Path::join`, `implode`).

**CI-Integration:** Gate 90 im `code-quality`-Job.

---

### PHPStan-Gate-Regeln (T3: Gates 11, 65, 72, 91, 92, 93)

**Zweck:** Die sicherheitsrelevanten Kernregeln lesen den Code, nicht Zeilen. Der Mutationsabgleich (Bericht
9-mutations, Welle 9) fand in drei Runden in Folge neue Schreibweisen, an denen die Regex-Gates vorbeisahen:
Namen im Kommentar zählten als Schutz, Aliase, Group-Use, Zeilenumbrüche, Konstanten und `sprintf` blieben
unsichtbar. Die Regeln liegen in `tests/PHPStan/Rule/Gate/`, sind in `phpstan.dist.neon` registriert und laufen im
CI-Schritt „Run PHPStan“ über `src/`:

| Gate | Regel | Rot bei |
|---|---|---|
| 65 | `CsrfStateChangingActionRule` | POST/PUT/PATCH/DELETE-Action ohne entscheidende Token-Prüfung (Attribut, `isCsrfTokenValid()` in einer Bedingung, die die Action beendet oder den Rumpf schützt, behandeltes Formular mit CSRF, `handleBulkDelete`, Helfer eine Ebene tief); GET-Action, die über einen ObjectManager `flush/persist/remove` aufruft; `'x_{id}'` als Token-Id. Marker `// @csrf-exempt: <Grund>`, `// @get-write-ok: <Grund>` |
| 72 | `TenantlessRowRule` | `new <mandantenbezogene Entity>` außerhalb von Entity/Fixtures/Restore ohne `setTenant(<nicht null>)` auf demselben Objekt (auch Fluent-Ketten); „eigene ODER globale Zeilen“-Abfrage (`OR … IS NULL`, `orWhere`, `orX`, `COALESCE`) auf einer Entity ohne `SharedTenantRowsInterface` |
| 91 | `MfaLoginStateRule` | `setToken(<nicht null>)` (auch als Callable/String), `_security_*`-Sitzungsschlüssel (auch zusammengesetzt) oder `$_SESSION` ohne `begin()` auf einem `MfaSessionState` in derselben Methode; `_security.mfa_*` außerhalb von `MfaSessionState`; `/setup` in `ALLOWED_PREFIXES`. Marker `// @mfa-ok: <Grund>` an der Methode |
| 92 | `MarkupOutputRule` | `new PhpWord` außerhalb von `DocxDocumentFactory`, `TemplateProcessor`, Escaping aus; `new Markup`, `Markup::class`, `is_safe`/`isSafe` ohne `// @markup-safe: <Grund>` |
| 93 | `SsoClaimPolicyRule` | Authenticator-Klasse (per Typ gefunden) ohne Eintrag in `CLAIM_CHECKS`/`NON_SSO`; fehlender `SsoClaimPolicy`-Aufruf bzw. Flow-Aufruf; `processResponse()` ohne Request-Id; Host-Header im SSO-Code; Claims per `array_merge`/`array_replace`/`+`/Spread in `src/Service/Sso`; `$allowEmailLinking` standardmäßig an |
| 11 | `ModuleGateRule` | regulatorisches Formularfeld ohne passendes `isModuleActive()`-If (auch über private Helfer); Framework-Route ohne `#[RequiresModule]` oder entscheidendes `checkModuleActive()`. Marker `// @no-module-gate-required: <Grund>` |

Marker gelten nur als Kommentar mit Grund dahinter, Namen in Kommentaren oder Docblocks nie als Schutz.
Bekannte Ausnahmen stehen in `phpstan-gates-baseline.neon` (je Eintrag `reportUnmatched: true`: ein behobener
Eintrag muss raus). Jede Regel hat eine Fixture unter `tests/PHPStan/Rule/Gate/Fixtures/`, deren Zeilen mit
`// expect: <identifier>` ihre Mutationstabelle sind (`GateRuleTestCase`); php-cs-fixer lässt die Fixtures aus,
damit Schreibweisen wie `TRUE` erhalten bleiben.

```bash
vendor/bin/phpstan analyse --configuration phpstan.dist.neon      # wie CI
vendor/bin/phpunit tests/PHPStan/Rule/Gate                          # Mutationstabellen der Regeln
```

**CI-Integration:** Schritt „Run PHPStan (incl. gate rules 11, 65, 72, 91, 92, 93)“ im `code-quality`-Job.

---

### quality/check_sso_claim_policy.py (Gate 93, Konfigurationsteil)

**Zweck:** E-Mail-Verknüpfung der env-Azure-Anmeldung bleibt standardmäßig aus: rot bei
`app.azure_allow_email_linking_default` oder `AZURE_ALLOW_EMAIL_LINKING` mit einem wahren Wert (true/1/yes/on, jede
Schreibweise, mit oder ohne Anführungszeichen) in `config/services.yaml`, `.env`, `.env.prod.example`. Den Code-Teil
prüft seit T3 die PHPStan-Regel `SsoClaimPolicyRule`; die Authenticatoren aller Firewalls aller Umgebungen prüft
`tests/Security/Sso/SsoClaimContractTest` auf der aufgelösten Konfiguration (`tests/Support/ResolvedSecurityConfig`).
Selbsttest: `scripts/quality/tests/test_check_sso_claim_policy.py`.

**CI-Integration:** Gate 93 im `code-quality`-Job.

---

### quality/check_stored_file_paths.py (Gate 94)

**Zweck:** Ein gespeicherter Dateipfad (Logo, Avatar, Audit-Freeze-PDF, Dokumentpfad) wird nie von Hand an
ein Verzeichnis gehängt (S19, 2026-11). Rot bei einer Verkettung von `getLogoPath()`, `getProfilePicture()`,
`getPdfPath()`, `getFilePath()`, `getStoredFilename()` oder gleichnamigen Variablen mit einem String und bei
`'/public/' . $x` außer `$this->uploadsDirectory`, außerhalb von `StoredFilePath`/`ProtectedUploads`.
Ausnahme mit `// @stored-path-ok: <Grund>` auf der Zeile. Verhalten (jede pfadartige Doctrine-Spalte
registriert, Traversal je Spalte abgewiesen, Restore übernimmt solche Werte nicht):
`tests/Security/StoredFilePathContractTest`.

**CI-Integration:** Gate 94 im `code-quality`-Job.

---

### quality/check_tenant_pin_restore.py (Gate 97)

**Zweck:** Eine in `TenantContext` gepinnte Organisation wird zurückgesetzt (S23, 2026-11). Der
Messenger-Worker führt geplante Befehle und Jobs in einem Container aus; ein Pin ohne Restore scopte den
nächsten Befehl (Instanz-Backup mit einer Organisation, Erinnerungen der anderen fehlten). Rot bei jedem
`->setCurrentTenant(`/`->setCurrentTenantById(`/`->setNoOrganisation(` in `src/`, der weder in einem
`try` derselben Funktion steht, dessen `finally` den Kontext zurücksetzt, noch selbst dieser Restore ist,
noch `// @tenant-pin-ok: <Grund>` trägt. Weg der Wahl: `TenantContext::runAs()` /
`runWithoutOrganisation()`. Keine Baseline. Selbsttest `scripts/quality/tests/test_check_tenant_pin_restore.py`;
das Verhalten pinnt `tests/Scheduler/ScheduledCommandsTenantIsolationTest`.

**CI-Integration:** Gate 97 im `code-quality`-Job.

---

### quality/check_api_module_fields.py (Gate 89)

**Zweck:** Felder eines optionalen Moduls an einem Datensatz eines anderen Moduls (DORA am
Vorfall, FAIR am Risiko, KI-Agent am Asset) sind über REST und den Assistenten (MCP) nur
schreibbar, solange das Modul aktiv ist — wie im Formular, das sie sonst nicht hinzufügt.
`App\Api\ModuleFieldContextBuilder` ignoriert sie im Request-Body, wenn die Entity-Property
`#[ModuleField('<modul>')]` trägt. Das Gate prüft: jedes Feld, das ein FormType nur in einem
`if ($this->isModuleActive('<m>'))`-Block (auch über `$this->addXyzFields($builder)`) hinzufügt
und das in einer `:write`-Gruppe der Entity steht, trägt `#[ModuleField('<m>')]`; jedes
`#[ModuleField]` nennt ein Modul aus `config/modules.yaml`. Felder ganz ohne Formular (DORA- und
FAIR-Spalten am Risiko) sind von Hand markiert. Keine Baseline.
Seit S6 zählen Traits mit: Properties aus `src/Entity/Trait/` und Feld-Helfer aus `src/Form/Trait/`.

**CI-Integration:** Gate 89 im `code-quality`-Job.

---

### quality/check_kpi_catalog.py (Gate 74)

**Zweck:** Management-Kennzahlen kommen nur aus dem KPI-Katalog
(`App\Service\Kpi\KpiCatalog`). Er wendet die KPI-Schwellwerte der Organisation
(Admin → KPI-Schwellwerte, im Konzern vererbt) auf die Ampel an und ergänzt die
Euro-Kennzahlen aus den Quant-Feldern der Risiken. Ein direkter Aufruf von
`DashboardStatisticsService::getManagementKPIs()` zeigt wieder die fest
eingebauten Standard-Ampeln, die Einstellung wirkt dort also nicht. Dashboards
wählen einzelne Kennzahlen mit `kpi('<key>')` bzw. `_components/_kpi_tile.html.twig`.

**CI-Integration:** Gate 74 im `code-quality`-Job.

---

### quality/check_list_skeleton.py (Gate 73)

**Zweck:** Jede Listenseite (`templates/**/index.html.twig`) läuft über das eine
Listen-Gerüst `_components/_fa_list_page.html.twig`: Filterleiste (Suche `q` +
einklappbare Filter, alles GET), Kurzinfo-Chips, Tabelle, Pager, leerer Zustand.
Listen auf dem Gerüst dürfen keine KPI-Kacheln (`_fa_feature_card`) tragen.

**Baseline:** `scripts/quality/baselines/list_skeleton.txt` ist seit der Umstellung
aller Listen leer und darf leer bleiben: jede neue Liste läuft über das Gerüst.
Neu schreiben: `--write-baseline scripts/quality/baselines/list_skeleton.txt`.
`index.html.twig`-Dateien, die keine Liste sind (Hubs, Dashboards, Assistenten,
Einstellungsformulare, Statusseiten), stehen mit Begründung in
`scripts/quality/baselines/list_skeleton_not_lists.txt` und werden nicht geprüft.

**CI-Integration:** Gate 73 im `code-quality`-Job.

---

### quality/check_copy_slop.py (Gate 71)

**Zweck:** UI-Texte ohne Füllstoff (Copy-Regeln in CLAUDE.md, Abschnitt
„UI copy“). Prüft jeden Wert in `translations/*.{de,en}.yaml` auf
„erfolgreich“ / „successful(ly)“, Ausrufezeichen und Füllwörter („nahtlos“,
„seamlessly“ …) sowie jeden `{% block title %}` auf einen hart kodierten
App-Namen (der Suffix steht einmal in `base.html.twig`).

**Baseline:** `scripts/quality/baselines/copy_slop.txt`, Einträge
`<datei>::<key>  # Grund` bzw. `<template>  # Grund`. Schlüsselbasiert, also
unabhängig von Zeilenverschiebungen.

**CI-Integration:** Gate 71 im `code-quality`-Job.

---

### quality/check_entity_manager_reset.py (Gate 68)

**Zweck:** Ein geschlossener EntityManager wird ausschließlich über
`App\Doctrine\EntityManagerRecovery::recover()` / `recoverIfClosed()` wieder
geöffnet. Ein nacktes `ManagerRegistry::resetManager()` verliert den
`tenant_id`-Parameter des Tenant-Filters (alle folgenden Queries lesen über alle
Organisationen) und den verwalteten Tenant im `TenantContext` (folgende
Audit-Zeilen scheitern). Der Helper sichert Filter- und Kontext-Zustand vor dem
Reset und stellt ihn danach wieder her — auch "Filter deaktiviert" bei
Operator-Pfaden (Restore, Schema-Wartung, Sample-Purge).

**Verwendung:** `python3 scripts/quality/check_entity_manager_reset.py`
(einzige erlaubte Stelle ist der Helper selbst). Erkannt werden
`resetManager(` — auch über Zeilen umbrochen — und `->reset(` auf der
Doctrine-Registry (Variable/Property/Getter mit `registry`/`doctrine` im Namen
oder `->get('doctrine')`) sowie auf jedem Namen, der in derselben Datei als
Registry typisiert ist (`ManagerRegistry`, `\Doctrine\Persistence\ManagerRegistry`,
Doctrine-Bundle-`Registry`, auch nullable und Constructor-Promotion) oder aus
`->getDoctrine()` / `->get('doctrine')` zugewiesen wird — `$mr->reset(` und
`$this->mr?->reset(` werden also unabhängig vom Namen gefunden, `->reset(` auf
anderen Objekten nicht. String-Literale, Kommentare sowie Heredoc-/Nowdoc-Inhalte
werden vorher ausgeblendet. Grenzen: Die Typisierung wird je Datei aufgelöst;
eine über untypisierte Variablen/Array-Zugriffe weitergereichte oder per
`use … as` umbenannte Registry wird nicht erkannt.

**CI-Integration:** Gate 68 im `code-quality`-Job, keine Baseline.
Selbsttest: `scripts/quality/tests/test_check_entity_manager_reset.py`.

---

### quality/check_yaml_duplicates.py

**Zweck:** Doppelte Schlüssel in derselben Mapping-Ebene aller
`translations/*.yaml`-Dateien. PyYAML behält still den letzten Wert (ein ganzer
Elternabschnitt kann verschwinden), Symfonys YAML-Parser lehnt die Datei ab —
die Übersetzungs-Domain lädt dann zur Laufzeit nicht.

**Verwendung:**
```bash
python3 scripts/quality/check_yaml_duplicates.py              # alle Übersetzungsdateien
python3 scripts/quality/check_yaml_duplicates.py datei.yaml   # einzelne Dateien
```

**CI-Integration:** Gate 63 im `code-quality`-Job (blockierend).

---

### quality/check_twig_macro_scope.py

**Zweck:** Erkennt Twig-Macro-Import-Scope-Bugs, die `lint:twig` nicht erfasst.

**Das Problem:**
```twig
{% extends 'base.html.twig' %}
{% import '_components/_fa_progress.html.twig' as _fa_progress %}  {# FILE-SCOPE — BUG! #}
{% block body %}
    {{ _fa_progress.render(...) }}  {# BROKEN: "Variable '_fa_progress' does not exist" #}
{% endblock %}
```
Twig parst dieses Template ohne Fehler. Der Fehler tritt erst beim Render auf.
Korrekt: Import muss *innerhalb* des `{% block %}` stehen, in dem er genutzt wird.

**Verwendung:**
```bash
# Vom Repo-Root:
python3 scripts/quality/check_twig_macro_scope.py

# Ausgabe in Datei:
python3 scripts/quality/check_twig_macro_scope.py 2>&1 | tee macro_scope_report.txt
```

**Exit-Codes:**
- `0` = Alle Templates fehlerfrei
- `1` = Macro-Scope-Issues gefunden (CI schlägt fehl)
- `2` = `templates/`-Verzeichnis nicht gefunden

**Output-Beispiel:**
```
FAIL templates/foo/bar.html.twig:5: macro '_fa_progress' imported at file-scope but used inside block at line 42
FAIL templates/baz.html.twig:3: macro '_fa_table' imported at file-scope but used inside block at line 110

2 macro-scope issue(s) in 2 template(s).
Fix: move the {% import %} statement inside the {% block %} where it is used.
```

**CI-Integration:** Läuft als `Twig Macro-Scope Check` im `code-quality`-Job in `.github/workflows/ci.yml`, direkt vor dem DQL-Field-Mismatch-Check.

**Regression-Schutz für:** Commit `075e36a4` (Bulk-Fix von 48 Templates).

---

### quality/check_flash_domain.py (Audit-S5 / P-5)

**Zweck:** Stoppt `$this->translator->trans('key')`-Calls ohne explizite
Domain in Controllern. Solche Calls fallen still auf die `messages`-Domain
zurueck und zeigen Roh-Keys in der UI an. Bevorzugt wird
`LocalizedFlashTrait::flashSuccess('key.with.dots')`.

**Verwendung:**
```bash
python3 scripts/quality/check_flash_domain.py --baseline scripts/quality/baselines/flash_domain.txt --quiet
```

**Whitelist-Annotation:** `// @flash-domain-fallback-ok: <reason>`.

**CI-Integration:** Gate 12 im `code-quality`-Job (BLOCKING via baseline).

---

### quality/check_freetext_legacy.py (Audit-S5 / P-15)

**Zweck:** Heuristik gegen Freitext-Felder in FormTypes, wo eine
strukturelle Entity-Referenz existieren sollte (Auditor, Owner, Country,
Department, Participants, …).

**Verwendung:**
```bash
# warn-only (default)
python3 scripts/quality/check_freetext_legacy.py

# strict (CI-blocking)
python3 scripts/quality/check_freetext_legacy.py --baseline scripts/quality/baselines/freetext_legacy.txt --strict --quiet
```

**Whitelist-Annotation:** `// @legacy-freetext: <reason>`.

**CI-Integration:** Gate 13 im `code-quality`-Job (`--strict --baseline`).

---

### quality/check_god_class_size.py (Gate 25 — God-Class-Ratchet)

**Zweck:** Jede Datei unter `src/` gegen einen LOC-Schwellwert ihrer Art
(Controller 600, Service 1500 LOC / 15 Konstruktor-Deps, Entity 1000,
Repository 600, Command 800, Rest 800, explizit gelistete Daten-Kataloge 2000 —
Liste `DATA_CATALOGUE_FILES` im Skript). Dateien darüber stehen in
`scripts/quality/baselines/god_class_size.txt`.

**Die Baseline ist eine Einbahnstraße:**
- neue Datei über Schwellwert → FAIL
- gelistete Datei wächst über ihren Eintrag (LOC, bei Services auch Deps) → FAIL
- `--strict-shrink`: Datei ist geschrumpft, Eintrag aber nicht gesenkt → FAIL
  (der Gewinn wird festgeschrieben, statt später wieder verbraucht zu werden)
- `--ratchet-ref <ref>`: Die Baseline selbst darf gegenüber `<ref>` nicht
  wachsen — angehobene oder neue Einträge → FAIL. "Baseline hochsetzen"
  ist damit kein Weg mehr am Gate vorbei. Eine umbenannte Datei darf den Eintrag
  einer im selben Change verschwundenen Datei übernehmen, wenn sie nicht größer ist.

**Ratchet-Basis in CI** (Gate 25 in `.github/workflows/ci.yml`):
- Pull Request → die PR-Basis (`github.base_ref`).
- Push → der vorherige Stand des Branches (`github.event.before`). Ein Push auf
  `main` gegen `origin/main` zu vergleichen hieße, den Commit mit sich selbst zu
  vergleichen — ein direkt gepushter Baseline-Bump käme durch.
- Force-Push (`github.event.forced`) → `main`: der alte Stand ist verworfen
  (GitHub liefert ihn oft trotzdem noch aus — er wäre eine falsche Basis).
- Neuer Branch / Tag (`before` = lauter Nullen) oder ein nicht erreichbares
  `before` → Fallback auf `main`.

**Umbenennen/Kopieren (`git diff --find-renames --find-copies`):** Welche Datei
einen Basis-Eintrag übernehmen darf, entscheidet allein Git's Rename-/Copy-Erkennung
zwischen Ratchet-Basis und `HEAD` (zwei Punkte, Baum gegen Baum — funktioniert mit
dem flachen `--depth=1`-Fetch der CI).
- `--find-renames`: `R<score>`-Paare — die neue Datei übernimmt den Eintrag der
  alten, wenn deren Eintrag wegfällt und die neue nicht größer ist.
- `--find-copies`: zusätzlich `C<score>`-Paare. Eine Kopie darf einen Eintrag nur
  übernehmen, wenn die Quelldatei ihren Eintrag im selben Change verliert (jeder
  Basis-Eintrag kann höchstens einmal übernommen werden). Kopie + Original
  bleibt bestehen → FAIL (`ADDED`). Ohne `--find-copies-harder` betrachtet Git
  nur im selben Change geänderte Dateien als Kopierquelle; wird eine
  unveränderte Datei kopiert, gibt es also gar kein Paar und die Kopie scheitert
  als `ADDED` (fail closed).
- Löschen + nicht verwandte neue Datei ist kein Rename → kein Erbe von Headroom.

**Wenn das Gate fehlschlägt:**
```bash
# NEW / GREW: Code in kohäsive Services/Traits auslagern, bis die Datei wieder
# innerhalb ihres Eintrags bzw. Schwellwerts liegt.

# stale (Datei geschrumpft): Baseline senken
python3 scripts/quality/check_god_class_size.py --write-baseline scripts/quality/baselines/god_class_size.txt

# lokal wie CI prüfen
python3 scripts/quality/check_god_class_size.py --baseline scripts/quality/baselines/god_class_size.txt \
    --ratchet-ref origin/main --strict-shrink
```

Schwellwerte oder die Daten-Katalog-Liste ändern ist eine bewusste,
reviewpflichtige Änderung am Skript (ADR-0012), kein Baseline-Bump.

**CI-Integration:** Gate 25 im `code-quality`-Job.

---

### quality/check_translation_issues.py

**Zweck:** Umfassender Translation Quality Checker für Twig-Templates

**Verwendung:**
```bash
cd scripts/quality
python3 check_translation_issues.py

# Report in Datei speichern
python3 check_translation_issues.py > translation_report.txt
```

**Findet:**
- ✅ **Hardcoded Text** - Text der übersetzt werden sollte
- ✅ **Untranslated Attributes** - title, aria-label, placeholder ohne Übersetzung
- ✅ **Missing Trans Params** - `|trans` ohne `({}, 'domain')`
- ✅ **Missing Domain** - Übersetzungen ohne explizite Domain
- ✅ **Invalid Domain** - Verwendung nicht existierender Domänen

**Output-Beispiel:**
```
📄 admin/dashboard.html.twig
   Line 42: Hardcoded text: 'Dashboard'
   💡 Suggestion: Use translation: {{ 'key'|trans({}, 'domain') }}

```

**Features:**
- Erkennt Hardcoded English text automatisch
- Validiert gegen die vorhandenen Translation Domains (`translations/*.yaml`)
- Prüft Accessibility-Attribute (aria-label, etc.)
- Gibt konkrete Verbesserungsvorschläge
- Gruppiert Issues nach Typ und Datei

**CI-Integration:** Gate 44 im `code-quality`-Job: jeder Fund (Datei:Zeile) muss in `baselines/translation_issues.txt` stehen.

**Best Practices Guide:** Siehe Output für Beispiele und `CLAUDE.md` für Domain-Liste

---

## 🛠️ Tools

### tools/license-report.sh

**Zweck:** Lizenz-Report-Generierung für alle Dependencies

**Verwendung:**
```bash
cd scripts/tools
chmod +x license-report.sh
./license-report.sh
```

**Generiert:**
- ✅ Detaillierter Lizenz-Report (docs/reports/license-report.md)
- ✅ Compliance-Status aller Packages
- ✅ Lizenzübersicht (MIT, BSD, Apache, LGPL)

**Dokumentation:** [docs/setup/SETUP_TOOLS.md#4-license-reportsh](../docs/setup/SETUP_TOOLS.md#4-license-reportsh)

---

### tools/create_pr.sh

**Zweck:** GitHub Pull Request Erstellung

**Verwendung:**
```bash
cd scripts/tools
chmod +x create_pr.sh
./create_pr.sh
```

**Features:**
- ✅ Automatische PR-Erstellung via `gh` CLI
- ✅ Branch-Detection
- ✅ Commit-Message als PR-Titel

**Hinweis:** Erfordert GitHub CLI (`gh`)

---

### tools/create_pr_url.sh

**Zweck:** GitHub PR URL-Generierung

**Verwendung:**
```bash
cd scripts/tools
chmod +x create_pr_url.sh
./create_pr_url.sh
```

**Features:**
- ✅ Generiert PR-URL für aktuellen Branch
- ✅ Branch-Detection
- ✅ Direkt zum Browser kopierbar

---

## ⚠️ Deprecation Warnings

### Root-Wrapper (Rückwärtskompatibilität)

Die folgenden Skripte im Root-Verzeichnis sind **deprecated** und leiten an die neuen Pfade weiter:

- ❌ `/validate-setup.sh` → ✅ `/scripts/setup/validate-setup.sh`
- ❌ `/create-database.sh` → ✅ `/scripts/setup/create-database.sh`
- ❌ `/reset-database.sh` → ✅ `/scripts/setup/reset-database.sh`

**Empfehlung:** Verwenden Sie die neuen Pfade in `scripts/setup/`

Die Wrapper zeigen eine Deprecation-Warnung an und leiten automatisch weiter.

---

## 📋 Schnellzugriff

### Für neue Benutzer

```bash
# 1. Setup validieren
cd scripts/setup && chmod +x validate-setup.sh && ./validate-setup.sh

# 2. Datenbank erstellen
chmod +x create-database.sh && ./create-database.sh
```

### Für Entwickler

```bash
# Übersetzungen prüfen
cd scripts/quality && python3 check_translations.py

# Lizenz-Report generieren
cd scripts/tools && chmod +x license-report.sh && ./license-report.sh
```

### Für Deployment

```bash
# Produktions-Check
cd scripts/deployment && chmod +x deployment-check.sh && ./deployment-check.sh
```

---

## 🔐 Sicherheitshinweise

- **Nie** Skripte von unbekannten Quellen ausführen
- **Immer** Skripte vor Ausführung überprüfen
- **Vorsicht** bei `reset-database.sh` - löscht alle Daten!
- **Backup** vor Produktions-Deployments erstellen

---

## 📞 Weitere Informationen

- **Haupt-README:** [../README.md](../README.md)
- **Setup-Dokumentation:** [../docs/setup/](../docs/setup/)
- **Deployment-Dokumentation:** [../docs/deployment/](../docs/deployment/)
- **Issue Tracker:** [GitHub Issues](https://github.com/moag1000/Little-ISMS-Helper/issues)

