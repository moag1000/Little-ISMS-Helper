# Persona-Dashboards — Benutzerhandbuch

Little ISMS Helper stellt rollenspezifische Dashboards bereit, die nur die
fuer die jeweilige Rolle relevanten KPIs, Aktionen und Navigations-Shortcuts
anzeigen. Die Zuweisung erfolgt automatisch anhand der Symfony-Rollen des
angemeldeten Nutzers.

---

## Wer landet wo (eine Zuordnung fuer alles)

Welche Persona ein Nutzer ist, entscheidet an genau einer Stelle
`App\Security\Persona\PersonaResolver` (Tabelle in `App\Security\Persona\Persona`).
Daraus folgen Landeseite nach dem Login, die Tour, welche Persona-Dashboards
im Menue "Mein Cockpit" erscheinen und welche Berichte jemand selbst laden darf.

| Persona | Rolle (Benutzerverwaltung) | Landet auf | Tour | Berichte selbst laden |
|---|---|---|---|---|
| CISO | `ROLE_CISO` | CISO-Dashboard | CISO | alle |
| ISB | `ROLE_ISB` | ISB-Dashboard | ISB | alle |
| Risk-Manager | `ROLE_RISK_MANAGER` | Risk-Manager-Dashboard | Risikomanagement | alle |
| Compliance-Manager | `ROLE_COMPLIANCE_MANAGER` | CM-Dashboard | CM | alle |
| DSB | `ROLE_DPO` | DSB-Dashboard (ohne Modul Datenschutz: Standard-Dashboard) | Datenschutz | alle |
| BCM-Beauftragter | `ROLE_BCM_OFFICER`, `ROLE_GROUP_BCM_OFFICER` | BCM-Dashboard (ohne Modul BCM: Standard-Dashboard) | BCM | alle |
| Leitung | `ROLE_TOP_MGMT` | Leitungs-Dashboard | Leitung | One-Pager, Managementbewertung, Quartalstrend, Zertifizierungsreife |
| Administrator ohne Persona-Rolle | `ROLE_ADMIN` | Standard-Dashboard | CISO | alle |
| Konzern-ISB | `ROLE_GROUP_CISO` | Standard-Dashboard | Leitung | Audit- und Leitungsberichte |
| Konzern-Auditor | `ROLE_KONZERN_AUDITOR` | Auditor-Dashboard | Auditor | Audit-Paket, Zertifizierungsbuendel, Zertifizierungsreife, Nachweispaket, Managementbewertung |
| Manager | `ROLE_MANAGER` | Standard-Dashboard | ISB | alle |
| Auditor | `ROLE_AUDITOR` | My Day | Auditor | wie Konzern-Auditor |
| Fachbereichsleitung / Risk-Owner | `ROLE_FUNCTION_OWNER` | My Day | Risk-Owner | keine |
| Nutzer, der ein Risiko besitzt | `ROLE_USER` (Eigentümer eines Risikos, als Login oder verknüpfte Person) | My Day | Risk-Owner | keine |
| Nutzer | `ROLE_USER` | My Day | Einstieg (mit Stopp „nächstes Control“) | keine |

Regeln:

- **Zugewiesene Rolle vor Rollenhierarchie.** Wer `ROLE_ADMIN` und `ROLE_DPO`
  hat, landet als DSB. Ein Administrator ohne Persona-Rolle landet nicht auf dem
  CISO-Dashboard, darf es aber oeffnen (die Hierarchie gibt Admins jede
  operative Persona). **Ausnahme Leitung:** `ROLE_ADMIN` erbt `ROLE_TOP_MGMT`
  nicht (seit 2026-11). Billigungen der Leitung (Leitungsentscheidungen,
  Risikoakzeptanz über der Schwelle, Abschluss der Managementbewertung,
  Workflow-Schritte mit `approver_role: ROLE_TOP_MGMT`) gehen nur mit der
  zugewiesenen Rolle Leitung — ISO 27001 5.1 verlangt die oberste Leitung.
  Solange in einer Organisation niemand die Rolle hat, sehen Admins auf dem
  Dashboard und in der Benutzerverwaltung einen Hinweis mit Link zum Zuweisen.
