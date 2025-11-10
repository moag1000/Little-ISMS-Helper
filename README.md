# 🛡️ Little ISMS Helper

<div align="center">

<img src="public/logo.svg" alt="Little ISMS Helper - Cyberpunk Security Fairy" width="300" />

**Eine moderne, webbasierte ISMS-Lösung für kleine und mittelständische Unternehmen**

[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony Version](https://img.shields.io/badge/Symfony-7.3-000000?logo=symfony&logoColor=white)](https://symfony.com/)
[![License](https://img.shields.io/badge/License-Proprietary-red)](LICENSE)
[![ISO 27001:2022](https://img.shields.io/badge/ISO-27001%3A2022-blue)](https://www.iso.org/standard/27001)
[![Tests](https://img.shields.io/badge/Tests-122%20passing-success)](tests/)

[Funktionen](#-funktionen) • [Quick Start](#-quick-start) • [Dokumentation](#-dokumentation) • [Roadmap](#-roadmap) • [Beitragen](#-beitragen)

</div>

---

## 📖 Über das Projekt

Little ISMS Helper unterstützt Organisationen bei der **Implementierung und Verwaltung ihres ISMS nach ISO/IEC 27001:2022**. Die Anwendung hilft dabei, Compliance-Anforderungen zu erfüllen, Risiken zu managen, Audits durchzuführen und KPIs zu überwachen – alles in einer zentralen, benutzerfreundlichen Plattform.

### 🎯 Warum Little ISMS Helper?

- ✅ **ISO 27001:2022 konform** - Alle 93 Annex A Controls integriert
- 🔄 **Intelligente Datenwiederverwendung** - Einmal erfasst, mehrfach genutzt
- 📊 **Multi-Framework Support** - ISO 27001, TISAX, DORA parallel verwalten
- 🚀 **Modern & schnell** - Symfony 7.3, PHP 8.4, Progressive UI
- 🔓 **Open Architecture** - REST API für Integrationen
- 📈 **Automatische KPIs** - Echtzeit-Metriken ohne manuelle Berechnung

### 🎨 Design & Branding

Das **Little ISMS Helper Logo** zeigt eine freundliche Cyberpunk-Fee, die die Mission der Anwendung verkörpert: Ein zugänglicher, moderner Helfer für Cyber Security und ISMS-Management. Die Fee trägt einen leuchtenden Sicherheits-Shield und kombiniert niedliche Elemente mit technischen Details wie Neon-Flügeln, Binärcode und digitalen Effekten – die perfekte Metapher für die Verbindung von Benutzerfreundlichkeit und professioneller Sicherheitstechnologie.

---

## ✨ Funktionen

<table>
<tr>
<td width="50%">

### 📋 Compliance Management
- **Statement of Applicability** - 93 ISO 27001:2022 Controls
- **SoA PDF Export** - Professional ISO 27001 Reports ✨ NEW!
- **Multi-Framework Support** - TISAX, DORA, NIS2, BSI IT-Grundschutz
- **Cross-Framework Mappings** - Transitive Compliance
- **Audit Management** - ISO 27001 Clause 9.2
- **Management Review** - ISO 27001 Clause 9.3

</td>
<td width="50%">

### 🔐 Risk & Asset Management
- **Asset Management** - CIA-Bewertung, ISO 27001 Fields
- **Risk Assessment** - 5x5 Matrix Visualisierung
- **Risk Appetite Management** - ISO 27005 Compliance
- **Risk Treatment Plans** - Timeline, Budget, Controls
- **Vulnerability Management** - CVE/CVSS Tracking (NIS2) ✨ NEW!
- **Patch Management** - Deployment Tracking (NIS2) ✨ NEW!
- **Risk Treatment** - Strategien & Restrisiko
- **Incident Management** - Vorfallsbehandlung
- **Data Breach Tracking** - GDPR-konform

</td>
</tr>
<tr>
<td width="50%">

### 🏢 Business Continuity & Crisis Management
- **BCM Module** - BIA mit RTO/RPO/MTPD
- **Process Management** - Geschäftsprozesse
- **Impact Analysis** - Kritikalitätsbewertung
- **Recovery Planning** - Kontinuitätsplanung
- **Crisis Team Management** - BSI 200-4 Krisenstab ✨ NEW!

</td>
<td width="50%">

### 👥 User & Training Management
- **RBAC** - Role-Based Access Control
- **Multi-Auth** - Local, Azure OAuth, SAML
- **MFA Token Management** - TOTP, WebAuthn, SMS (NIS2) ✨ NEW!
- **Training Management** - Schulungsplanung
- **Audit Logging** - Vollständige Änderungsverfolgung

</td>
</tr>
<tr>
<td width="50%">

### 📊 Reporting & Integration
- **PDF/Excel Export** - 6 professionelle PDF Reports ✨ NEW!
- **REST API** - 30 Endpoints, OpenAPI 3.0
- **Email Notifications** - Automatisierte Benachrichtigungen
- **Workflow Engine** - Genehmigungsprozesse

</td>
<td width="50%">

### 🎨 Modern UI/UX
- **Progressive Disclosure** - Aufgeräumte Oberfläche
- **Dark Mode** - Theme-Switching
- **Quick View** - Modal-Previews (Space)
- **Global Search** - Cmd+K/Ctrl+K
- **Drag & Drop** - Dashboard & File Upload ✨ NEW!
- **Bulk Actions** - Multi-Select für 4 Module
- **Keyboard Shortcuts** - Power-User-Features

</td>
</tr>
</table>

### 🔄 Intelligente Datenwiederverwendung

Ein Kernprinzip: **Maximale Wertschöpfung aus einmal erfassten Daten**

- **BCM → Asset Protection** - RTO/RPO leiten Verfügbarkeitsanforderungen ab
- **Incident → Risk Validation** - Risikobewertungen werden durch echte Vorfälle validiert
- **Control → Effectiveness** - Incident-Reduktion misst Control-Wirksamkeit
- **Training → Coverage** - Training-Lücken werden automatisch identifiziert
- **Process → Risk Alignment** - BIA und Risikobewertung werden konsistent gehalten

**Zeitersparnis:** ~10,5 Stunden (95%) pro Audit-Zyklus durch automatisierte Analysen

---

## 🚀 Quick Start

### Voraussetzungen

- **PHP** 8.4 (empfohlen) oder 8.2+
- **Composer** 2.x
- **PostgreSQL** 16+ oder MySQL 8.0+
- **Symfony CLI** (optional)

### Installation (5 Minuten)

```bash
# 1. Repository klonen
git clone https://github.com/moag1000/Little-ISMS-Helper.git
cd Little-ISMS-Helper

# 2. Dependencies installieren
composer install
php bin/console importmap:install

# 3. Umgebung konfigurieren
cp .env .env.local

# 3.1. APP_SECRET generieren
echo "APP_SECRET=$(openssl rand -hex 32)" >> .env.local

# 3.2. Datenbank-URL konfigurieren (wählen Sie eine Option):
# Option A: SQLite (Standard, ideal für Tests/Entwicklung):
# DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"

# Option B: PostgreSQL (Empfohlen für Produktion):
# echo 'DATABASE_URL="postgresql://dbuser:dbpassword@127.0.0.1:5432/little_isms?serverVersion=16&charset=utf8"' >> .env.local

# Option C: MySQL:
# echo 'DATABASE_URL="mysql://dbuser:dbpassword@127.0.0.1:3306/little_isms?serverVersion=8.0.32&charset=utf8mb4"' >> .env.local

# 4. Datenbank einrichten
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction

# 5. Rollen & Berechtigungen einrichten + Admin-User erstellen
php bin/console app:setup-permissions \
  --admin-email=admin@example.com \
  --admin-password=admin123

# 6. ISO 27001 Controls laden
php bin/console isms:load-annex-a-controls

# 7. Server starten
symfony serve
# oder: php -S localhost:8000 -t public/
```

**Fertig!** 🎉 Öffnen Sie http://localhost:8000

**Standard Login-Daten:**
- Email: `admin@example.com`
- Passwort: `admin123`

⚠️ **WICHTIG:** Ändern Sie das Admin-Passwort nach dem ersten Login!

### 🧙 Deployment Wizard (Alternative Installation)

Für eine geführte Setup-Erfahrung können Sie den **Deployment Wizard** nutzen:

```bash
# Nach Schritten 1-3 oben (Clone, Dependencies, .env)
php -S localhost:8000 -t public/
```

Öffnen Sie dann im Browser: `http://localhost:8000/setup`

Der Wizard führt Sie durch:
- ✅ **Schritt 1**: System-Anforderungen automatisch prüfen
- ✅ **Schritt 2**: Module auswählen (Core ISMS, BCM, Compliance, etc.)
- ✅ **Schritt 3**: Datenbank automatisch initialisieren
- ✅ **Schritt 4**: Basis-Daten importieren (ISO 27001 Controls, Permissions)
- ✅ **Schritt 5**: Optional Beispiel-Daten laden
- ✅ **Schritt 6**: Setup abschließen

📖 Detaillierte Anleitung: [DEPLOYMENT_WIZARD.md](DEPLOYMENT_WIZARD.md)

### Automatisierte Setup-Tools ✨ NEU!

Wir bieten drei professionelle Setup-Tools für eine fehlerfreie Installation:

**1. Umfassende Validierung (18+ Checks):**
```bash
chmod +x validate-setup.sh
./validate-setup.sh
```

Prüft automatisch:
- ✅ PHP-Version und Extensions
- ✅ Composer Dependencies
- ✅ Entity-Migration Konsistenz
- ✅ AuditLog Konfiguration
- ✅ Foreign Key Constraints

**2. Sichere Datenbank-Erstellung:**
```bash
chmod +x create-database.sh
./create-database.sh
```

Features:
- ✅ Interaktive Einrichtung mit Bestätigungen
- ✅ Automatische APP_SECRET Generierung
- ✅ Optionaler Admin-User
- ✅ ISO 27001 Controls (93 Controls)
- ✅ Schema-Validierung

**3. Datenbank-Reset (bei Fehlern):**
```bash
chmod +x reset-database.sh
./reset-database.sh
```

📖 Siehe [SETUP_TOOLS.md](SETUP_TOOLS.md) für vollständige Dokumentation.

### Troubleshooting

**Problem: "APP_SECRET is empty"**
```bash
# Generieren Sie einen neuen Secret:
php bin/console secret:generate-keys
# oder manuell:
echo "APP_SECRET=$(openssl rand -hex 32)" >> .env.local
```

**Problem: "Could not create database"**
```bash
# Stellen Sie sicher, dass die DATABASE_URL in .env.local korrekt ist
# Prüfen Sie, ob der Datenbankserver läuft (PostgreSQL/MySQL)
# Für SQLite: Stellen Sie sicher, dass das var/ Verzeichnis beschreibbar ist
chmod -R 777 var/
```

**Problem: "No admin user found"**
```bash
# Erstellen Sie manuell einen Admin-User:
php bin/console app:setup-permissions \
  --admin-email=admin@example.com \
  --admin-password=SecurePassword123!
```

**Problem: "Permission denied" beim Login**
```bash
# Führen Sie das Setup-Permissions Command erneut aus:
php bin/console app:setup-permissions
```

**Problem: Migration-Fehler "Column not found" oder "already exists"**
```bash
# Datenbank komplett zurücksetzen und neu aufsetzen:
chmod +x reset-database.sh
./reset-database.sh

# Oder manuell:
php bin/console doctrine:database:drop --force
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:setup-permissions --admin-email=admin@example.com --admin-password=admin123
```

### Produktions-Deployment

Für Produktions-Deployments beachten Sie bitte:

1. **Sichere Konfiguration**: Verwenden Sie starke, einzigartige Werte für `APP_SECRET`
2. **Datenbank**: Verwenden Sie PostgreSQL 16+ oder MySQL 8.0+ statt SQLite
3. **HTTPS**: Konfigurieren Sie SSL/TLS-Verschlüsselung
4. **Umgebung**: Setzen Sie `APP_ENV=prod` in `.env.local`
5. **Cache**: Führen Sie `php bin/console cache:clear --env=prod` aus

Detaillierte Anweisungen finden Sie in:
- [DEPLOYMENT_WIZARD.md](DEPLOYMENT_WIZARD.md) - Deployment Wizard Setup
- [docs/DOCKER_SETUP.md](docs/DOCKER_SETUP.md) - Docker Compose Setup

### Optional: Weitere Frameworks laden

```bash
# TISAX (VDA ISA) für Automobilindustrie
php bin/console app:load-tisax-requirements

# EU-DORA für Finanzdienstleister
php bin/console app:load-dora-requirements

# NIS2 für kritische Infrastrukturen
php bin/console app:load-nis2-requirements

# BSI IT-Grundschutz für Deutschland
php bin/console app:load-bsi-requirements
```

### Optional: Benachrichtigungen einrichten

```bash
# Crontab bearbeiten
crontab -e

# Täglich um 8 Uhr Benachrichtigungen versenden
0 8 * * * cd /path/to/Little-ISMS-Helper && php bin/console app:send-notifications --type=all
```
---

## 📚 Dokumentation

### Setup & Deployment

| Dokument | Beschreibung |
|----------|--------------|
| [API Setup Guide](docs/API_SETUP.md) | REST API Konfiguration, Swagger UI, Postman |
| [Docker Setup](docs/DOCKER_SETUP.md) | Docker Compose, Entwicklung & Produktion |
| [Authentication Setup](docs/AUTHENTICATION_SETUP.md) | RBAC, Azure OAuth/SAML, Multi-Provider |
| [Audit Logging](docs/AUDIT_LOGGING.md) | Automatische Änderungsverfolgung |
| [Deployment Wizard](DEPLOYMENT_WIZARD.md) | Schritt-für-Schritt Produktionssetup |
| [Setup Tools](SETUP_TOOLS.md) | **NEU!** 3 automatisierte Scripts für fehlerfreie Installation |
| [Setup Validation](SETUP_VALIDATION.md) | Automatische Validierung der Installation (25 Tests) |
| [Migration Fix Report](MIGRATION_FIX.md) | Dokumentation von 5 behobenen kritischen Migrations-Fehlern |
| [Entity-Table Mapping](ENTITY_TABLE_MAPPING.md) | Vollständige Zuordnung aller 23 Entities zu Datenbank-Tabellen |

### Architecture & Design

| Dokument | Beschreibung |
|----------|--------------|
| [Solution Description](SOLUTION_DESCRIPTION.md) | Architektur-Übersicht, Design-Entscheidungen |
| [Data Reuse Analysis](docs/DATA_REUSE_ANALYSIS.md) | Intelligente Datenwiederverwendung |
| [Verification Report](VERIFICATION_REPORT.md) | Code-Nachweis für alle Features |

### Phase Reports (Vollständigkeitsprüfungen)

| Phase | Status | Dokument |
|-------|--------|----------|
| Phase 2 | ✅ 100% | [BCM, Multi-Framework, Data Reuse](docs/PHASE2_COMPLETENESS_REPORT.md) |
| Phase 3 | ✅ 100% | [User Management, Security, RBAC](docs/PHASE3_COMPLETENESS_REPORT.md) |
| Phase 4 | ✅ 100% | [CRUD, Workflows, Risk Matrix](docs/PHASE4_COMPLETENESS_REPORT.md) |
| Phase 5 | ✅ 100% | [Reports, API, Notifications](docs/PHASE5_COMPLETENESS_REPORT.md) |
| **Phase 6** | 🚧 ~70% | **[Module Completeness Audit](docs/MODULE_COMPLETENESS_AUDIT.md)** |

### UI/UX

| Dokument | Beschreibung |
|----------|--------------|
| [UI/UX Quick Start](docs/UI_UX_QUICK_START.md) | Keyboard Shortcuts, Command Palette (⌘K) |
| [UI/UX Implementation](docs/UI_UX_IMPLEMENTATION.md) | Progressive Disclosure, Components |
| [Paket B: Quick View](docs/PHASE5_PAKET_B.md) | Global Search, Quick Preview, Filters |
| [Paket C: Dark Mode](docs/PHASE5_PAKET_C.md) | Theme Toggle, User Preferences, Notifications |

### Quickstart Guides

| Dokument | Beschreibung |
|----------|--------------|
| [Audit Logging Quickstart](docs/AUDIT_LOGGING_QUICKSTART.md) | 3-Schritte Setup für Audit-Logging |

---

## 🛠️ Technologie-Stack

<table>
<tr>
<td><b>Backend</b></td>
<td>PHP 8.4, Symfony 7.3, Doctrine ORM</td>
</tr>
<tr>
<td><b>Frontend</b></td>
<td>Twig, Bootstrap 5, Stimulus, Turbo</td>
</tr>
<tr>
<td><b>Database</b></td>
<td>PostgreSQL 16 / MySQL 8.0+</td>
</tr>
<tr>
<td><b>API</b></td>
<td>API Platform 4.2, OpenAPI 3.0, Swagger UI</td>
</tr>
<tr>
<td><b>Export</b></td>
<td>Dompdf 3.1 (PDF), PhpSpreadsheet 5.2 (Excel)</td>
</tr>
<tr>
<td><b>Email</b></td>
<td>Symfony Mailer, TemplatedEmail</td>
</tr>
<tr>
<td><b>Testing</b></td>
<td>PHPUnit 12.4 (122 tests passing)</td>
</tr>
<tr>
<td><b>CI/CD</b></td>
<td>GitHub Actions (4 parallel jobs)</td>
</tr>
<tr>
<td><b>Deployment</b></td>
<td>Docker, Docker Compose, Nginx</td>
</tr>
</table>

---

## 🗺️ Roadmap

**Vollständige Projekt-Roadmap:** 📋 **[ROADMAP.md](ROADMAP.md)**

### Abgeschlossene Phasen

- ✅ **Phase 1:** Core ISMS - 9 Entities, 93 ISO 27001:2022 Controls, KPI Dashboard
- ✅ **Phase 2:** Data Reuse & Multi-Framework - BCM, TISAX, DORA, Cross-Framework Mappings
- ✅ **Phase 3:** User Management & Security - RBAC, Multi-Auth, Audit Logging
- ✅ **Phase 4:** CRUD & Workflows - Vollständige CRUD, Workflow-Engine, Risk Matrix
- ✅ **Phase 5:** Reporting & Integration - PDF/Excel Export, REST API, Notifications, Dark Mode

### 🚧 Phase 6: Module Completeness & Quality Assurance (In Entwicklung)

**Status:** ~75% Abgeschlossen | **Detaillierte Planung:** [ROADMAP.md - Phase 6](ROADMAP.md#-phase-6-module-completeness--quality-assurance-in-entwicklung)

**Fokus:**
- 🔥 Form Types & Test Coverage (KRITISCH)
- 🏛️ ISO 27001 Inhaltliche Vervollständigung
- 🇪🇺 NIS2 Directive Compliance (KRITISCH - Deadline: 17.10.2024)
- 🇩🇪 BSI IT-Grundschutz Integration
- 🎯 Module UI Completeness (5 Haupt-Module)

**Erwartete Vollständigkeit nach Phase 6:**
- **Technisch:** 95%+
- **ISO 27001:** 98%+
- **NIS2 Compliance:** 95%+ (von 68%)
- **Test Coverage:** 80%+ (von 26%)

### 📅 Zukünftige Phasen

- 🚀 **Phase 7:** Enterprise Features - Multi-Tenancy, Advanced Analytics, Mobile PWA
- 📅 **Backlog:** JWT Auth, Real-time Notifications, Custom Report Builder, Integration Marketplace

**Legende:** ✅ Abgeschlossen | 🚧 In Entwicklung | 🔄 Geplant | 📅 Backlog

---

## 🤝 Beitragen

Wir freuen uns über Beiträge! Bitte lesen Sie unsere [Contributing Guidelines](CONTRIBUTING.md) für Details zu:

- Code-Standards (PSR-12, Symfony Best Practices)
- Commit-Konventionen (Conventional Commits)
- Pull Request Prozess
- Testing-Anforderungen
- Entwicklungsworkflow

### Schnelleinstieg für Contributor

```bash
# Fork & Clone
git clone https://github.com/YOUR-USERNAME/Little-ISMS-Helper.git

# Branch erstellen
git checkout -b feature/your-feature

# Entwickeln & Testen
>>>>>>> origin/main
php bin/phpunit

# Commit & Push
git commit -m "feat(module): add awesome feature"
git push origin feature/your-feature

# Pull Request erstellen
```

Siehe auch: [CHANGELOG.md](CHANGELOG.md) für detaillierte Versionshistorie

---

## 📊 Projekt-Statistiken

- **Codezeilen:** ~43,600+ LOC (+8,900 durch Phase 6H/6I)
- **Entities:** 29 Doctrine Entities (+4: CrisisTeam, MfaToken, Patch, Vulnerability)
- **Controllers:** 22+ Controllers (+4: VulnerabilityController, PatchController, MfaTokenController, CrisisTeamController)
- **Templates:** 98+ Twig Templates (+16: 4 modules × 4 templates)
- **Services:** 13+ Business Logic Services
- **Commands:** 8+ Console Commands (+3: LoadNis2, LoadBsi, LoadIso22301)
- **Forms:** 25+ Symfony Form Types (+4: VulnerabilityType, PatchType, MfaTokenType, CrisisTeamType)
- **Translations:** 1,454 keys (DE) + 1,451 keys (EN) = 2,905 total (+428 keys)
- **Tests:** 122 tests, 228 assertions (100% passing)
  - **Test Coverage:** ~26% (Ziel: 80%+)
  - **Module mit Tests:** 6/29 (21%)
- **API Endpoints:** 30 REST Endpoints
- **Report Types:** 11 (6 PDF + 5 Excel)
- **Notification Types:** 5 automatisierte Typen
- **Compliance Frameworks:** 8 (ISO 27001, ISO 22301, ISO 19011, ISO 31000, ISO 27005, DORA, TISAX, NIS2, BSI)
  - **Vollständig implementiert (100%):** 5 Frameworks (ISO 27001, DORA, TISAX, ISO 22301, ISO 27005) ✨ NEW!
  - **Core Infrastructure (40-50%):** 2 Frameworks (NIS2, BSI) - Entities/Forms/Commands ✓, UI/Workflows pending ✨ NEW!
- **Module Vollständigkeit (Technisch):** ~78% durchschnittlich (+3% durch Phase 6H/6I)
- **ISO 27001:2022 Compliance:** 96% ✅ (Zertifizierungsbereit)
- **Multi-Standard Compliance:** 88% Durchschnitt (+4% durch NIS2/BSI Core)
  - ISO 22301:2019 (BCM): 100% ✅
  - ISO 19011:2018 (Audit): 95% ⚠️
  - ISO 31000:2018 (Risk): 95% ⚠️
  - ISO 27005:2022 (Risk Security): 100% ✅
  - EU DORA: 85% ⚠️
  - TISAX/VDA ISA: 75% ⚠️
  - **NIS2 Directive (EU 2022/2555):** 40% 🚧 (Core: Vulnerability, Patch, MFA entities/forms/UI ✓)
  - **BSI IT-Grundschutz 200-4:** 50% 🚧 (Core: CrisisTeam entity/form/UI ✓, ISO 22301 loader ✓)

---

## 📄 ISO 27001:2022 Compliance

Das Tool orientiert sich an den Anforderungen der **ISO/IEC 27001:2022** und unterstützt:

- ✅ **Clause 4** - Kontext der Organisation
- ✅ **Clause 5** - Führung
- ✅ **Clause 6** - Planung (inkl. 6.2 ISMS Objectives)
- ✅ **Clause 7** - Unterstützung
- ✅ **Clause 8** - Betrieb (inkl. 8.2 Risk Assessment, 8.3 Risk Treatment)
- ✅ **Clause 9** - Bewertung (inkl. 9.2 Internal Audit, 9.3 Management Review)
- ✅ **Clause 10** - Verbesserung
- ✅ **Annex A** - Alle 93 Controls vollständig integriert

Zusätzliche Frameworks:
- **TISAX (VDA ISA)** - 32 Anforderungen für Automobilindustrie
- **EU-DORA** - 30 Anforderungen für Finanzdienstleister

---

## 📞 Support & Community

- **Bugs & Feature Requests:** [GitHub Issues](https://github.com/moag1000/Little-ISMS-Helper/issues)
- **Diskussionen:** [GitHub Discussions](https://github.com/moag1000/Little-ISMS-Helper/discussions)
- **Dokumentation:** [docs/](docs/) Verzeichnis

---

## 📋 Lizenz-Compliance & Third-Party Attributions

Little ISMS Helper verwendet **163 Third-Party Open-Source-Pakete**, die für kommerzielle Nutzung freigegeben sind:

### Compliance-Status

| Status | Pakete | Prozent | Beschreibung |
|--------|--------|---------|--------------|
| ✅ Erlaubt | 160 | 98.2% | Permissive Lizenzen (MIT, BSD, Apache-2.0) |
| 🔄 Copyleft | 1 | 0.6% | LGPL (dynamic linking erlaubt) |
| ❓ Unbekannt | 2 | 1.2% | LGPL-Varianten (manuell geprüft ✓) |

**Gesamtstatus:** ✅ **Lizenzkonform für kommerzielle Nutzung**

### Lizenzinformationen im Web-Interface

Die Anwendung bietet direkt im Web-Interface Zugriff auf alle Lizenzinformationen:

- **📄 NOTICE** - Third-Party Software Attributions
- **📊 Detaillierter Bericht** - Vollständige Compliance-Analyse
- **📈 Zusammenfassung** - Schnellübersicht & KPIs

**Zugriff:** Footer → "Lizenzen" oder direkt unter `/about/licenses`

### Lizenzübersicht

<details>
<summary><b>Hauptkomponenten nach Lizenz</b></summary>

**MIT License (134 Pakete, 82.2%):**
- Symfony Framework & Components
- Doctrine ORM & DBAL
- Bootstrap 5
- Chart.js
- PHPOffice/PhpSpreadsheet
- Monolog
- und weitere...

**BSD-3-Clause (26 Pakete, 16%):**
- Twig Template Engine
- und weitere...

**LGPL (3 Pakete, 1.8%):**
- DomPDF (PDF-Generierung)
- php-font-lib
- php-svg-lib

> **Hinweis:** LGPL-Komponenten werden über Dynamic Linking eingebunden, was kommerzielle Nutzung ohne Quelloffenlegung ermöglicht.

</details>

### Automatische Compliance-Prüfung

Das Projekt nutzt automatisierte Tools zur Lizenzüberwachung:

```bash
# Lizenzbericht generieren
./license-report.sh

# Ausgabe: docs/reports/license-report.md
```

**CI/CD Integration:**
- ✅ Automatische Lizenzprüfung bei jedem Pull Request
- ✅ Monatliche Compliance-Checks
- ✅ Warnungen bei problematischen Lizenzen
- ✅ GitHub Actions Workflow integriert

### Compliance-Dokumentation

- **[NOTICE.md](NOTICE.md)** - Vollständige Attributionen & Lizenzhinweise
- **[docs/reports/license-report.md](docs/reports/license-report.md)** - Automatisch generierter Detailbericht
- **[SETUP_TOOLS.md](SETUP_TOOLS.md#4-license-reportsh)** - Anleitung zur Berichtsgenerierung

### Wichtige Hinweise

- Alle Dependencies sind für **kommerzielle Nutzung** freigegeben
- **Attribution erforderlich** bei Weitergabe (NOTICE.md beachten)
- **Keine Non-Commercial Lizenzen** im Projekt
- **Regelmäßige Prüfung** durch automatisierte Workflows

---

## 📜 Lizenz

<<<<<<< HEAD
### ✅ Implementiert

- [x] Basis-Setup und Projektstruktur
- [x] Alle ISMS Kernentities (Asset, Risk, Control, Incident, etc.)
- [x] Statement of Applicability mit allen 93 Annex A Controls
- [x] Grundlegende Controller und Views für alle Module
- [x] KPI Dashboard mit Echtzeit-Daten
- [x] Datenbank-Migration
- [x] **User Authentication & Authorization** (Symfony Security + Azure AD)
- [x] **Deployment Wizard** mit geführter Einrichtung
- [x] **Audit Logging System** für Compliance
- [x] **Mehrsprachigkeit** (Deutsch/Englisch)
- [x] Business Continuity Management (BCM)
- [x] Multi-Framework Compliance (ISO 27001, TISAX, DORA)
- [x] Rollenbasierte Zugriffskontrolle (RBAC)

### 🚧 In Planung

- [ ] Vollständige CRUD-Operationen für alle Module
- [ ] Formulare mit Validierung
- [ ] Risk Assessment Matrix Visualisierung
- [ ] Erweiterte Reporting & Export Funktionen (PDF, Excel)
- [ ] Datei-Uploads für Nachweise und Dokumentation
- [ ] E-Mail-Benachrichtigungen für Vorfälle und Fälligkeiten
- [ ] REST API für Integration mit anderen Systemen
- [ ] Multi-Tenancy Support (für MSPs)
- [ ] Responsive Design Optimierung
- [ ] Automatisierte Tests (Unit, Integration)
=======
**Proprietary** - Alle Rechte vorbehalten
>>>>>>> origin/main

Siehe [LICENSE](LICENSE) für Details.

---

## 🙏 Danksagungen

- Entwickelt für kleine und mittelständische Unternehmen
- Built with ❤️ using Symfony 7.3
- Unterstützt durch Claude AI (Anthropic)

---

<div align="center">

**[⬆ Zurück nach oben](#-little-isms-helper)**

Made with 🛡️ for better Information Security Management

</div>
