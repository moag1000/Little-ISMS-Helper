# Little ISMS Helper

<div align="center">

<img src="public/logo.svg" alt="Little ISMS Helper" width="280" />

**Multi-Tenant ISMS-Plattform mit Multi-Framework-Compliance -- ISO 27001:2022, NIS2, DORA, TISAX, BSI IT-Grundschutz und 20 weitere Frameworks.**

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony 7.4](https://img.shields.io/badge/Symfony-7.4-000000?logo=symfony&logoColor=white)](https://symfony.com/)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](LICENSE)
[![ISO 27001:2022](https://img.shields.io/badge/ISO-27001%3A2022-blue)](https://www.iso.org/standard/27001)

![Entities](https://img.shields.io/badge/Entities-80-informational)
![Frameworks](https://img.shields.io/badge/Frameworks-25-informational)
![Controls](https://img.shields.io/badge/ISO%2027001%20Controls-93-informational)
![Tests](https://img.shields.io/badge/Tests-2%2C850%2B-informational)

[Funktionen](#funktionen) |
[Quick Start](#quick-start) |
[Architektur](#architektur) |
[Testing](#testing) |
[Dokumentation](#dokumentation) |
[Lizenz](#lizenz)

</div>

---

## Fuer wen ist dieses Tool?

### Der Sweet Spot

Little ISMS Helper wurde fuer Organisationen gebaut, fuer die Enterprise-ISMS-Tools zu teuer und Spreadsheets zu riskant sind. Der typische Nutzer ist ein IT-Leiter, QM-Beauftragter oder nebenberuflicher ISB der ein ISMS aufbauen muss -- oft ohne dediziertes Security-Budget und ohne externen Berater.

| Organisationsgroesse | Passt? | Warum |
|----------------------|--------|-------|
| **10-50 Mitarbeiter** | Ja | Quick-Entry-Formulare, Essential-Controls-Filter (31 statt 93), Branchen-Baselines mit Vorausfuellung, adaptive Fortschrittsschwellen |
| **51-250 Mitarbeiter** | Ja (Sweet Spot) | Voller Feature-Umfang, Multi-Framework mit Data-Reuse, Holding-Struktur fuer Tochtergesellschaften |
| **251-1.000 Mitarbeiter** | Ja, mit Einschraenkungen | Alle Features vorhanden, aber API-Integrationen (SIEM, Ticketing) muessen selbst gebaut werden |
| **1.000+ Mitarbeiter** | Machbar, braucht Investment | Architektur traegt (Multi-Tenancy, API Platform), aber Integrationslandschaft und SaaS-Betrieb muessen aufgebaut werden |

### Branchen

Besonders geeignet fuer regulierte Branchen im DACH-Raum:

- **Automotive** -- TISAX-Baseline mit VDA-ISA-Mapping
- **Finanzdienstleister** -- DORA-Compliance, BaFin-Anforderungen
- **Gesundheitswesen** -- KRITIS-Health, DiGAV, Patientendatenschutz
- **IT-Dienstleister / Systemhaeuser** -- ISO 27001 fuer Ausschreibungsfaehigkeit, SOC 2
- **Cloud-/Hosting-Provider** -- BSI C5 (2020 + 2026), SOC 2
- **Produktion / Fertigung** -- NIS2 (ab 50 MA / 10 Mio. EUR), BSI IT-Grundschutz
- **Kritische Infrastruktur** -- KRITIS-Dachgesetz, BSI-Meldepflichten

### Typische Nutzerprofile

| Rolle | Was das Tool ihnen bringt |
|-------|--------------------------|
| **IT-Admin als Teilzeit-ISB** | Gefuehrter Einstieg in 7 Phasen, ISO-9001-Bruecke im Glossar, Quick-Entry fuer die ersten Assets und Risiken |
| **Informationssicherheitsbeauftragter (ISB)** | Voller ISMS-Lifecycle, SoA-Management, Risikobehandlungsplaene, Management-Reviews, Audit-Vorbereitung |
| **CISO / Geschaeftsfuehrung** | Board-One-Pager, KPI-Dashboards, Zertifizierungs-Dokumentenpaket als ZIP, Konzern-Uebersicht |
| **Datenschutzbeauftragter (DSB/DPO)** | Verarbeitungsverzeichnis, DSFA, Datenschutzverletzungen mit 72h-Frist, Betroffenenrechte |
| **Compliance Manager** | 25 Frameworks mit Cross-Mapping, transitive Coverage, FTE-Einsparung durch Data-Reuse |
| **Interner / externer Auditor** | Evidence-Management, Audit-Freeze, Audit-Pakete, tamper-evidenter Audit-Trail |

### Was dieses Tool nicht ist

- **Kein Vulnerability-Scanner** -- es verwaltet Schwachstellen, scannt aber keine Systeme
- **Kein SIEM** -- es nimmt Incidents entgegen, sammelt aber keine Logs
- **Kein Dokumentenmanagementsystem** -- es verlinkt Nachweise zu Controls, ist aber kein SharePoint-Ersatz
- **Kein Fertigprodukt fuer den Enterprise-Massenmarkt** -- Integrationen (Jira, ServiceNow, Nessus) muessen fuer grosse Umgebungen ergaenzt werden

### Roadmap und Unterstuetzung

Dieses Projekt wird als Open Source (AGPL v3) entwickelt. Die Kernfunktionalitaet ist produktionsreif -- die folgenden Features erfordern Funding:

| Feature | Status | Impact |
|---------|--------|--------|
| Scanner-Integration (OpenVAS / Nessus) | Geplant | Automatisierte Schwachstellenerfassung |
| Ticketing-Connector (Jira / ServiceNow) | Geplant | Massnahmen-Tracking in bestehenden Workflows |
| SaaS-Deployment mit Self-Service-Signup | Architektur steht | Organisationen ohne Docker-Know-how erreichen |
| Weitere Sprachen (FR, IT, NL, PL) | Infrastruktur steht (96 Domaenen) | Schweizer Markt, EU-weite NIS2-Umsetzung |
| Unabhaengiges Security-Audit | Geplant | Vertrauen fuer sicherheitskritisches Tool |
| API-Ausbau (80 Entities, aktuell 18 exponiert) | Architektur steht | Enterprise-Integrationsfaehigkeit |

**Das Projekt unterstuetzen:** Ueber den Sponsor-Button auf GitHub koennen Sie die Weiterentwicklung direkt foerdern. Jeder Beitrag fliesst in die oben genannten Features.

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-red?logo=github)](https://github.com/sponsors/moag1000)

---

## Funktionen

### Compliance und Frameworks

- **ISO 27001:2022** -- Alle 93 Annex-A-Controls und Clauses 4-10 vollstaendig abgedeckt
- **25 Compliance-Frameworks** -- ISO 27001, ISO 22301, ISO 27005, ISO 27701 (2019+2025), NIS2, NIS2UmsuCG, DORA, TISAX, BSI IT-Grundschutz, BSI C5 (2020+2026), SOC 2, NIST CSF 2.0, CIS Controls v8.1, GDPR, BDSG, EU AI Act, GxP, DiGAV, TKG, KRITIS, KRITIS-Health, MRIS v1.5
- **Cross-Framework-Mapping** -- 8 kuratierte Seed-Kataloge mit transitiver Compliance-Ableitung; ein Nachweis bedient mehrere Frameworks gleichzeitig (Data-Reuse-Prinzip)
- **Branchen-Baselines** -- 9 vorkonfigurierte Starter-Pakete (Generic, Production, Finance, KRITIS-Health, Automotive, Cloud, MSP, IT-Service, Hosting) fuer sofortigen Einstieg
- **Framework-Reife-Baselines** -- 35 Reife-Soll-Pakete (7 Frameworks x 5 Branchen: ISO 27001, BSI IT-Grundschutz, BSI C5, NIS2, DORA, TISAX, GDPR x KRITIS/Finance/SaaS/Manufacturing/Healthcare)
- **MRIS v1.5** -- 19 zusaetzliche Branchen-Reife-Baselines mit DE/EN-i18n
- **GSTOOL-XML-Import** -- 5-phasiger Migrationspfad fuer Verinice-Profile (Zielobjekte -> Bausteine -> Massnahmen -> Risikoanalyse), Admin-UI mit Tabbed-Preview
- **SoA-Export** -- Statement of Applicability als PDF, inklusive Management-Review nach Clause 9.3

### Risikomanagement

- **5x5-Risikomatrix** -- Eintrittswahrscheinlichkeit und Auswirkung mit visueller Bewertung
- **Risikobehandlungsplaene** -- Formaler Akzeptanzprozess mit Genehmigungsworkflow und Audit-Trail
- **Risk-Appetite** -- Organisationsweite Schwellenwerte mit automatischer Warnung
- **Periodische Reviews** -- Automatisierte Erinnerungen nach ISO 27001 Clause 6.1.3.d
- **Vulnerability- und Patch-Management** -- CVE/CVSS-Tracking (NIS2-konform)

### GDPR / Datenschutz

- **Verarbeitungsverzeichnis (VVT)** -- Strukturierte Erfassung nach Art. 30 DSGVO
- **DPIA** -- Datenschutz-Folgenabschaetzung nach Art. 35/36 mit 6-Schritt-Workflow
- **Data-Breach-Management** -- 72-Stunden-Meldefrist nach Art. 33 mit automatischem Deadline-Tracking
- **Betroffenenrechte (DSR)** -- Auskunft, Loeschung, Berichtigung, Datenportabilitaet
- **Einwilligungsverwaltung (Consent)** -- Nachweisbare Einwilligungen mit Versionierung

### Business Continuity (BCM)

- **Business-Impact-Analyse** -- RTO, RPO, MTPD nach BSI 200-4
- **BC-Plaene** -- Kontinuitaetsstrategien mit Uebungsverwaltung
- **Krisenstab** -- Rollen und Eskalationspfade nach BSI 200-4
- **Uebungsmanagement** -- Planung, Durchfuehrung und Auswertung von BC-Uebungen

### Workflow-System

- **Event-getriebene Auto-Progression** -- Workflows schreiten automatisch fort, wenn relevante Felder befuellt werden
- **Vorkonfigurierte Workflows** -- GDPR Data Breach (72h), Incident Response (hoch/niedrig), Risikobehandlung, DPIA
- **AND/OR-Logik** -- Komplexe Bedingungen fuer Workflow-Schritte
- **Zeitbasierte Schritte** -- Automatische Progression nach konfigurierbarer Wartezeit
- **Cron-Integration** -- `app:process-timed-workflows` fuer vollautomatische Verarbeitung

### Corporate Structure und Multi-Tenancy

- **Holding-/Konzernstruktur** -- Tenant-Hierarchie mit Cycle-Safety und Baseline-Vererbung
- **Group-CISO-Dashboards** -- 7 Konzern-Uebersichten (NIS2-Matrix, Top-10-Risiken, SoA-Matrix 93xN, Supplier-Dedup, Incident-Cross-Post)
- **Policy-Vererbung** -- Mandatory-Policies durchmandatieren oder lokalen Override erlauben
- **Portfolio-Report** -- Delta-Trends via Snapshots mit Drill-Down auf einzelne Requirements

### KPI und Reporting

- **KPI-Dashboard** -- ISMS Health Score, Framework-Compliance, Risk-Appetite, MTTR
- **Taegliche Snapshots** -- 12-Monatstrend mit automatisiertem Cron-Job
- **Board-One-Pager** -- Management-Report als PDF fuer Geschaeftsfuehrung
- **Excel-Exporte** -- Risiken, Assets, Controls, Compliance-Status
- **Glossar** -- 171 Begriffe mit ISO-9001-Analogien fuer Einsteiger

### Setup und Onboarding

- **Setup-Wizard** -- 8 Schritte von Tenant-Erstellung bis Framework-Auswahl
- **3-Bucket-Applicability** -- Frameworks automatisch in Pflicht / Empfohlen / Optional klassifiziert
- **Guided Tours** -- Rollenbezogene Einfuehrungen (Junior, ISB, CISO, Auditor, Compliance Manager)
- **Command Palette** -- Cmd+K / Ctrl+K fuer schnellen Zugriff

### Sicherheit und Administration

- **RBAC** -- USER, AUDITOR, MANAGER, ADMIN, SUPER_ADMIN plus Holding-Rollen ROLE_GROUP_CISO und ROLE_KONZERN_AUDITOR (50+ Permissions)
- **Multi-Auth** -- Lokale Anmeldung, Azure OAuth, SAML, Generic-SSO (OIDC/OAuth2 mit PKCE, JWKS-Verifikation, JIT-Provisioning + Approval-Queue, Domain-Bindung, AEAD-verschluesselte Client-Secrets)
- **MFA** -- TOTP mit Backup-Codes
- **Audit-Log** -- HMAC-SHA256-Chain, tamper-evident, NIS2-konform
- **Audit-Freeze** -- SHA-256-versiegeltes Compliance-Abbild zum Stichtag
- **Backup/Restore** -- AES-256-GCM-Verschluesselung, Tenant-Scoping, DR-Runbook

### Design und Barrierefreiheit

- **FairyAurora v4** -- Cyberpunk-Design-System mit Alva-Maskottchen (9 Stimmungen)
- **Dark Mode** -- Vollstaendige Theme-Unterstuetzung
- **WCAG 2.2 AA** -- ARIA, Keyboard-Navigation, Focus-Management, Skip-Links
- **i18n** -- Deutsch und Englisch, 180 Uebersetzungsdateien in 90 Domaenen

---

## Screenshots

> Screenshots werden hier ergaenzt. Die Anwendung verfuegt ueber ein Dashboard, Risikomatrix, Compliance-Uebersicht, Workflow-Editor und Management-Reports.

---

## Quick Start

### Docker (empfohlen)

```bash
git clone https://github.com/moag1000/Little-ISMS-Helper.git
cd Little-ISMS-Helper

# Production -- All-in-One mit embedded MariaDB
docker-compose -f docker-compose.prod.yml up -d

# Oeffnen: http://localhost/setup
```

Siehe [DOCKER_PRODUCTION.md](docs/deployment/DOCKER_PRODUCTION.md) fuer Details.

### Lokale Installation

**Voraussetzungen:** PHP 8.4+, Composer 2.x, PostgreSQL 16+ oder MySQL 8.0+

```bash
git clone https://github.com/moag1000/Little-ISMS-Helper.git
cd Little-ISMS-Helper

# Dependencies
composer install
php bin/console importmap:install

# Umgebung konfigurieren
cp .env .env.local
# DATABASE_URL in .env.local anpassen

# Datenbank einrichten
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:setup-permissions --admin-email=admin@example.com --admin-password=admin123
php bin/console isms:load-annex-a-controls

# Server starten
symfony serve
```

Oeffnen: `http://localhost:8000/setup`

Der Setup-Wizard fuehrt durch Tenant-Erstellung, Framework-Auswahl und Branchen-Baseline.

---

## Architektur

### Technologie-Stack

| Komponente | Technologie |
|---|---|
| Backend | PHP 8.4+ (8.5 tested), Symfony 7.4 LTS, Doctrine ORM 3.6, Doctrine-Migrations-Bundle 4.0 |
| Frontend | Twig 3.24, Bootstrap 5.3, Stimulus 3.2, Turbo 8 (Hotwire), Chart.js 4 |
| API | API Platform 4.3, OpenAPI 3.0 |
| Datenbank | PostgreSQL 16+ / MySQL 8.0+ / MariaDB 10.11+ |
| Export | Dompdf >=3.1.5 (PDF), PhpSpreadsheet >=5.7 (Excel) |
| Testing | PHPUnit 13.1 |
| Design | FairyAurora v4 |

### Projektstruktur

```
src/
  Entity/          78 Doctrine-Entities (alle mit tenant_id)
  Controller/     123 HTTP-Controller
  Service/        143 Business-Logic-Services
  Command/         91 Console-Commands
  Security/Voter/     Authorization-Voter

templates/        Twig-Templates mit Aurora v4 Macros
translations/     180 YAML-Dateien (90 Domaenen x 2 Sprachen)
tests/            ~8.290 Testmethoden (Unit + WebTestCase)
config/           Symfony-Konfiguration, Module, Active Modules
```

### Kern-Services

| Service | Aufgabe |
|---|---|
| `TenantContext` | Multi-Tenant-Scoping |
| `RiskService`, `AssetService`, `ControlService` | ISMS-Kern-CRUD |
| `WorkflowService` | Workflow-Instanzverwaltung |
| `WorkflowAutoProgressionService` | Event-getriebene Workflow-Progression |
| `AuditLogger` | Tamper-evidenter Audit-Trail |
| `BackupService`, `RestoreService` | Backup/Restore mit Verschluesselung |
| `ComplianceMappingService` | Cross-Framework-Mapping und Data Reuse |

### Wichtige Patterns

- **Multi-Tenancy:** Jede Entity traegt `tenant_id`. `TenantContext` filtert automatisch.
- **RBAC:** 7 Rollen (USER bis SUPER_ADMIN plus ROLE_GROUP_CISO und ROLE_KONZERN_AUDITOR), 50+ granulare Permissions via Voter.
- **Data Reuse:** Ein Nachweis wird ueber Cross-Framework-Mappings mehreren Frameworks zugeordnet. Review-Pflicht bei Uebernahme.
- **Workflow Auto-Progression:** Entity-Aenderungen triggern automatische Workflow-Schritte (AND/OR-Logik, zeitbasiert).

---

## Testing

```bash
# Alle Tests ausfuehren
php bin/phpunit

# Einzelne Suite
php bin/phpunit tests/Controller/
php bin/phpunit tests/Service/RiskServiceTest.php

# Lesbare Ausgabe
php bin/phpunit --testdox
```

### Test-Datenbank einrichten

```bash
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/console app:setup-permissions --admin-email=test@example.com --admin-password=test123 --env=test
php bin/console isms:load-annex-a-controls --env=test
```

### Statistiken

| Metrik | Wert |
|---|---|
| Testmethoden | 3.919 |
| Testdateien | 267 |
| Test-LOC | ~75.500 |
| Controller-Tests | ~1.100 |
| Service-Tests | ~900 |
| Repository-Tests | ~400 |
| Entity-Tests | ~128 |

---

## Dokumentation

### Setup und Deployment

| Dokument | Thema |
|---|---|
| [Docker Setup](docs/setup/DOCKER_SETUP.md) | Entwicklungs-Setup mit Docker Compose |
| [Docker Production](docs/deployment/DOCKER_PRODUCTION.md) | All-in-One Production Container |
| [Deployment Wizard](docs/deployment/DEPLOYMENT_WIZARD.md) | 10-Schritte-Setup ohne Docker |
| [Plesk Deployment](docs/deployment/DEPLOYMENT_PLESK.md) | Strato/Plesk-spezifisches Setup |
| [Authentication](docs/setup/AUTHENTICATION_SETUP.md) | RBAC, Azure OAuth, SAML |
| [API Setup](docs/setup/API_SETUP.md) | REST API, Swagger UI |

### Architektur und Compliance

| Dokument | Thema |
|---|---|
| [Solution Description](docs/architecture/SOLUTION_DESCRIPTION.md) | Architektur-Uebersicht |
| [Data Reuse Analysis](docs/architecture/DATA_REUSE_ANALYSIS.md) | Cross-Modul-Datenwiederverwendung |
| [Cross-Framework Mappings](docs/architecture/CROSS_FRAMEWORK_MAPPINGS.md) | Multi-Framework-Mapping-Architektur |
| [ISO Compliance](docs/compliance/ISO_COMPLIANCE_IMPLEMENTATION_SUMMARY.md) | ISO 27001:2022 Implementierungsdetails |
| [Corporate Structure](docs/CORPORATE_STRUCTURE.md) | Holding-/Konzern-Governance |

### Betrieb

| Dokument | Thema |
|---|---|
| [Disaster Recovery](docs/operations/DISASTER_RECOVERY.md) | Backup-Scope, Restore-Szenarien, APP_SECRET |
| [Backup Architecture](docs/operations/BACKUP_ARCHITECTURE.md) | Format 2.0, Entity-Coverage, Dependency-Order |
| [Audit Logging](docs/setup/AUDIT_LOGGING.md) | HMAC-Chain, Verifikation |
| [Admin Guide](docs/ADMIN_GUIDE.md) | Admin-Portal-Referenz |

### UI/UX

| Dokument | Thema |
|---|---|
| [UI/UX Quick Start](docs/ui-ux/UI_UX_QUICK_START.md) | Keyboard Shortcuts, Command Palette |
| [UI Patterns](docs/ui-patterns/README.md) | Komponenten-Bibliothek |
| [Accessibility](docs/ui-patterns/ACCESSIBILITY.md) | WCAG 2.2 AA Richtlinien |

### Security

| Dokument | Thema |
|---|---|
| [Security Architecture](docs/security/SECURITY.md) | Sicherheitsarchitektur |
| [OWASP Audit](docs/reports/security-audit-owasp-2025-rc1.md) | Security Audit Report |
| [License Report](docs/reports/license-report.md) | Third-Party-Lizenz-Compliance |

---

## Projekt unterstuetzen

Wenn Ihnen der Little ISMS Helper weiterhilft, freue ich mich ueber Unterstuetzung:

<a href="https://www.buymeacoffee.com/moag1000" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me A Coffee" style="height: 60px !important;width: 217px !important;" ></a>

---

## Lizenz

**GNU Affero General Public License v3.0 (AGPL-3.0)**

Freie Nutzung, Modifikation und Verteilung -- auch kommerziell. Quellcode-Offenlegung bei SaaS-Betrieb erforderlich.

Siehe [LICENSE](LICENSE) fuer den vollstaendigen Text.

---

<div align="center">

Little ISMS Helper -- Open-Source ISMS fuer den deutschsprachigen Markt

[GitHub Issues](https://github.com/moag1000/Little-ISMS-Helper/issues) |
[Dokumentation](docs/)

</div>