- **Kontext und Ziele pflegen ISB und CISO** (ISO 27001 4.1–4.3, 6.2), nicht nur
  der Admin: ein Voter-Attribut `ISMS_STEERING_EDIT` (`IsmsSteeringVoter`).
- **Rechte folgen der Rollenhierarchie.** Jede Persona-Rolle erbt `ROLE_MANAGER`;
  ein ISB sieht in My Day deshalb Pannen, Vorfaelle und Freigaben wie ein Manager.
- **Leitung liest, aendert nichts operativ** (`ROLE_TOP_MGMT` erbt `ROLE_AUDITOR`).
  Freigaben, die der Leitung zustehen, pruefen `ROLE_TOP_MGMT` ausdruecklich.
- **Leitungs-Dashboard** sehen Leitung, CISO, Compliance-Manager und Konzern-ISB.
- **Auditoren laden Audit-Paket und Zertifizierungsbuendel selbst**, nur lesend
  (`ReportAccessVoter`). Das Audit-Paket je Framework steht auf der Seite
  Zertifizierungsbuendel (Menue Berichte, Suche "Audit-Paket").
- Die Persona-Vorschau des Compliance-Managers ("Persona-Vorschau" im Menue)
  oeffnet das jeweilige Dashboard fuer die Dauer der Sitzung.

Rollen vergibt der Administrator unter Administration → Benutzer → Bearbeiten →
Systemrollen.

**Was jedes Persona-Dashboard zeigt (gleiche Bausteine):**

- **„Was bei dir ansteht“** — die offenen Pflichten der Person aus allen
  Fristenquellen (`ObligationInbox`, Twig `my_obligations()`), dieselbe Liste wie
  Mein Tag; ersetzt die frühere eigene Freigabe-Abfrage (ISB, BCM, Auditor).
- **Downloads** — die Berichte, die die Persona laden darf
  (`dashboards/_report_downloads.html.twig`, geprüft über `ReportAccessVoter`).
- **Kennzahlen** nur aus dem `KpiCatalog` (BCM: BIA-Abdeckung, kritische
  Prozesse, aktive Pläne, Übungen dieses Jahres).

---

## CISO-Dashboard

**Route:** `/{locale}/dashboard/ciso`

Zielgruppe: Sicherheitsverantwortliche mit Management-Aufgaben.

Enthaltene Widgets:

| Widget | Beschreibung |
|---|---|
| ISMS Health Score | Gesamtreife-Score (0-100), Trend 12 Monate |
| Risk-Heatmap | 5x5-Matrix mit Cluster-Faerbung nach Risikowert |
| Top-10-Risiken | Offene High/Critical-Risiken nach Risikowert sortiert |
| SoA-Coverage | Prozentsatz umgesetzter Annex-A-Controls |
| Offene Incidents (high/critical) | Aktive Vorfaelle mit MTTR-Ampel |
| Workflow-Eskalationen | Workflows mit gerissener Frist (CISO-relevant) |
| Board-Link | Direktlink auf Board-One-Pager (PDF-Vorschau in Sidebar) |

---

## Leitungs-Dashboard (Board)

**Route:** `/{locale}/dashboards/board`

Separate, druckoptimierte Ansicht fuer Geschaeftsfuehrung und Aufsichtsgremien
(Rolle `ROLE_TOP_MGMT`, dazu CISO, Compliance-Manager, Konzern-ISB). Der Knopf
"One-Pager (PDF)" oben rechts laedt den Board-One-Pager direkt.
Oben steht, was die Leitung entscheidet: offene Vorlagen mit Billigen und
Ablehnen, fehlende oder bald endende Billigungen und die letzten
Entscheidungen ([Leitungsentscheidungen](LEITUNGSENTSCHEIDUNGEN.md)).
Darunter aggregierte KPIs ohne technische Details:

