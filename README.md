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
- **Multi-Framework Support** - TISAX, DORA
- **Cross-Framework Mappings** - Transitive Compliance
- **Audit Management** - ISO 27001 Clause 9.2
- **Management Review** - ISO 27001 Clause 9.3

</td>
<td width="50%">

### 🔐 Risk & Asset Management
- **Asset Management** - CIA-Bewertung
- **Risk Assessment** - 5x5 Matrix Visualisierung
- **Risk Treatment** - Strategien & Restrisiko
- **Incident Management** - Vorfallsbehandlung
- **Data Breach Tracking** - GDPR-konform

</td>
</tr>
<tr>
<td width="50%">

### 🏢 Business Continuity
- **BCM Module** - BIA mit RTO/RPO/MTPD
- **Process Management** - Geschäftsprozesse
- **Impact Analysis** - Kritikalitätsbewertung
- **Recovery Planning** - Kontinuitätsplanung

</td>
<td width="50%">

### 👥 User & Training Management
- **RBAC** - Role-Based Access Control
- **Multi-Auth** - Local, Azure OAuth, SAML
- **Training Management** - Schulungsplanung
- **Audit Logging** - Vollständige Änderungsverfolgung

</td>
</tr>
<tr>
<td width="50%">

### 📊 Reporting & Integration
- **PDF/Excel Export** - 5 professionelle Reports
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
- [DEPLOYMENT_WIZARD.md](DEPLOYMENT_WIZARD.md) - Schritt-für-Schritt Produktionssetup
- [docs/DOCKER_SETUP.md](docs/DOCKER_SETUP.md) - Docker Compose Setup

### Optional: Weitere Frameworks laden

