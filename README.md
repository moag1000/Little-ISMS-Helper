# 🛡️ Little ISMS Helper

<div align="center">

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
# Bearbeiten Sie .env.local mit Ihrer Datenbank-URL

# 4. Datenbank setup
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# 5. ISO 27001 Controls laden
php bin/console isms:load-annex-a-controls

# 6. Server starten
symfony serve
# oder: php -S localhost:8000 -t public/
```

**Fertig!** 🎉 Öffnen Sie http://localhost:8000

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

### 🚧 Phase 6: Enterprise Features (In Entwicklung)
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
- **Entities:** 20+ Doctrine Entities
- **Controllers:** 15+ Controllers
- **Templates:** 80+ Twig Templates
- **Services:** 12+ Business Logic Services
- **Commands:** 5+ Console Commands
- **Tests:** 122 tests, 228 assertions (100% passing)
- **API Endpoints:** 30 REST Endpoints
- **Report Types:** 10 (5 PDF + 5 Excel)
- **Notification Types:** 5 automatisierte Typen
- **Compliance Frameworks:** 3 (ISO 27001, TISAX, DORA)

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