- ISMS Health Score mit Vorperioden-Vergleich
- Anzahl kritischer Risiken mit Behandlungsstatus
- Compliance-Status (ampelbasiert) fuer die wichtigsten regulatorischen Anforderungen
- Naechste anstehende Audits / Zertifizierungsfristen
- Vorfall-Zusammenfassung (Anzahl, Durchschnittliche Loesungszeit)

Das Board-Dashboard laesst sich direkt als PDF exportieren
("Board-One-Pager"). Der Export beinhaltet das Tenant-Logo und das
Erstellungsdatum.

---

## Risk-Manager-Dashboard

**Route:** `/{locale}/dashboard/risk-manager`

Zielgruppe: Verantwortliche fuer das operative Risikomanagement.

Enthaltene Widgets:

| Widget | Beschreibung |
|---|---|
| Risk-Pipeline | Kanban-Ansicht: neu / in Behandlung / akzeptiert / geschlossen |
| Risk-Appetite-Status | Offene Risiken oberhalb des Appetit-Schwellenwerts |
| Periodenreview-Kalender | Naechste faellige Risk-Reviews pro Risikotraeger |
| Risikobehandlungsplaene | Offene Behandlungsplaene nach Eskalationsstufe |
| Vulnerability-Inbox | CVEs mit CVSS >= 7.0 ohne Behandlungsbestaetigung |

---

## DPO-Workflow-Dashboard

**Route:** `/{locale}/dashboard/dpo`

Zielgruppe: Datenschutzbeauftragte (intern oder extern bestellt).

**Voraussetzung:** Modul `privacy` muss aktiv sein.

Enthaltene Widgets:

| Widget | Beschreibung |
|---|---|
| DSR-Queue | Offene Betroffenenrechte-Anfragen nach Typ und Fristampel |
| DPIA-Pipeline | DPIAs nach Status (Entwurf / Pruefung / Freigegeben / Abgelaufen) |
| Datenpannen-Inbox | Aktive Data-Breach-Meldeverfahren mit 72h-Countdown |
| VVT-Vollstaendigkeit | Verarbeitungsverzeichnis: Eintraege ohne Rechtsgrundlage / DSFA-Pflicht |
| Consent-Ueberlauf | Einwilligungen mit ablaufendem Gueltigkeitsdatum |

DPIA-Pipeline-Detail: Zeigt die Phasen als horizontale Stepper-Bar.
Klick auf eine Phase oeffnet die Detailansicht der betroffenen DPIAs.

---

## CM-Dashboard (Compliance-Manager)

**Route:** `/{locale}/dashboard/compliance-manager`

Zielgruppe: Head of GRC, Compliance-Manager, Multi-Framework-Verantwortliche.

Enthaltene Widgets:

| Widget | Beschreibung |
|---|---|
| Framework-Heatmap | Alle aktiven Frameworks als Heatmap (Score x Fraelligkeit) |
| Heatmap-Drill-Down | Klick auf Framework oeffnet Requirement-Level-Details |
| Mapping-Review-Queue | Cross-Framework-Mappings in Status `review` zur Freigabe |
| Cert-Bundle-Preflight | Zertifizierungspakete mit Ampelstatus fuer Dokumentvollstaendigkeit |
| Wizard-Fortschritt | Laufende Wizard-Sessions aller Frameworks mit Score-Trend |
| Mein-Tag CM-Buckets | Framework-Gap-Alerts, Mapping-Queue, Cert-Preflight direkt eingebettet |

### Wann CM-Heatmap-Drill nutzen

- **Vor einem Audit:** Identifiziere Controls mit Score < 70 % gezielt.
- **Nach Framework-Update:** Pruefe, welche Bereiche durch neue Anforderungen
  (z. B. BSI C5:2026) absanken.
- **Quartals-Reporting:** Exportiere Drill-Down-Ansicht als PDF fuer das Management.