```bash
# TISAX (VDA ISA) für Automobilindustrie
php bin/console app:load-tisax-requirements

# EU-DORA für Finanzdienstleister
php bin/console app:load-dora-requirements
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

### ✅ Phase 1: Core ISMS (Abgeschlossen)
- ✅ 9 Core Entities (Asset, Risk, Control, Incident, etc.)
- ✅ Statement of Applicability mit 93 ISO 27001:2022 Controls
- ✅ Grundlegende Controller & Views
- ✅ KPI Dashboard

### ✅ Phase 2: Data Reuse & Multi-Framework (Abgeschlossen)
- ✅ Business Continuity Management (BCM)
- ✅ Multi-Framework Compliance (ISO 27001, TISAX, DORA)
- ✅ Cross-Framework Mappings & Transitive Compliance
- ✅ Vollständige Entity-Beziehungen
- ✅ Automatische KPIs
- ✅ Progressive Disclosure UI
- ✅ Symfony UX Integration (Stimulus, Turbo)

### ✅ Phase 3: User Management & Security (Abgeschlossen)
- ✅ Multi-Provider Authentication (Local, Azure OAuth/SAML)
- ✅ RBAC mit 5 System-Rollen & 29 Permissions
- ✅ Automatisches Audit Logging
- ✅ Multi-Language Support (DE, EN)
- ✅ User Management UI

### ✅ Phase 4: CRUD & Workflows (Abgeschlossen)
- ✅ Vollständige CRUD für alle Module
- ✅ 5 Form Types mit Validierung
- ✅ Workflow-Engine (Approval, Rejection, Cancellation)
- ✅ Risk Assessment Matrix (5x5 Visualisierung)
- ✅ 30+ Professional Templates

### ✅ Phase 5: Reporting & Integration (Abgeschlossen)
- ✅ PDF/Excel Export System (5 Report-Typen)
- ✅ REST API (30 Endpoints, OpenAPI 3.0)
- ✅ Automated Notification Scheduler (5 Typen)
- ✅ Premium Features (Dark Mode, Global Search, Quick View)
- ⏸️ Document Management (Foundation, deferred)

### 🚧 Phase 6: Module Completeness & Quality Assurance (In Entwicklung)

**Status:** Umfassendes Audit durchgeführt am 2025-11-08 (Technisch + ISO 27001 + Multi-Standard)
- **Technische Vollständigkeit:** ~70% (Lücken: Form Types, Tests, Workflows)
- **ISO 27001:2022 Compliance:** 94.5% ✅
- **Multi-Standard Compliance:** 92% Durchschnitt ✅
  - ISO 22301:2019 (BCM): 100% ✅
  - ISO 19011:2018 (Audit): 95% ⚠️
  - ISO 31000:2018 (Risk): 95% ⚠️
  - ISO 27005:2022 (Risk Security): 100% ✅
  - EU DORA: 85% ⚠️
  - TISAX/VDA ISA: 75% ⚠️
- **Zertifizierungsbereitschaft:** JA (mit Minor Findings in Asset Management)
- **Kritische Lücken identifiziert:**
  - *Technisch:* 8 fehlende Form Types, 70% Module ohne Tests
  - *Inhaltlich ISO 27001:* Asset Management (Acceptable Use, Return Workflow, Data Classification)
  - *Multi-Standard:* Auditor Competence (ISO 19011), Risk Communication Log (ISO 31000), TPP Register (DORA), TISAX AL-Tracking
- **Siehe:** [MODULE_COMPLETENESS_AUDIT.md](docs/MODULE_COMPLETENESS_AUDIT.md)

#### 🔥 Phase 6A: Form Types (Priorität KRITISCH)
- 🔄 ISMSObjectiveType (Controller existiert bereits)
- 🔄 WorkflowType, WorkflowInstanceType
- 🔄 ComplianceFrameworkType, ComplianceRequirementType, ComplianceMappingType
- 📋 **Aufwand:** 1-2 Tage | **Impact:** Hoch

#### 🧪 Phase 6B: Test Coverage (Priorität KRITISCH)
- 🔄 Entity Tests für 17 Module ohne Tests
- 🔄 Controller Tests für kritische Module
- 🔄 Service Tests für Business Logic
- 🔄 Ziel: Test Coverage von 26% auf 80%+
- 📋 **Aufwand:** 3-4 Tage | **Impact:** Sehr hoch

#### 🔧 Phase 6C: Workflow-Management (Priorität WICHTIG)
- 🔄 Workflow CRUD vervollständigen (aktuell nur 35%)
- 🔄 WorkflowInstance CRUD vervollständigen (aktuell nur 30%)
- 🔄 Templates erstellen (6+ neue Templates)
- 🔄 Tests implementieren
- 📋 **Aufwand:** 2-3 Tage | **Impact:** Hoch

#### 📊 Phase 6D: Compliance-Detail-Management (Priorität WICHTIG)
- 🔄 ComplianceFrameworkController (dediziert, vollständiges CRUD)
- 🔄 ComplianceRequirementController (dediziert, vollständiges CRUD)
- 🔄 ComplianceMappingController (dediziert, vollständiges CRUD)
- 🔄 Templates erstellen (12+ neue Templates)
- 📋 **Aufwand:** 2-3 Tage | **Impact:** Mittel

#### ✨ Phase 6E: Polish & Optimization (Priorität OPTIONAL)
- 📅 Code-Review und Refactoring
- 📅 Dokumentation vervollständigen
- 📅 UX-Verbesserungen
- 📋 **Aufwand:** 1-2 Tage | **Impact:** Niedrig

#### 🏛️ Phase 6F: ISO 27001 Inhaltliche Vervollständigung (Priorität HOCH)
- 🔄 **Asset Management vervollständigen** (KRITISCH für Zertifizierung)
  - Acceptable Use Policy Field
  - Monetary Value
  - Handling Instructions
  - Data Classification (public/internal/confidential/restricted)
  - Asset Return Workflow
- 🔄 **Risk Management vervollständigen**
  - Risk Owner als User-Referenz
  - Risk Appetite Entity
  - Risk Treatment Plan Entity
- 🔄 **Statement of Applicability Report**
  - SoA PDF Generator Service
  - Professional SoA Template
- 📋 **Aufwand:** 2-3 Tage | **Impact:** KRITISCH

#### 🌐 Phase 6G: Multi-Standard Compliance Vervollständigung (Priorität MITTEL)
- 🔄 **Audit Management Erweiterung (ISO 19011)**
  - AuditorCompetence Entity (Auditor-Qualifikationsverwaltung)
  - Competence Level Tracking (junior/senior/lead)
  - Training-Integration
- 🔄 **Risk Communication Log (ISO 31000)**
  - RiskCommunication Entity
  - Stakeholder Engagement Tracking
  - Communication Type Management
- 🔄 **DORA Compliance (nur für Financial Entities)**
  - ICTThirdPartyProvider Entity (TPP Register)
  - TLPTExercise Entity (Threat-Led Penetration Testing)
  - Critical/Important Provider Classification
- 🔄 **TISAX Compliance (nur für Automotive Industry)**
  - Asset.php Erweiterung (AL1/AL2/AL3, Protection Need, Prototype Fields)
  - TISAXAssessment Entity
  - Maturity Level Tracking
- 📋 **Aufwand:** 3-4 Tage | **Impact:** MITTEL (branchenspezifisch)

**Gesamt-Aufwand Phase 6 (A-G):** 16-24 Tage
**Erwartete Vollständigkeit nach Phase 6:**
- **Technisch:** ~95%
- **ISO 27001 Inhaltlich:** 98%+
- **Multi-Standard Compliance:** 98%+ (branchenabhängig)
- **Zertifizierungsbereitschaft:** 100% ✅ (ISO 27001, ISO 22301, ISO 19011, TISAX AL1)

---

### 🚀 Phase 7: Enterprise Features (Geplant)
- ✅ Automated Testing (122 tests, 100% passing)
- ✅ CI/CD Pipeline (GitHub Actions)
- ✅ Docker Deployment
- 🔄 Multi-Tenancy Support (MSPs)
- 🔄 Advanced Analytics Dashboards
- 🔄 Mobile PWA
- 📅 Kubernetes Deployment

### 📅 Zukünftige Phasen
- JWT Authentication für Mobile Apps
- Real-time Notifications (WebSocket/Mercure)
- Advanced API Filters & Search
- Custom Report Builder
- Integration Marketplace (Slack, Teams, JIRA)

**Legende:** ✅ Abgeschlossen | 🚧 In Entwicklung | 🔄 Geplant | 📅 Backlog | ⏸️ Deferred

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
php bin/phpunit

# Commit & Push
git commit -m "feat(module): add awesome feature"
git push origin feature/your-feature

# Pull Request erstellen
```

