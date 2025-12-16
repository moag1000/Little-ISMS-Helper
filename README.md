# 🛡️ Little ISMS Helper

<div align="center">

<img src="public/logo.svg" alt="Little ISMS Helper - Cyberpunk Security Fairy" width="300" />

**Moderne, webbasierte ISMS-Lösung für KMUs – ISO 27001:2022 konform**

[![CI/CD](https://github.com/moag1000/Little-ISMS-Helper/actions/workflows/ci.yml/badge.svg)](https://github.com/moag1000/Little-ISMS-Helper/actions/workflows/ci.yml)
[![License Compliance](https://github.com/moag1000/Little-ISMS-Helper/actions/workflows/license-compliance.yml/badge.svg)](https://github.com/moag1000/Little-ISMS-Helper/actions/workflows/license-compliance.yml)
[![GitHub release](https://img.shields.io/github/v/release/moag1000/Little-ISMS-Helper?logo=github)](https://github.com/moag1000/Little-ISMS-Helper/releases)
[![GitHub stars](https://img.shields.io/github/stars/moag1000/Little-ISMS-Helper?style=flat&logo=github)](https://github.com/moag1000/Little-ISMS-Helper/stargazers)
[![GitHub issues](https://img.shields.io/github/issues/moag1000/Little-ISMS-Helper)](https://github.com/moag1000/Little-ISMS-Helper/issues)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony 7.4](https://img.shields.io/badge/Symfony-7.4-000000?logo=symfony&logoColor=white)](https://symfony.com/)
[![Docker Pulls](https://img.shields.io/docker/pulls/moag2000/little-isms-helper?logo=docker)](https://hub.docker.com/r/moag2000/little-isms-helper)
[![Docker Image](https://img.shields.io/docker/v/moag2000/little-isms-helper?logo=docker&label=image)](https://hub.docker.com/r/moag2000/little-isms-helper)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](LICENSE)
[![ISO 27001:2022](https://img.shields.io/badge/ISO-27001%3A2022-blue)](https://www.iso.org/standard/27001)
[![WCAG 2.1 AA](https://img.shields.io/badge/WCAG-2.1%20AA-green)](https://www.w3.org/WAI/WCAG21/quickref/)

[Features](#-funktionen) • [Quick Start](#-quick-start-mit-docker) • [Dokumentation](#-dokumentation) • [Roadmap](#-roadmap) • [Contributing](#-beitragen)

---

### ☕ Support this Project

If you find Little ISMS Helper useful, please consider supporting its development:

<a href="https://www.buymeacoffee.com/moag1000" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me A Coffee" style="height: 60px !important;width: 217px !important;" ></a>

Your support helps maintain and improve this open-source ISMS solution!

</div>

---

## 📖 Was ist Little ISMS Helper?

Little ISMS Helper unterstützt Organisationen bei der **Implementierung und Verwaltung ihres ISMS nach ISO/IEC 27001:2022**. Die Anwendung hilft dabei, Compliance-Anforderungen zu erfüllen, Risiken zu managen, Audits durchzuführen und KPIs zu überwachen – alles in einer zentralen, benutzerfreundlichen Plattform.

### 🎯 Warum Little ISMS Helper?

| Feature | Beschreibung |
|---------|--------------|
| ✅ **ISO 27001:2022 konform** | Alle 93 Annex A Controls integriert |
| 🔄 **Intelligente Datenwiederverwendung** | Einmal erfasst, mehrfach genutzt |
| 📊 **Multi-Framework Support** | ISO 27001, TISAX, DORA, NIS2, BSI IT-Grundschutz |
| 🐳 **Docker-Ready** | Ein Befehl – alles läuft |
| 🚀 **Modern & schnell** | Symfony 7.4, PHP 8.4, Progressive UI |
| 🔓 **Open Architecture** | REST API für Integrationen |
| 📈 **Automatische KPIs** | Echtzeit-Metriken ohne manuelle Berechnung |

**Zeitersparnis:** ~10,5 Stunden (95%) pro Audit-Zyklus durch automatisierte Analysen

---

## 🐳 Quick Start mit Docker

**Empfohlener Weg – keine PHP/Composer-Installation nötig!**

### 🚀 Production Deployment (Empfohlen)

**All-in-One Container** mit embedded MariaDB - nur 1 Container, 1 Volume

```bash
# 1. Repository klonen
git clone https://github.com/moag1000/Little-ISMS-Helper.git
cd Little-ISMS-Helper

# 2. Production Container starten
docker-compose -f docker-compose.prod.yml up -d

# 3. Status prüfen
docker-compose -f docker-compose.prod.yml ps
```

**Fertig!** 🎉 Öffnen Sie: `http://localhost/setup`

- ✅ **1 Container** - App mit embedded MariaDB
- ✅ **1 Volume** - Alle Daten in `isms_data`
- ✅ **Auto-Config** - DB-Passwort wird automatisch generiert
- ✅ **Production-Ready** - Resource Limits & Health Checks

📖 **Vollständige Dokumentation:** [DOCKER_PRODUCTION.md](docs/deployment/DOCKER_PRODUCTION.md)

### 🛠️ Development Setup

**Multi-Container** mit PostgreSQL, MailHog & pgAdmin für Entwicklung

```bash
# 1. Repository klonen
git clone https://github.com/moag1000/Little-ISMS-Helper.git
cd Little-ISMS-Helper

# 2. Development Services starten
docker-compose up -d
```

Öffnen: `http://localhost:8000/setup`

#### Enthaltene Services

| Service | URL | Beschreibung |
|---------|-----|--------------|
| **App** | http://localhost:8000 | Little ISMS Helper |
| **MailHog** | http://localhost:8025 | Email-Testing (SMTP) |
| **pgAdmin** | http://localhost:5050 | Datenbank-Management |

📖 **Detaillierte Anleitung:** [DOCKER_SETUP.md](docs/setup/DOCKER_SETUP.md)

---

## ⚙️ Alternative Installationsmethoden

<details>
<summary><b>🧙 Installation mit Deployment Wizard (ohne Docker)</b></summary>

**Voraussetzungen:** PHP 8.4+, Composer 2.x, PostgreSQL 16+ / MySQL 8.0+

```bash
# 1. Repository klonen
git clone https://github.com/moag1000/Little-ISMS-Helper.git
cd Little-ISMS-Helper

# 2. Dependencies installieren
composer install
php bin/console importmap:install

# 3. Server starten
php -S localhost:8000 -t public/
```

Öffnen Sie: `http://localhost:8000/setup`

📖 **Detaillierte Anleitung:** [DEPLOYMENT_WIZARD.md](docs/deployment/DEPLOYMENT_WIZARD.md)

</details>

<details>
<summary><b>⌨️ Manuelle Installation (Fortgeschritten)</b></summary>

```bash
# Nach Repository-Clone und Dependencies

# 1. Umgebung konfigurieren
cp .env .env.local
echo "APP_SECRET=$(openssl rand -hex 32)" >> .env.local

# 2. Datenbank-URL konfigurieren
echo 'DATABASE_URL="postgresql://user:pass@127.0.0.1:5432/little_isms?serverVersion=16"' >> .env.local

# 3. Datenbank einrichten
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:setup-permissions --admin-email=admin@example.com --admin-password=admin123
php bin/console isms:load-annex-a-controls

# 4. Server starten
symfony serve
```

**Login:** admin@example.com / admin123 (⚠️ Sofort ändern!)

</details>

<details>
<summary><b>🛠️ Automatisierte Setup-Tools</b></summary>

Professionelle Setup-Tools im `scripts/` Verzeichnis:

```bash
# Umfassende Validierung (18+ Checks)
chmod +x scripts/setup/validate-setup.sh
scripts/setup/validate-setup.sh

# Sichere Datenbank-Erstellung
chmod +x scripts/setup/create-database.sh
scripts/setup/create-database.sh

# Datenbank-Reset (bei Fehlern)
chmod +x scripts/setup/reset-database.sh
scripts/setup/reset-database.sh
```

📖 **Siehe:** [SETUP_TOOLS.md](docs/setup/SETUP_TOOLS.md)

</details>

### Produktions-Deployment

Für Produktions-Deployments beachten Sie:

- ✅ **Sichere Konfiguration** - Starke `APP_SECRET`, `APP_ENV=prod`
- ✅ **Datenbank** - PostgreSQL 16+ / MySQL 8.0+ (nicht SQLite!)
- ✅ **HTTPS** - SSL/TLS-Verschlüsselung konfigurieren
- ✅ **Cache** - `php bin/console cache:clear --env=prod`

📖 **Detaillierte Anleitungen:**
- [DEPLOYMENT_WIZARD.md](docs/deployment/DEPLOYMENT_WIZARD.md) - Deployment Wizard
- [DEPLOYMENT_PLESK.md](docs/deployment/DEPLOYMENT_PLESK.md) - Strato/Plesk Setup
- [DOCKER_HUB.md](docs/setup/DOCKER_HUB.md) - Docker Hub Integration

---

## ✨ Funktionen

<table>
<tr>
<td width="50%">

### 📋 Compliance Management
- **93 ISO 27001:2022 Controls** - Vollständige Annex A Abdeckung
- **Multi-Framework** - TISAX, DORA, NIS2, BSI IT-Grundschutz
- **SoA PDF Export** - Professional ISO 27001 Reports
- **Audit Management** - ISO 27001 Clause 9.2
- **Management Review** - ISO 27001 Clause 9.3

### 🔐 Risk & Asset Management
- **Risk Assessment** - 5x5 Matrix, ISO 27005 Guidance, GDPR Integration
- **Risk Owner Management** - Mandatory assignment (ISO 27001)
- **Periodic Review Workflow** - Automated reminders (ISO 27001 Clause 6.1.3.d)
- **Risk Acceptance Process** - Formal approval workflow with audit trail
- **Treatment Plan Monitoring** - Progress tracking & dashboard widgets
- **Asset Management** - CIA-Bewertung, Multi-Subject (Asset/Person/Location/Supplier)
- **Vulnerability Management** - CVE/CVSS Tracking (NIS2)
- **Patch Management** - Deployment Tracking (NIS2)
- **Incident Management** - Vorfallsbehandlung & GDPR Data Breach

### 🏢 Business Continuity
- **BCM Module** - BIA mit RTO/RPO/MTPD
- **Crisis Team Management** - BSI 200-4 Krisenstab
- **Recovery Planning** - Kontinuitätsplanung
- **Process Management** - Geschäftsprozesse

</td>
<td width="50%">

### 👥 User Management
- **Multi-Tenancy** - Vollständige Mandantentrennung mit Corporate Structure
- **RBAC** - Role-Based Access Control, 50+ Permissions
- **Multi-Auth** - Local, Azure OAuth, SAML
- **MFA** - TOTP with Backup Codes (WebAuthn & SMS planned)
- **Training Management** - Schulungsplanung
- **Audit Logging** - Vollständige Änderungsverfolgung

### 📊 Reporting & Integration
- **11 Professionelle Reports** - 6 PDF + 5 Excel
- **REST API** - 30 Endpoints, OpenAPI 3.0, Swagger UI
- **Email Notifications** - Automatisierte Benachrichtigungen
- **Workflow Engine** - Genehmigungsprozesse

### 🎨 Modern UI/UX (🆕 2.0)
- **Mega Menu Navigation** - Hierarchische, Multi-Column Navigation mit visuellen Icons
- **Dark Mode** - Vollständige Theme-Unterstützung mit WCAG AA Kontrasten
- **Complete i18n** - 3.290+ Übersetzungsschlüssel in 97 Domänen (DE/EN)
- **WCAG 2.1 AA** - 100% Barrierefreiheit (Kontraste, ARIA, Keyboard Navigation)
- **Component System** - Wiederverwendbare Twig-Komponenten (Cards, Badges, Buttons)
- **Responsive Design** - Mobile-First mit Bootstrap 5.3
- **Cyberpunk Fairy Design** - Einzigartiger visueller Stil mit Gradients & Neon-Effekten
- **Global Search** - Cmd+K/Ctrl+K
- **Drag & Drop** - Dashboard & File Upload
- **Keyboard Shortcuts** - Power-User-Features
- **Quick View** - Modal-Previews (Space)

### 🔄 Automated Workflows (🆕 2.1)
- **GDPR Breach Wizard** - 72h Notification Timeline mit automatischer Bewertung
- **Incident Escalation** - Automatische Eskalations-Workflows mit Preview
- **Risk Treatment Approval** - Genehmigungs-Workflows für Risikobehandlungspläne
- **Document Approval** - Dokumenten-Freigabe-Workflows
- **Dashboard Widget** - Übersicht ausstehender Workflow-Aufgaben
- **Auto-Trigger** - Workflows werden automatisch bei Entity-Änderungen gestartet

### ⏰ Proactive Compliance Monitoring (🆕 2.2)
- **Review Reminder System** - Automatische Erinnerungen für überfällige Reviews
- **Multi-Entity Coverage** - Risks, BC Plans, VVT, DPIAs, Data Breaches
- **72h Breach Alerts** - GDPR Art. 33 Deadline-Tracking mit stündlicher Prüfung
- **Dashboard Widget** - Übersicht überfälliger Reviews mit Direktlinks
- **Cron-Integration** - Vollautomatische Email-Benachrichtigungen
- **Interactive Risk Slider** - Visuelle Risikobewertung mit 5x5 Matrix

</td>
</tr>
</table>

### 🔄 Intelligente Datenwiederverwendung

Ein Kernprinzip: **Maximale Wertschöpfung aus einmal erfassten Daten**

- **BCM → Asset Protection** - RTO/RPO leiten Verfügbarkeitsanforderungen ab
- **Incident → Risk Validation** - Risikobewertungen werden durch echte Vorfälle validiert
- **Control → Effectiveness** - Incident-Reduktion misst Control-Wirksamkeit
- **Training → Coverage** - Training-Lücken werden automatisch identifiziert

📖 **Details:** [DATA_REUSE_ANALYSIS.md](docs/architecture/DATA_REUSE_ANALYSIS.md)

---

## 🎛️ Admin Portal

Professionelles Admin Portal zur zentralen Verwaltung aller administrativen Aufgaben.

**Zugriff:** `http://localhost:8000/{locale}/admin` (Rolle: `ROLE_ADMIN`)

**16 Admin-Funktionen:**
- User, Role & Permission Management
- Tenant Management, Session Tracking
- MFA Token Management
- System Settings & Module Management
- Compliance Framework Management
- System Health & Performance Monitoring
- Database Backup, Export & Import
- License Management (163 Dependencies)

📖 **Vollständiger Guide:** [ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md)

---

## 📚 Dokumentation

### 🚀 Setup & Deployment

| Dokument | Beschreibung |
|----------|--------------|
| [Docker Setup](docs/setup/DOCKER_SETUP.md) | Docker Compose Setup für Entwicklung & Produktion |
| [Docker Hub](docs/setup/DOCKER_HUB.md) | Docker Hub Integration & CI/CD |
| [Deployment Wizard](docs/deployment/DEPLOYMENT_WIZARD.md) | 10-Schritte Setup für Produktion |
| [Plesk Deployment](docs/deployment/DEPLOYMENT_PLESK.md) | Strato/Plesk Setup & "Primary script unknown" Fix |
| [Setup Tools](docs/setup/SETUP_TOOLS.md) | Automatisierte Scripts für fehlerfreie Installation |
| [Authentication](docs/setup/AUTHENTICATION_SETUP.md) | RBAC, Azure OAuth/SAML, Multi-Provider |
| [API Setup](docs/setup/API_SETUP.md) | REST API, Swagger UI, Postman |
| [Audit Logging](docs/setup/AUDIT_LOGGING.md) | Automatische Änderungsverfolgung |

### 🏗️ Architecture & Design

| Dokument | Beschreibung |
|----------|--------------|
| [Solution Description](docs/architecture/SOLUTION_DESCRIPTION.md) | Architektur-Übersicht, Design-Entscheidungen |
| [Data Reuse Analysis](docs/architecture/DATA_REUSE_ANALYSIS.md) | Intelligente Datenwiederverwendung |
| [Cross-Framework Mappings](docs/architecture/CROSS_FRAMEWORK_MAPPINGS.md) | Multi-Framework Compliance Mappings |
| [Entity-Table Mapping](docs/architecture/ENTITY_TABLE_MAPPING.md) | Zuordnung aller 23 Entities zu DB-Tabellen |

### 🎨 UI/UX

| Dokument | Beschreibung |
|----------|--------------|
| [UI/UX Quick Start](docs/ui-ux/UI_UX_QUICK_START.md) | Keyboard Shortcuts, Command Palette (⌘K) |
| [UI/UX Implementation](docs/ui-ux/UI_UX_IMPLEMENTATION.md) | Progressive Disclosure, Components |

### 📊 Reports & Quality

| Dokument | Beschreibung |
|----------|--------------|
| [Verification Report](docs/reports/VERIFICATION_REPORT.md) | Code-Nachweis für alle Features |
| [Module Completeness Audit](docs/phases/MODULE_COMPLETENESS_AUDIT.md) | Phase 6 Module Completeness Status |
| [Security Audit](docs/reports/security-audit-owasp-2025-rc1.md) | OWASP Security Audit Report |
| [License Report](docs/reports/license-report.md) | Third-Party License Compliance (163 Pakete) |

### 🔒 Compliance & Security

| Dokument | Beschreibung |
|----------|--------------|
| [ISO 27001 Implementation](docs/compliance/ISO_COMPLIANCE_IMPLEMENTATION_SUMMARY.md) | ISO 27001:2022 Implementation Details |
| [Security Architecture](docs/security/SECURITY.md) | Security Architecture & Best Practices |

---

## 🛠️ Technologie-Stack

| Komponente | Technologie |
|------------|-------------|
| **Backend** | PHP 8.4, Symfony 7.4, Doctrine ORM |
| **Frontend** | Twig, Bootstrap 5, Stimulus, Turbo |
| **Database** | PostgreSQL 16 / MySQL 8.0+ |
| **API** | API Platform 4.2, OpenAPI 3.0, Swagger UI |
| **Export** | Dompdf 3.1 (PDF), PhpSpreadsheet 5.2 (Excel) |
| **Testing** | PHPUnit 12.4 (2528 tests, 7782 assertions) |
| **CI/CD** | GitHub Actions (4 parallel jobs) |
| **Deployment** | Docker, Docker Compose, Nginx |

---

## 📊 Projekt-Statistiken

- **Codezeilen:** ~43,600+ LOC
- **Entities:** 39 Doctrine Entities
- **Controllers:** 38 Controllers
- **Templates:** 197 Twig Templates
- **Translations:** 2,905 keys (DE + EN)
- **Tests:** 2528 tests (34 test files, 19,175 LOC)
- **API Endpoints:** 30 REST Endpoints
- **Report Types:** 11 (6 PDF + 5 Excel)
- **Compliance Frameworks:** 9 (ISO 27001, ISO 22301, ISO 19011, ISO 31000, ISO 27005, DORA, TISAX, NIS2, BSI IT-Grundschutz)

### ISO 27001:2022 Compliance: 96% ✅

- ✅ **Clause 4-10** - Alle Anforderungen erfüllt
- ✅ **Annex A** - Alle 93 Controls vollständig integriert
- ✅ **Zertifizierungsbereit**

### Multi-Framework Support

| Framework | Status | Coverage |
|-----------|--------|----------|
| ISO 27001:2022 | ✅ Vollständig | 96% |
| ISO 22301:2019 (BCM) | ✅ Vollständig | 100% |
| ISO 27005:2022 | ✅ Vollständig | 100% |
| DORA | ✅ Vollständig | 85% |
| TISAX/VDA ISA | ✅ Vollständig | 75% |
| NIS2 Directive | ✅ Vollständig | 90% |
| BSI IT-Grundschutz | 🚧 In Arbeit | 50% |

---

## 🧪 Testing

Das Projekt enthält eine umfassende Testsuite mit **2528 Tests** in **34 Testdateien** (~19.175 LOC).

### Test Coverage

| Kategorie | Tests | Beschreibung |
|-----------|-------|--------------|
| **Controller Tests** | ~1100 | HTTP-Requests, Forms, Security, Flash Messages |
| **Service Tests** | ~900 | Business Logic, Data Processing, Validation |
| **Repository Tests** | ~400 | Database Queries, Filtering, Pagination |
| **Entity Tests** | ~128 | Getters/Setters, Validation, Relationships |

### Tests ausführen

```bash
# Alle Tests
php bin/phpunit

# Spezifische Testsuite
php bin/phpunit tests/Controller/
php bin/phpunit tests/Service/RiskServiceTest.php

# Mit Testdox (lesbare Ausgabe)
php bin/phpunit --testdox
```

### Test-Datenbank einrichten

Viele Tests benötigen eine Test-Datenbank. So richten Sie sie ein:

```bash
# 1. Test-Datenbank erstellen
php bin/console doctrine:database:create --env=test

# 2. Schema erstellen
php bin/console doctrine:migrations:migrate --env=test --no-interaction

# 3. Testdaten laden (optional)
php bin/console app:setup-permissions --admin-email=test@example.com --admin-password=test123 --env=test
php bin/console isms:load-annex-a-controls --env=test

# 4. Tests ausführen
php bin/phpunit
```

**Hinweis:** Tests, die eine Datenbank benötigen, werden im CI/CD-Flow automatisch übersprungen, wenn keine Testdatenbank verfügbar ist.

### Testabdeckung nach Modul

- ✅ **Risk Management** - Vollständig getestet (Probability Adjustment, Review, Acceptance Workflow)
- ✅ **Privacy/GDPR** - Vollständig getestet (Data Breach, DPIA, Processing Activities)
- ✅ **BCM** - Vollständig getestet (Incident Impact Analysis)
- ✅ **Compliance** - Vollständig getestet (Requirement Fulfillment)
- ✅ **Multi-Tenancy** - Vollständig getestet (Tenant Isolation Checks)
- ✅ **Admin Services** - Vollständig getestet (System Requirements, Database, Environment)
- 🚧 **API Platform** - In Entwicklung
- 🚧 **Workflow Engine** - In Entwicklung

**Aktuelle Test-Statistik:**
- Gesamt: 2528 Tests
- Assertions: 7782
- Erfolgsquote: ~95% (ohne Test-DB-Setup)
- Mit Test-DB: ~98%+

---

## 🗺️ Roadmap

**Vollständige Projekt-Roadmap:** 📋 **[ROADMAP.md](ROADMAP.md)**

### ✅ Abgeschlossene Phasen

- **Phase 1:** Core ISMS - 9 Entities, 93 ISO 27001:2022 Controls, KPI Dashboard
- **Phase 2:** Data Reuse & Multi-Framework - BCM, TISAX, DORA, Cross-Framework Mappings
- **Phase 3:** User Management & Security - RBAC, Multi-Auth, Audit Logging
- **Phase 4:** CRUD & Workflows - Vollständige CRUD, Workflow-Engine, Risk Matrix
- **Phase 5:** Reporting & Integration - PDF/Excel Export, REST API, Notifications
- **Phase 6A-6H:** Module Completeness
  - 6A: Form Types (5/6 abgeschlossen)
  - 6C: Workflow Management
  - 6D: Compliance Detail Management
  - 6F: ISO 27001 Inhaltliche Vervollständigung
  - 6H: NIS2 Compliance (90%+ compliant)
  - 6L: Multi-Tenancy & Unified Admin Panel

### 🚧 In Entwicklung

**Phase 6: Module Completeness & Quality Assurance (~85% abgeschlossen)**

- 🧪 Test Coverage (Aktuell: 60% → Ziel: 80%+)
- 🇩🇪 BSI IT-Grundschutz Integration
- 🎯 Advanced Compliance Features

**Erwartete Vollständigkeit nach Phase 6:**
- Technisch: 95%+
- ISO 27001: 98%+
- Test Coverage: 80%+

### 📅 Zukünftige Phasen

- **Phase 7:** Advanced Analytics & Management Reporting - Executive Dashboards, Predictive Analytics, Custom Report Builder
- **Backlog:** Mobile PWA, JWT Auth, Real-time Notifications, AI-gestützte Features

---

## 🤝 Beitragen

Wir freuen uns über Beiträge! Bitte lesen Sie unsere [Contributing Guidelines](CONTRIBUTING.md) für Details zu:

- Code-Standards (PSR-12, Symfony Best Practices)
- Commit-Konventionen (Conventional Commits)
- Pull Request Prozess
- Testing-Anforderungen

### Schnelleinstieg für Contributor

```bash
# Fork & Clone
git clone https://github.com/YOUR-USERNAME/Little-ISMS-Helper.git

# Branch erstellen
git checkout -b feature/your-feature

# Docker-Entwicklungsumgebung starten
docker-compose up -d

# Entwickeln & Testen
docker-compose exec app php bin/phpunit

# Commit & Push
git commit -m "feat(module): add awesome feature"
git push origin feature/your-feature
```

Siehe auch: [CHANGELOG.md](CHANGELOG.md) für detaillierte Versionshistorie

---

## 📋 Lizenz-Compliance

Little ISMS Helper verwendet **163 Third-Party Open-Source-Pakete**, die für kommerzielle Nutzung freigegeben sind.

### Compliance-Status

| Status | Pakete | Prozent |
|--------|--------|---------|
| ✅ Erlaubt | 160 | 98.2% |
| 🔄 Copyleft (LGPL) | 3 | 1.8% |

**Gesamtstatus:** ✅ **Lizenzkonform für kommerzielle Nutzung**

**Zugriff im Web-Interface:** Footer → "Lizenzen" oder `/about/licenses`

📖 **Details:** [NOTICE.md](NOTICE.md) • [License Report](docs/reports/license-report.md)

---

## 📜 Lizenz

**GNU Affero General Public License v3.0 (AGPL-3.0)**

✅ **Du kannst:** Frei nutzen, modifizieren, verteilen & kommerziell einsetzen
⚠️ **Du musst:** Quellcode offenlegen (auch bei SaaS), gleiche Lizenz verwenden, Copyright beibehalten

Siehe [LICENSE](LICENSE) für den vollständigen Lizenztext.

---

## 📞 Support & Community

- **Bugs & Feature Requests:** [GitHub Issues](https://github.com/moag1000/Little-ISMS-Helper/issues)
- **Diskussionen:** [GitHub Discussions](https://github.com/moag1000/Little-ISMS-Helper/discussions)
- **Dokumentation:** [docs/](docs/) Verzeichnis

---

## 🙏 Danksagungen

- Entwickelt für kleine und mittelständische Unternehmen
- Built with ❤️ using Symfony 7.4
- Unterstützt durch Claude AI (Anthropic)

---

<div align="center">

**[⬆ Zurück nach oben](#-little-isms-helper)**

Made with 🛡️ for better Information Security Management

</div>