### Wann CertBundle-Preflight nutzen

- **4-8 Wochen vor Zertifizierungsaudit:** Preflight zeigt offene Pflichtdokumente,
  abgelaufene Nachweise und fehlende Unterschriften.
- **Nach Rezertifizierungszyklus:** Starte einen neuen Preflight um den
  Reset-Baseline zu dokumentieren.

---

## Auditor-Dashboard

**Route:** `/{locale}/dashboard/auditor`

Zielgruppe: Interne Revisoren und externe Zertifizierungsauditoren.

Enthaltene Widgets:

| Widget | Beschreibung |
|---|---|
| Aktive Auditplaene | Laufende Auditplaene mit Status und naechstem Termin |
| Evidence-Queue | Controls mit fehlenden oder abgelaufenen Nachweisen |
| NC-Tracker | Nichtkonformitaeten nach Schweregrad und Massnahmen-Status |
| Audit-Freeze-Status | Verfuegbare Stichtag-Snapshots mit SHA-256-Siegel |
| Audit-Log-Viewer | Direktlink zum gefilterten Audit-Log |

Der Auditor hat Lesezugriff auf alle Bereiche, aber kein Schreibrecht auf
Entitaeten. Das gilt ebenso für Group-CISO und Konzern-Auditor ohne Manager-Rolle
und wird zentral geprüft: Formulare zum Anlegen, Bearbeiten oder Klonen öffnen
sich nicht, die Seite „Zugriff verweigert" nennt den Grund. Erlaubt bleiben die
Audit-Arbeit (Audits, Checklisten, Feststellungen, Auditbericht), Kommentare,
SoA-Stichtag-Snapshots, Wirksamkeits-Reviews von Dokumenten, Entscheidungen in
Freigaben, die dem Auditor zugewiesen sind, eigene Reports, Exporte, das eigene
Profil und die Bestätigung von Richtlinien.

---

## Kennzahlen: eine Quelle, eine Ampel

Alle Dashboards, die Startseite, Management-Reports, geplante Reports und die
monatlichen KPI-Snapshots lesen dieselben Kennzahlen aus dem KPI-Katalog. Eine
Kennzahl hat überall denselben Wert und dieselbe Ampel.

- **Ampel-Schwellen:** Admin → KPI-Schwellwerte. Die Werte gelten sofort für alle
  Seiten; im Konzern erbt eine Tochter die Werte der Holding, solange sie keine
  eigenen setzt. Ohne Eintrag gelten 80/60 % (Compliance, Readiness, Health Score)
  bzw. 90/70 % (Behandlungs-, Klassifizierungs-, Schulungs-, Lieferanten-, BIA-Quote).
- **Risiko in Euro** (Modul „Quantitative Risikoanalyse“, sichtbar ab Rolle Manager):
  erwarteter Jahresschaden über alle offenen Risiken (SLE × ARO, akzeptierte Risiken
  zählen mit) und die fünf größten Einzelrisiken. Der Hinweis „x von y offenen
  Risiken bewertet“ zeigt, wie viel des Registers die Summe abdeckt. Zu sehen auf
  CISO-, Vorstands-, Risikomanager- und ISB-Dashboard, auf der Startseite und im
  Konzern-One-Pager.
- **Risikostufen:** überall dieselbe Skala wie in der Risikomatrix: kritisch ab 20,
  hoch ab 12, mittel ab 6.

## Risikoakzeptanz entscheiden

Eine beantragte Risikoakzeptanz erscheint bei der zuständigen Person in **Mein Tag**
(„Risikoakzeptanzen zu entscheiden“) mit einer Zeile wie „Hoch (12) · ca. 100.000 €/Jahr ·
Asset: Kundenportal“. „Akzeptieren“ entscheidet direkt dort; „Ablehnen“ führt zur
Risikoseite, weil ein Grund nötig ist. Zuständig ist bis zur Manager-Schwelle
(Admin → Risiko-Genehmigungsschwellen) die Rolle Manager, darüber Admin.

