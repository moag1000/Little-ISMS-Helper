# Persona-Dashboards — Benutzerhandbuch (v3.5)

Little ISMS Helper stellt rollenspezifische Dashboards bereit, die nur die
fuer die jeweilige Rolle relevanten KPIs, Aktionen und Navigations-Shortcuts
anzeigen. Die Zuweisung erfolgt automatisch anhand der Symfony-Rollen des
angemeldeten Nutzers.

---

## 5 verkabelte Persona-Rollen

| Persona-Rolle | Symfony-Rolle | Standard-Einstiegs-Dashboard |
|---|---|---|
| CISO / Executive | `ROLE_CISO` | CISO-Dashboard + Board-Dashboard |
| Risk-Manager | `ROLE_RISK_MANAGER` | Risk-Manager-Dashboard |
| DPO / Datenschutzbeauftragter | `ROLE_DPO` | DPO-Workflow + DPIA-Pipeline |
| Compliance-Manager | `ROLE_COMPLIANCE_MANAGER` | CM-Dashboard |
| Auditor (intern/extern) | `ROLE_AUDITOR` | Auditor-Dashboard |

Nutzer ohne Persona-Rolle sehen das Standard-ISMS-Dashboard.

Nutzer mit mehreren Rollen koennen per Dropdown zwischen den zugeordneten
Dashboards wechseln. Die Auswahl wird im Session-Profil gespeichert.

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

## Board-Dashboard

**Route:** `/{locale}/dashboard/board`

Separate, druckoptimierte Ansicht fuer Geschaeftsfuehrung und Aufsichtsgremien.
Enthalt keine technischen Details, nur aggregierte KPIs:

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
Entitaeten (nur NC-Eintraege und Kommentare).

---

## Mega-Menu-Sichtbarkeit per Rolle

Das Haupt-Navigations-Mega-Menu blendet Bereiche aus, die fuer die aktive
Persona-Rolle nicht relevant sind:

| Bereich | Sichtbar fuer |
|---|---|
| Risikomanagement | CISO, Risk-Manager, ISB |
| GDPR / Datenschutz | DPO, Compliance-Manager (wenn `privacy` aktiv) |
| BCM | ISB, CISO (wenn `bcm` aktiv) |
| AI Governance | Compliance-Manager, CISO (wenn `ai_governance` aktiv) |
| Supplier-Mgmt | ISB, CISO, Compliance-Manager |
| Reports / Exports | Alle Rollen (unterschiedliche Tiefe) |
| Admin / Tenant-Settings | Nur ADMIN / SUPER_ADMIN |

Die Sichtbarkeit wird serverseitig per Twig-Extension und Symfony-Security-Voter
gesteuert; kein clientseitiges Ausblenden.

---

## Verwandte Dokumente

- `docs/user-guide/MEIN_TAG.md` — Inbox-Aggregation fuer alle Rollen
- `docs/user-guide/COMPLIANCE_WIZARD.md` — Wizard-System fuer Compliance-Manager
- `docs/user-guide/ACTIVITY_FEED.md` — Activity-Feed fuer Auditoren und ISBs
