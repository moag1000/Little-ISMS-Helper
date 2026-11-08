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

---

## 🔍 Qualitätssicherung

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

**Dokumentation:** [docs/reports/TRANSLATION_CONSISTENCY_REPORT.md](../docs/reports/TRANSLATION_CONSISTENCY_REPORT.md)

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

**Dokumentation:** [docs/reports/TRANSLATION_VERIFICATION_REPORT.md](../docs/reports/TRANSLATION_VERIFICATION_REPORT.md)

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
(`status-map`; Button-Varianten-Maps mit `btn`/`button` im Namen sind ausgenommen); Bootstrap-Tooltips nur auf fokussierbaren
Elementen (`tooltip-unreachable`).

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
`PENDING` im Skript nimmt Rollen auf, die eine andere Änderung gerade einführt. Keine Baseline.

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

### quality/check_job_id_access.py (Gate 90)

**Zweck:** Jede Route, die einen Hintergrundjob über eine Job-ID aus der URL liest
(`JobStatusService::read()/exists()/delete()`), trägt `#[IsGranted(AsyncJobVoter::ACCESS, subject: '<id>')]`
an der Action oder Klasse — nur wer den Job gestartet hat (oder ein Super-Admin) sieht Status und
Ergebnisdatei. Routen mit eigener Zugangsregel (Quick-Fix) tragen `// @job-access-ok: <Grund>`.
Keine Baseline. Selbsttest: `scripts/quality/tests/test_s6_contract_gates.py`.

**CI-Integration:** Gate 90 im `code-quality`-Job.

---

### quality/check_mfa_login_state.py (Gate 91)

**Zweck:** Der zweite Faktor hat einen Zustand und keine Seitentür (S8, 2026-11). Rot bei
(1) einem `_security.mfa_*`-Sitzungsschlüssel außerhalb von `src/Security/Mfa/MfaSessionState.php`,
(2) `->setToken($x)` (nicht `null`) in einer Datei ohne `MfaSessionState` — eine selbstgebaute
Anmeldung ohne zweiten Faktor; Ausnahme mit `// @mfa-ok: <Grund>`, (3) `'/setup'` in den
`ALLOWED_PREFIXES` der MFA-Enforcer. Das Verhalten pinnen `tests/Security/Mfa/`. Keine Baseline.
Selbsttest: `scripts/quality/tests/test_s6_contract_gates.py`.

**CI-Integration:** Gate 91 im `code-quality`-Job.

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

### quality/check_tenant_null_rows.py (Gate 72)

**Zweck:** Datensätze ohne Mandant, die der `TenantFilter` versteckt. Zwei Regeln:
`new-no-tenant` meldet Controller/Services, die eine mandantenbezogene Entity mit
`new X()` anlegen, ohne in derselben Methode `setTenant()` aufzurufen (so gingen
Workflow-Instanzen und Risikoappetit für die eigene Organisation verloren).
`shared-query` meldet Abfragen „eigene ODER globale Zeilen“
(`x.tenant = :t OR x.tenant IS NULL`) auf Entities ohne
`SharedTenantRowsInterface`: Unter dem Filter trifft der globale Zweig nie.

**Baseline:** `scripts/quality/baselines/tenant_null_rows.txt`, Einträge
`<datei>::<regel>::<detail>  # Grund` (z. B. Formularobjekt, dessen Service den
Mandanten setzt). Veraltete Einträge werden gemeldet, schlagen aber nicht fehl.

**CI-Integration:** Gate 72 im `code-quality`-Job.

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

### quality/check_module_gating.py (Audit-S5 / P-6)

**Zweck:** Erzwingt Module-Gating-Disziplin für regulatorische FormType-Felder.
Verhindert, dass DORA-/LkSG-/MaRisk-/NIS2-/TISAX-/GDPR-Felder ohne
`isModuleActive()`-Gate / `addModuleGatedField()` / safe-helper ins
Form-Layout rutschen.

**Verwendung:**
```bash
# Repo-weit, gegen Baseline
python3 scripts/quality/check_module_gating.py --baseline scripts/quality/baselines/module_gating.txt --quiet

# Einzelne Datei
python3 scripts/quality/check_module_gating.py --paths src/Form/SupplierType.php

# Baseline neu schreiben (Snapshot-Modus)
python3 scripts/quality/check_module_gating.py --write-baseline scripts/quality/baselines/module_gating.txt
```

**Whitelist-Annotation:** `// @no-module-gate-required: <reason>` direkt
oberhalb des `->add(...)`-Calls.

**CI-Integration:** Gate 11 im `code-quality`-Job (BLOCKING via baseline).

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
- ✅ **Hardcoded Text** - Text der übersetzt werden sollte (156 Issues)
- ✅ **Untranslated Attributes** - title, aria-label, placeholder ohne Übersetzung (41 Issues)
- ✅ **Missing Trans Params** - `|trans` ohne `({}, 'domain')` (4.340 Issues)
- ✅ **Missing Domain** - Übersetzungen ohne explizite Domain (78 Issues)
- ✅ **Invalid Domain** - Verwendung nicht existierender Domänen (4 Issues)

**Output-Beispiel:**
```
📄 admin/dashboard.html.twig
   Line 42: Hardcoded text: 'Dashboard'
   💡 Suggestion: Use translation: {{ 'key'|trans({}, 'domain') }}

SUMMARY BY TYPE
  HARDCODED_TEXT..........................  156 issue(s)
  MISSING_TRANS_PARAMS.................... 4340 issue(s)
  NO_DOMAIN...............................   78 issue(s)
  UNTRANSLATED_ATTRIBUTE..................   41 issue(s)
  INVALID_DOMAIN..........................    4 issue(s)
  TOTAL................................... 4619 issue(s)
```

**Features:**
- Erkennt Hardcoded English text automatisch
- Validiert gegen 49 bekannte Translation Domains
- Prüft Accessibility-Attribute (aria-label, etc.)
- Gibt konkrete Verbesserungsvorschläge
- Gruppiert Issues nach Typ und Datei

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
- ✅ Compliance-Status (163 Packages)
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

---

**Stand:** 2025-11-12
**Version:** 1.0
**Autor:** Little ISMS Helper Team