## Wo finde ich mein Dashboard?

Alle Persona-Dashboards stehen an genau einer Stelle: **Mein Cockpit** in der
Seitenleiste. Jede Person sieht dort nur die Karten ihrer Rollen:

| Karte | Sichtbar für |
|---|---|
| CISO, Risk-Manager, ISB, BCM | jeweilige Persona-Rolle |
| DSB | DSB (wenn `privacy` aktiv) |
| Compliance-Manager | Compliance-Manager (wenn `compliance` aktiv) |
| Auditor | ab Rolle Auditor |
| Board | CISO, Compliance-Manager |

Die Bereiche der Seitenleiste richten sich nach den aktiven Modulen; Admin nur
für ADMIN / SUPER_ADMIN. **Portfolio** (Konzern-Reports) ist ein direkter Link
für Group-CISO und Konzern-Auditor in Konzern-Organisationen.

### Konzern-Reports und Organisationsvergleich

Die Konzern-Reports lesen alle Organisationen unterhalb der eigenen (nie
Schwester- oder Mutterorganisationen). Group-CISO und Konzern-Auditor sehen
sie; Audits auf Töchter ableiten darf nur der Group-CISO.

Der **Organisationsvergleich** (Konzern-Reports → Organisationsvergleich, auch
über die Suche: "Vergleich", "Benchmark") stellt je Organisation die
Management-KPIs (Health Score, Control-Compliance, kritische Risiken,
Behandlungsrate, Vorfälle, BIA, Schulungen, Audit-Feststellungen, Lieferanten,
Dokumente, Jahresschaden in Euro für berechtigte Rollen) und die
Framework-Erfüllung nebeneinander. Jede Zelle ist der Wert vom Dashboard der
jeweiligen Organisation, eingefärbt nach deren KPI-Schwellwerten; leer heißt:
Modul dort nicht aktiv oder keine Daten. Die Konzern-Zeile zeigt Quoten als
einfachen Mittelwert (Ø), Anzahlen und Euro als Summe (Σ). Excel und PDF
enthalten dieselben Werte; jeder Export wird im Audit-Log festgehalten
(`group_report.comparison.export`). Die frühere Framework-Reifegrad-Matrix ist
darin aufgegangen; ihre alte Adresse leitet weiter.

Jede Seite hat genau einen Menüeintrag (Gate `check_nav_area_parity.py`).
Bulk-Importe laufen über "Bulk-Import – Übersicht" (ISMS Kern), nicht
mehr über eigene Einträge je Modul.

---

## Detailseiten: ein Aufbau

Alle Detailseiten haben denselben Aufbau, von Risiko, Asset und Control über
Lieferant, BC-Plan, Verarbeitungstätigkeit und Audit bis zu den
Verwaltungsseiten (Benutzer, Rollen, SSO, Mandanten):

1. **Statusband** unter dem Titel: Lebenszyklus-Status mit Statuswechsel,
   Verantwortliche Person, nächste Fälligkeit bzw. Restrisiko oder Schweregrad
   und, falls eine Freigabe läuft, ein Hinweis „Freigabe: Läuft/Überfällig" mit
   Link auf den Workflow.