Siehe auch: [CHANGELOG.md](CHANGELOG.md) für detaillierte Versionshistorie

---

## 📊 Projekt-Statistiken

- **Codezeilen:** ~31,650+ LOC
- **Entities:** 23 Doctrine Entities
- **Controllers:** 18+ Controllers
- **Templates:** 80+ Twig Templates
- **Services:** 12+ Business Logic Services
- **Commands:** 5+ Console Commands
- **Tests:** 122 tests, 228 assertions (100% passing)
  - **Test Coverage:** ~26% (Ziel: 80%+)
  - **Module mit Tests:** 6/23 (26%)
- **API Endpoints:** 30 REST Endpoints
- **Report Types:** 10 (5 PDF + 5 Excel)
- **Notification Types:** 5 automatisierte Typen
- **Compliance Frameworks:** 6 (ISO 27001, ISO 22301, ISO 19011, ISO 31000, DORA, TISAX)
- **Module Vollständigkeit (Technisch):** ~70% durchschnittlich (siehe [Audit](docs/MODULE_COMPLETENESS_AUDIT.md))
- **ISO 27001:2022 Compliance:** 94.5% ✅ (Zertifizierungsbereit)
- **Multi-Standard Compliance:** 92% Durchschnitt ✅
  - ISO 22301:2019 (BCM): 100% ✅
  - ISO 19011:2018 (Audit): 95% ⚠️
  - ISO 31000:2018 (Risk): 95% ⚠️
  - ISO 27005:2022 (Risk Security): 100% ✅
  - EU DORA: 85% ⚠️
  - TISAX/VDA ISA: 75% ⚠️

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

## 📜 Lizenz

**Proprietary** - Alle Rechte vorbehalten

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