2. **Ankerleiste**: Sprungmarken zu den Abschnitten, bleibt beim Scrollen oben.
3. **Verknüpfungen** als erster Block: die Nachweiskette Asset → Risiko →
   Control → Nachweis → Vorfall → Schwachstelle aus den vorhandenen
   Verknüpfungen. Leere Gruppen bleiben sichtbar („Keine Verknüpfung."), damit
   Lücken in der Kette auffallen. Nachweise eines Risikos oder Assets kommen
   über die verknüpften Controls. Wo möglich gibt es „Risiko ableiten",
   „Vorfall erfassen" oder „Schwachstelle ableiten" mit vorbelegtem Formular.
4. Fachabschnitte, danach **Historie** (Audit-Log und Statuswechsel) und
   **Kommentare**.

Datensätze außerhalb der Kette zeigen die Kettenglieder, mit denen sie verknüpft
werden können: ein Lieferant seine unterstützten Assets, Risiken und Dokumente,
ein Behandlungsplan Risiko, Controls und Nachweise, eine Anforderung ihre
Controls und Nachweise, ein Dokument die Controls, die es als Nachweis nutzen,
eine Verarbeitungstätigkeit oder DSFA Assets und Controls, eine
Datenschutzverletzung den Vorfall, ein Patch Assets und Schwachstelle.
Konfigurationsseiten ohne Bezug zur Kette (Rollen, SSO, Sitzungen) haben keinen
Verknüpfungsblock. Die Historie zeigt auf jeder Seite die letzten zehn
Audit-Log-Einträge des Datensatzes.

Gruppen, deren Modul nicht aktiv ist, werden nicht angezeigt.

---

## Formulare: Kern zuerst, nichts zweimal tippen

- **Große Formulare** (ab 25 Feldern, z. B. Lieferant, VVT, DSFA, Vorfall,
  Risiko) haben links eine Gliederung. Offen sind beim Laden nur der erste
  Abschnitt (Kern), Abschnitte mit leeren Pflichtfeldern und Abschnitte mit
  Fehlern. Die übrigen sind eingeklappt und zeigen „3 von 12 ausgefüllt".
- **Ungespeicherte Änderungen**: Wer ein Formular geändert hat und die Seite
  verlässt (Link, Zurück, Tab schließen), wird vorher gefragt. Speichern hebt
  die Warnung auf. Filter-, Lösch- und Massenaktionsformulare sind ausgenommen.
- **Vorbelegung aus dem Kontext**: „Risiko ableiten" auf einem Asset öffnet
  das Risikoformular mit verknüpftem Asset, Titel und Auswirkung aus dem
  Schutzbedarf (höchster CIA-Wert). Auf einem Risiko legt „Neuer
  Behandlungsplan" den Plan mit Risiko, Titel und Risikoeigner als
  Verantwortlichem an. Am Control werden Nachweise direkt hochgeladen
  („Nachweis hochladen" springt dorthin). Vorbelegt wird nur aus Datensätzen
  der eigenen Organisation. URL-Parameter: `fromAsset`, `fromLocation`, `fromRisk`,
  `fromIncident`, `fromVulnerability`.
- **Alte Freitextfelder für Personen** (Eigentümer, Verantwortliche,
  Ansprechpartner aus der Zeit vor der Personenauswahl) erscheinen nur noch,
  solange sie einen Wert haben.

---

## Suchen und Befehle: ein Fenster

**⌘K** (Mac) bzw. **Strg+K**, **⌘P** / **Strg+P**, die Taste **/** und der Such-Knopf in der
Kopfzeile öffnen dasselbe Fenster:

| Eingabe | Ergebnis |
|---|---|
| leer | Befehle: Navigation, Anlegen, Export, Administration (nur, was Ihre Rolle und die aktiven Module erlauben; auf `/admin`-Seiten Administration zuerst) |
| ab 2 Zeichen | Seiten und Einstellungen, Datensätze (Risiken, Assets, Controls, Vorfälle, Dokumente, Lieferanten …) und passende Befehle |

Pfeiltasten wählen über alle Gruppen, **Enter** öffnet, **Esc** schließt. **?** zeigt alle Tastenkürzel.

Datum und Uhrzeit erscheinen überall im Format und in der Zeitzone aus
*Administration → Einstellungen → Anwendung*; ein leeres Datum wird als „-" angezeigt.

## Verwandte Dokumente

- `docs/user-guide/MEIN_TAG.md` — Inbox-Aggregation fuer alle Rollen
- `docs/user-guide/COMPLIANCE_WIZARD.md` — Wizard-System fuer Compliance-Manager
- `docs/user-guide/ACTIVITY_FEED.md` — Activity-Feed fuer Auditoren und ISBs
