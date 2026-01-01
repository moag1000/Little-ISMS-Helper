# 🗺️ Little ISMS Helper - Roadmap

Dieses Dokument enthält die vollständige Projekt-Roadmap mit allen Phasen, Meilensteinen und geplanten Features.

**Status-Legende:** ✅ Abgeschlossen | 🚧 In Entwicklung | 🔄 Geplant | 📅 Backlog

---

## ✅ Phase 1: Core ISMS (Abgeschlossen)
- 9 Core Entities, SoA mit 93 ISO 27001:2022 Controls, KPI Dashboard

## ✅ Phase 2: Data Reuse & Multi-Framework (Abgeschlossen)
- BCM, Multi-Framework Compliance (ISO 27001, TISAX, DORA), Cross-Framework Mappings

## ✅ Phase 3: User Management & Security (Abgeschlossen)
- Multi-Provider Auth (Local, Azure OAuth/SAML), RBAC mit 5 Rollen & 29 Permissions, Audit Logging

## ✅ Phase 4: CRUD & Workflows (Abgeschlossen)
- Vollständige CRUD für alle Module, Workflow-Engine, Risk Assessment Matrix (5x5)

## ✅ Phase 5: Reporting & Integration (Abgeschlossen)
- PDF/Excel Export (5 Report-Typen), REST API (30 Endpoints), Automated Notifications

---

## ✅ Phase 6: Module Completeness & Quality Assurance (Abgeschlossen)

**Zeitraum:** Abgeschlossen Dez 2025
**Status:** ✅ Abgeschlossen
**Audit:** [docs/phases/MODULE_COMPLETENESS_AUDIT.md](docs/phases/MODULE_COMPLETENESS_AUDIT.md)

### Überblick
Phase 6 konzentriert sich auf die Vervollständigung aller Module und die Sicherstellung der Zertifizierungsbereitschaft.

**Finaler Stand:**
- **Technische Vollständigkeit:** 95%+
- **Test Coverage:** ~65% (3652 Tests, 9607 Assertions)
- **Test Success Rate:** 100% (alle Tests bestehen)
- **Zertifizierungsbereitschaft:** ISO 27001: 96%, ISO 22301: 100%, **NIS2: 90%+** ✅

### ✅ Abgeschlossene Subphasen
- ✅ Phase 6A: Form Types (Komplett - alle Formulare auf _auto_form migriert)
- ✅ Phase 6C: Workflow-Management (inkl. Auto-Trigger & GDPR Breach Wizard)
- ✅ Phase 6D: Compliance-Detail-Management
- ✅ Phase 6F: ISO 27001 Inhaltliche Vervollständigung
- ✅ Phase 6H: NIS2 Compliance Completion (90%+ compliant)
- ✅ Phase 6L: Multi-Tenancy & Subsidiary Management
- ✅ Phase 6N: Automated Workflows (GDPR Breach, Incident Escalation, Approvals)
- ✅ Phase 6O: Proactive Compliance Monitoring (Review Reminders, 72h Breach Alerts, Risk Slider)

### ✅ Weitere abgeschlossene Arbeiten
- ✅ Phase 6B: Test Coverage (3652 Tests, 9607 Assertions, 100% Success Rate)
  - ✅ Umfangreiche Test-Suite
  - ✅ 100% Success Rate
  - ✅ Compliance-Tests für Multi-Framework Support
  - ✅ Workflow Service Tests (6 neue Test-Klassen)
- ✅ Phase 6K: Internationalisierung (i18n) Vervollständigung (~98% abgeschlossen)
  - ✅ Translation domain architecture (49 domains × 2 languages = 97 YAML files)
  - ✅ Translation quality checker script (HARDCODED_TEXT, INVALID_DOMAIN, NO_DOMAIN, UNTRANSLATED_ATTRIBUTE)
  - ✅ Fixed 100+ translation issues (56× 'audits'→'audit', 2× 'controls'→'control', etc.)
  - ✅ Added {% trans_default_domain %} to 14 templates
  - ✅ All templates now have proper translation domain configuration
  - ✅ 5 major templates fully internationalized (user import, data export, role compare, business process, compliance dashboard)
  - ✅ Fixed 21 hardcoded aria-label="Actions" across 20 templates
  - ✅ Created notifications.{de,en}.yaml translation files
- ✅ Phase 6M: Docker Production Hardening
  - ✅ Dockerfile Hadolint best practices applied
  - ✅ Composer version pinned (composer:2)
  - ✅ RUN instructions consolidated
  - ✅ Word splitting fixed
- ✅ Phase 6P: Welcome Page & UX Improvements (NEU)
  - ✅ Welcome page with hero section and branding
  - ✅ Active modules overview with live statistics
  - ✅ Urgent tasks panel (overdue reviews, treatment plans, workflows)
  - ✅ Quick actions for common tasks
  - ✅ User preference to skip welcome page
  - ✅ Full i18n support (DE/EN)

### ✅ Phase 6G: Advanced Compliance Features
- ✅ TISAX VDA ISA 6.x Extended Requirements Command
  - ✅ 12 TISAX Labels across 3 Modules
  - ✅ Confidentiality (Confidential, Strictly Confidential AL3)
  - ✅ Availability (High, Very High AL3)
  - ✅ Prototype Protection (Proto Parts, Proto Vehicles, Test Vehicles, Events & Shootings) - ALL AL3
  - ✅ Data Protection (Data AL2, Special Data AL3 for GDPR Art. 9)
- ✅ DORA TPP (Third-Party Provider) - bereits vorhanden in LoadDoraRequirementsCommand

### ✅ Phase 6I: BSI IT-Grundschutz Integration
- ✅ SupplementBsiGrundschutzRequirementsCommand mit 70+ zusätzlichen Anforderungen
  - ✅ ORP (Organisation und Personal): Identitäts- und Berechtigungsmanagement, Compliance
  - ✅ CON (Konzepte): Löschen/Vernichten, Software-Entwicklung, Webanwendungen
  - ✅ OPS (Betrieb): IT-Administration, Schadprogramme, Software-Tests, Telearbeit
  - ✅ APP (Anwendungen): Office, Verzeichnisdienste, AD DS, Webanwendungen, Datenbanken
  - ✅ SYS (IT-Systeme): Server, Virtualisierung, Clients, Windows, Smartphones, IoT
  - ✅ NET (Netze): Netzmanagement, WLAN, Router/Switches, Firewall, VPN, NAC
  - ✅ INF (Infrastruktur): Rechenzentrum, Serverraum, Arbeitsplätze, Verkabelung
  - ✅ IND (Industrielle IT): OT-Segmentierung, ICS, SPS, Fernwartung
  - ✅ DER (Detektion/Reaktion): Sicherheitsvorfälle, Forensik, Audits, Notfallmanagement

### 📅 Verschoben auf spätere Phasen
- 📅 Phase 6E: Datenbank-Konsistenz & Constraints → Phase 8
- 📅 Phase 6J: Performance Optimierung → Phase 8

---

## ✅ Phase 7: Advanced Analytics & Management Reporting (Abgeschlossen)

**Zeitraum:** Dez 2025
**Status:** ✅ Abgeschlossen
**Priorität:** HOCH (Management Requirements)

### Überblick
Phase 7 führt umfassende Management-Reporting-Funktionen und erweiterte Analytics-Dashboards ein, um Executives und Management fundierte Entscheidungen zu ermöglichen.

**Business Value:**
- ✅ Compliance mit Berichts-Anforderungen (ISO 27001 Annex A.5.7, NIS2 Art. 23)
- ✅ Management Visibility & Decision Support
- ✅ Audit-Ready Dokumentation
- ✅ Predictive Risk Management

---

### ✅ Phase 7A: Management Reporting System

**Priorität:** KRITISCH
**Status:** ✅ Abgeschlossen (Dez 2025)
**Estimated Effort:** 40-50 Stunden

#### Scope

**1. Risk Management Reports**
- **Executive Risk Dashboard**
  - Top 10 Critical Risks mit Treatment Status
  - Risk Appetite Tracking (Toleranz-Überschreitungen)
  - Risk Velocity (Neue vs. Geschlossene Risiken)
  - Residual Risk Trends (12-Monats-Entwicklung)
  - Risk Treatment Progress (% Completion)

- **Risk Register Report**
  - Vollständiges Risk Register (PDF/Excel)
  - Filterbar: Severity, Status, Asset, Owner
  - Risk Age Distribution
  - Treatment Effectiveness Analysis

- **Risk Trend Analysis**
  - Inherent vs. Residual Risk Development
  - Risk Distribution by Category
  - Monthly/Quarterly Risk Metrics

**2. BCM Management Reports**
- **BC Plan Status Report**
  - Critical Process Coverage (%)
  - BC Plans mit/ohne regelmäßige Tests
  - RTO/RPO Compliance Matrix
  - Gaps & Recommendations

- **BC Exercise Report**
  - Exercise Results (Success Rate %)
  - Identified Issues & Actions
  - Exercise Frequency Compliance
  - Historical Exercise Trends

- **Business Impact Analysis Report**
  - Critical Processes nach RTO/RPO
  - Dependencies & Single Points of Failure
  - Recovery Capability Assessment

**3. Audit Management Reports** ⚠️ **NEU**
- **Audit Status Report**
  - Completed vs. Planned Audits
  - Finding Categories & Severity
  - Corrective Action Status
  - Audit Schedule Compliance

- **Finding Tracker Report**
  - Open Findings by Priority
  - Overdue Corrective Actions
  - Finding Trends (Monthly)
  - Closure Rate Analytics

**4. Compliance Status Reports**
- **Framework Compliance Summary**
  - Multi-Framework Progress Overview
  - Control Implementation by Framework
  - Gap Analysis per Framework
  - Compliance Roadmap Timeline

- **Control Effectiveness Report**
  - Controls ohne Risk/Asset/Framework Assignment
  - Review Compliance (Überfällige Reviews)
  - Implementation Quality Score
  - Top Performing/Underperforming Controls

**5. Asset Management Reports**
- **Asset Risk Profile**
  - Assets by Criticality (CIA)
  - Asset-to-Risk Correlation
  - High-Risk Assets Dashboard
  - Asset Coverage Analysis

- **Asset Inventory Report**
  - Complete Asset Register
  - Asset Types Distribution
  - Owner Assignment Status
  - Lifecycle Status

#### Technical Requirements

**Report Formats:**
- ✅ Interactive HTML Preview
- ✅ PDF Export (Management-ready)
- ✅ Excel Export (Data Analysis)
- ✅ PowerPoint Export (Board Meetings)

**Features:**
- ✅ Date Range Selection (Custom, Last Quarter, Last Year)
- ✅ Role-Based Report Access
- ✅ Scheduled Auto-Reports (Monthly/Quarterly via E-Mail)
- ✅ Report Templates (Customizable)
- ✅ Corporate Branding (Logo, Colors)
- ✅ Audit Trail (Who generated which report when)

**Routes Structure:**
```
/reports/                          → Report Center Dashboard
/reports/risk/executive            → HTML Preview
/reports/risk/executive/pdf        → PDF Download
/reports/risk/register             → Risk Register
/reports/bcm/status                → BCM Status
/reports/bcm/exercises             → BC Exercise Report
/reports/audit/findings            → Audit Findings Tracker
/reports/compliance/frameworks     → Multi-Framework Overview
/reports/assets/risk-profile       → Asset Risk Profile
```

#### Deliverables
- [x] ManagementReportController (17 Endpoints für alle Report-Kategorien)
- [x] ManagementReportService (Zentrale Business Logic)
- [x] ScheduledReportService (Automatische Report-Generierung & E-Mail-Versand)
- [x] ScheduledReportController (CRUD, Toggle, Trigger, Preview)
- [x] ProcessScheduledReportsCommand (Console Command für Cron)
- [x] ScheduledReport Entity (mit manueller Aktivierung)
- [x] PdfExportService (Enhanced mit Management Reports)
- [x] Report Templates (15+ HTML/PDF Templates)
- [ ] PowerPointExportService (Optional - Backlog)
- [x] Report Access Control (ROLE_MANAGER erforderlich)
- [x] i18n Support (DE/EN Übersetzungen)

#### Acceptance Criteria
- [x] ✅ Alle 7 Report-Kategorien implementiert (Executive, Risk, BCM, Compliance, Audit, Assets, GDPR)
- [x] ✅ PDF/Excel Export funktioniert
- [x] ✅ Scheduled Reports per E-Mail (täglich/wöchentlich/monatlich)
- [x] ✅ Manuelle Aktivierung erforderlich (isActive = false per Default)
- [x] ✅ Role-Based Access Control (ROLE_MANAGER)
- [x] ✅ i18n (DE/EN) vollständig
- [x] ✅ Print-optimiertes Layout
- [ ] 🔄 PowerPoint Export (optional, Backlog)
- [ ] 🔄 Charts in PDF Reports (Backlog)

---

### ✅ Phase 7B: Advanced Analytics Dashboards

**Priorität:** HOCH
**Status:** ✅ Abgeschlossen (Dez 2025)
**Estimated Effort:** 30-40 Stunden

#### Scope

**1. Multi-Framework Compliance Analytics**
- **Framework Comparison Dashboard**
  - Side-by-Side Framework Progress (Stacked Bars)
  - Cross-Mapping Visualization (Venn Diagrams)
  - Gap Analysis per Framework
  - Compliance Roadmap Timeline
  - Framework Overlap Matrix (Heat Map)

- **Control Coverage Matrix**
  - Which Controls cover which Frameworks?
  - Transitive Compliance Calculation
  - Coverage Efficiency Score
  - Top Multi-Framework Controls

**2. Control Effectiveness Analytics**
- **Control Impact Analysis**
  - Controls ohne Risks → Effectiveness Score
  - Control-to-Risk Reduction Ratio
  - Review Compliance Status
  - Implementation Quality Metrics

- **Control Performance Dashboard**
  - Top 10 Most/Least Effective Controls
  - Control Aging Analysis (Time since Review)
  - Orphaned Controls Detection
  - Effectiveness Heat Map

**3. Predictive Analytics** 🤖
- **Risk Forecast**
  - ML-basierte Trend-Prognose (6 Monate)
  - Risk Velocity Prediction
  - Incident Probability by Asset
  - Compliance Forecast (Wann 100%?)

- **Anomaly Detection**
  - Unusual Risk Spikes
  - Incident Pattern Detection
  - Control Drift Alerts

**4. Benchmarking & KPIs**
- **Industry Comparison** (Optional - anonymisiert)
  - Peer Comparison (similar organization size)
  - Best Practice Recommendations
  - Maturity Model Assessment

- **Executive KPI Dashboard**
  - Risk Appetite vs. Actual
  - Compliance Progress Trends
  - Incident Response Time
  - Control Implementation Velocity

**5. Asset Criticality Analytics**
- **Asset Vulnerability Matrix**
  - CIA Values vs. Risk Count (Bubble Chart)
  - Asset Protection ROI
  - High-Risk Asset Prioritization

- **Supply Chain Risk Visualization**
  - Supplier Dependencies (Graph)
  - Third-Party Risk Assessment
  - Concentration Risk Analysis

#### Visualizations (Chart.js)
- ✅ Multi-Framework Stacked Bar Charts
- ✅ Venn Diagrams (Framework Overlaps)
- ✅ Risk Heat Maps (Enhanced)
- ✅ Compliance Radar (Multi-Framework)
- ✅ Timeline/Gantt Charts (Roadmap)
- ✅ Bubble Charts (Asset Criticality)
- ✅ Trend Lines with Forecasts
- ✅ Sankey Diagrams (Risk Flow)

#### Technical Requirements

**Enhanced Analytics Controller:**
```php
/analytics/                        → Analytics Hub (Tabbed)
/analytics/compliance/frameworks   → Multi-Framework Dashboard
/analytics/controls/effectiveness  → Control Performance
/analytics/risk/forecast           → Predictive Risk Analytics
/analytics/assets/criticality      → Asset Risk Matrix
/analytics/benchmarking            → Industry Comparison
```

**API Endpoints:**
```php
/analytics/api/frameworks/comparison     → JSON Data
/analytics/api/risk/forecast             → ML Predictions
/analytics/api/controls/effectiveness    → Metrics
/analytics/api/assets/vulnerability      → Matrix Data
```

#### Deliverables
- [x] Enhanced AnalyticsController (630+ LOC, 25+ Routes)
- [x] ComplianceAnalyticsService (+ 17 Unit Tests)
- [x] RiskForecastService (ML/Stats) (+ 21 Unit Tests)
- [x] ControlEffectivenessService (+ 22 Unit Tests)
- [x] AssetCriticalityService (+ 15 Unit Tests)
- [x] Chart Components (Stimulus: analytics, chart, radar_chart, trend_chart)
- [x] Analytics Templates (9 Templates)
- [x] API Documentation (docs/api/ANALYTICS_API.md)
- [x] Unit Tests (75 Tests für Phase 7B Services)

#### Acceptance Criteria
- [x] ✅ Multi-Framework Comparison funktioniert
- [x] ✅ Predictive Analytics liefert Forecasts
- [x] ✅ Control Effectiveness Metrics korrekt
- [x] ✅ Asset Criticality Matrix visualisiert
- [x] ✅ Alle Charts responsive & interaktiv
- [x] ✅ Performance: <2s Ladezeit
- [x] ✅ Export: CSV Export für Analytics-Daten
- [x] ✅ 75 Unit Tests für Analytics Services

---

### ✅ Phase 7C: Custom Report Builder (MVP)

**Priorität:** MEDIUM
**Status:** ✅ Abgeschlossen (Dez 2025)
**Estimated Effort:** 20-30 Stunden

#### Scope

**Drag & Drop Report Designer:**
- Template Library (Pre-defined Reports)
- Custom KPI Selection (User wählt Metriken)
- Widget-based Layout Builder
- Saved Report Configurations
- Report Sharing (Team Members)

**Features:**
- ✅ Visual Report Designer
- ✅ KPI Widget Library (25+ Widgets)
- ✅ Custom Date Ranges
- ✅ Filter Configuration
- ✅ Layout Templates (5 Layouts: Single, Two-Column, Dashboard, Wide+Narrow, Narrow+Wide)
- ✅ Export as Template
- ✅ Version History
- ✅ Report Sharing with Team Members

#### Deliverables
- [x] ReportBuilderController (17 Endpoints)
- [x] ReportBuilderService (Widget Data Generation)
- [x] CustomReport Entity (JSON Widget Storage)
- [x] CustomReportRepository (CRUD + Sharing Queries)
- [x] Widget System (25 Reusable Widgets across 5 Categories)
- [x] Template Engine (6 Predefined Templates)
- [x] Visual Designer UI (Drag & Drop with Stimulus.js)
- [x] Widget Library (KPIs, Charts, Tables, Status, Text)
- [x] i18n Support (DE/EN - report_builder.*.yaml)
- [x] Unit Tests (20 Tests für ReportBuilderService)

#### Widget Categories
- **KPIs (10):** Risk Count, High Risks, Control Count, Implementation Rate, Asset Count, Incident Count, Open Incidents, Compliance Score, Overdue Treatments, BCM Coverage
- **Charts (8):** Risk Matrix, Risk by Category, Risk Trend, Control Status, Compliance Radar, Incident Trend, Asset Criticality, Framework Comparison
- **Tables (6):** Top Risks, Recent Incidents, Overdue Controls, Critical Assets, Audit Findings, BC Plans
- **Status (1):** RAG Status
- **Text (3):** Header, Auto Summary, Custom Text

#### Predefined Templates
1. Executive Summary - High-level management overview
2. Risk Report - Comprehensive risk analysis
3. Compliance Dashboard - Multi-framework compliance status
4. Incident Report - Security incidents overview
5. BCM Status - Business continuity status
6. Asset Overview - Asset inventory and criticality

#### Acceptance Criteria
- [x] ✅ Users können eigene Reports erstellen
- [x] ✅ 25+ Widgets verfügbar
- [x] ✅ Templates speicherbar & teilbar
- [x] ✅ Export funktioniert (PDF)
- [x] ✅ Intuitive UI (Drag & Drop Designer)

---

### ✅ Phase 7D: Role-Based Dashboards

**Priorität:** MEDIUM
**Status:** ✅ Abgeschlossen (Dez 2025)
**Estimated Effort:** 15-20 Stunden

#### Scope

**Spezifische Dashboards für Rollen:**

1. **CISO Dashboard** ✅
   - Compliance Status (All Frameworks)
   - High/Critical Risk Overview
   - Audit Readiness Score
   - Incident Trends
   - Budget vs. Actual (Risk Treatment)

2. **Risk Manager Dashboard** ✅
   - Risk Treatment Pipeline
   - Top Risks by Category
   - Treatment Progress
   - Risk Appetite Alerts
   - Mitigation Effectiveness

3. **Auditor Dashboard** ✅
   - Evidence Collection Status
   - Finding Tracker
   - Audit Schedule Timeline
   - Non-Conformities
   - Corrective Actions

4. **Board Dashboard** (High-Level) ✅
   - Red/Amber/Green Status Indicators
   - Trend Arrows (↑↓)
   - Top 3 Critical Items
   - Compliance Summary (%)
   - Executive Summary Card

#### Deliverables
- [x] Role-Based Dashboard Routes (`RoleDashboardController`)
- [x] Dashboard Configuration per Role (`RoleDashboardService`)
- [x] Auto-Redirect based on User Role (Dashboard Switcher)
- [x] Dashboard Templates (4 Roles: CISO, Risk Manager, Auditor, Board)
- [x] Customization Options (Dashboard Switcher Dropdown)
- [x] Translations (DE/EN) for all dashboards
- [x] Unit Tests for RoleDashboardService

---

### 📊 Phase 7 Summary

**Total Effort:** ~160 Stunden
**Status:** ✅ VOLLSTÄNDIG ABGESCHLOSSEN (Dez 2025)

**Abgeschlossene Subphasen:**
- ✅ Phase 7A: Management Reporting System (~45h)
- ✅ Phase 7B: Advanced Analytics Dashboards (~35h)
- ✅ Phase 7C: Custom Report Builder (~25h)
- ✅ Phase 7D: Role-Based Dashboards (~18h)
- ✅ Phase 7E: Compliance Wizards & Module-Aware KPIs (~40h)

**Dependencies:**
- ✅ Phase 6B (Test Coverage) sollte abgeschlossen sein
- ✅ PdfExportService & ExcelExportService existieren bereits
- ✅ Chart.js bereits integriert

**Business Impact:**
- ✅ **Management Visibility:** Fundierte Entscheidungen durch umfassende Reports
- ✅ **Audit Readiness:** Alle Reports dokumentiert & exportierbar
- ✅ **Compliance:** ISO 27001 A.5.7 (Threat Intelligence), NIS2 Art. 23 (Reporting)
- ✅ **Efficiency:** Automatisierte Reports sparen ~5-10h/Monat pro Manager
- ✅ **Predictive:** Proaktives Risikomanagement statt reaktiv

---

## ✅ Phase 7E: Compliance Wizards & Module-Aware KPIs (Abgeschlossen)

**Priorität:** KRITISCH
**Status:** ✅ Abgeschlossen (Dez 2025)
**Estimated Effort:** 35-45 Stunden

### Überblick

Compliance Wizards führen Benutzer durch die bestehenden Module und prüfen den Abdeckungsgrad für spezifische Normen. Im Gegensatz zu isolierten Checklisten nutzen die Wizards die bereits erfassten ISMS-Daten (Data Reuse) und zeigen, wo Lücken bestehen.

**Business Value:**
- ✅ Geführte Norm-Compliance ohne Expertenwissen
- ✅ Automatische Abdeckungsberechnung aus bestehenden Daten
- ✅ Modulübergreifende Sichtbarkeit (Assets, Risks, Controls, BCM, etc.)
- ✅ Handlungsempfehlungen mit direkten Links zu den Modulen
- ✅ Management-ready Compliance Reports

### 🔄 Phase 7E.1: Compliance Wizard Framework

**Komponenten:**

**1. ComplianceWizardService**
- Modul-Awareness: Prüft welche Module aktiv sind
- Data Reuse: Nutzt bestehende Entities (Assets, Risks, Controls, Incidents, BCM)
- Abdeckungsberechnung pro Requirement-Kategorie
- Gap-Identifikation mit konkreten Handlungsempfehlungen
- Progress Tracking über Wizard-Sessions

**2. Wizard-Typen (Framework-spezifisch)**

| Wizard | Framework | Module Required | Prüfbereiche |
|--------|-----------|-----------------|--------------|
| ISO 27001 Readiness | ISO 27001:2022 | controls, risks, assets | 93 Controls, SoA, Risk Treatment |
| NIS2 Compliance | NIS2 | incidents, controls, authentication | Art. 21 (10 Bereiche), Art. 23 Meldepflichten |
| DORA Readiness | DORA | bcm, incidents, controls, assets | ICT Risk, BCM, Incident Reporting, Third-Party |
| TISAX Assessment | TISAX | controls, assets | VDA ISA Katalog (Prototyp, Produktion, etc.) |
| BSI IT-Grundschutz | BSI | controls, assets, risks | Bausteine, Maßnahmen |
| GDPR/DSGVO | GDPR | privacy (neu) | Art. 5-50, DSFA, VVT, TOM |

**3. Wizard-Schritte (Generisches Pattern)**

```
Step 1: Vorbereitung
├── Modul-Check: Welche Module sind aktiv?
├── Framework-Requirements laden
└── Bestehende Daten analysieren

Step 2: Bereich-für-Bereich Prüfung
├── Kategorie A: Governance (z.B. ISO 27001 Clause 4-5)
│   ├── Automatische Prüfung: Policies vorhanden?
│   ├── Manuelle Bestätigung: "Haben Sie ein ISMS-Scope definiert?"
│   └── Abdeckung: 75% → Empfehlung: "Scope dokumentieren"
├── Kategorie B: Risk Management (z.B. Clause 6)
│   ├── Auto: Anzahl Risks erfasst, Treatment Plans vorhanden
│   ├── Auto: Risk Assessment durchgeführt?
│   └── Abdeckung: 90% → Link zu Risk Module
├── Kategorie C: Controls (Annex A)
│   ├── Auto: SoA Coverage, Implementation Status
│   └── Abdeckung: 85% → Gap-Liste mit fehlenden Controls
...

Step 3: Zusammenfassung
├── Overall Compliance Score: 82%
├── Critical Gaps: 5 Items
├── Recommendations: Priorisierte Liste
└── Export: PDF Management Report
```

**4. UI-Konzept**

```
┌─────────────────────────────────────────────────────────────┐
│ 🧭 ISO 27001 Compliance Wizard                    Step 3/7 │
├─────────────────────────────────────────────────────────────┤
│ ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░░░░░ 42% Complete                │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ 📋 Clause 6: Risk Management                                │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ ✅ 6.1.1 Risk Assessment Process    │ 100% │ Vollständig│ │
│ │ ⚠️ 6.1.2 Risk Treatment             │  65% │ 3 offene   │ │
│ │ ✅ 6.1.3 Risk Acceptance            │ 100% │ Vollständig│ │
│ │ ❌ 6.2 ISMS Objectives              │   0% │ Nicht def. │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ 💡 Empfehlungen:                                            │
│ • 3 Risiken ohne Treatment Plan → [Risk Module öffnen]      │
│ • ISMS Objectives nicht definiert → [Objectives erfassen]   │
│                                                             │
│ ┌──────────┐  ┌──────────┐  ┌────────────────┐              │
│ │ ← Zurück │  │ Weiter → │  │ 📊 Report PDF  │              │
│ └──────────┘  └──────────┘  └────────────────┘              │
└─────────────────────────────────────────────────────────────┘
```

### 🔄 Phase 7E.2: Module-Aware KPIs

**Konzept:** KPIs werden nur angezeigt, wenn das zugehörige Modul aktiv ist.

**Dashboard KPIs nach Modul:**

| Modul | KPIs | Berechnung |
|-------|------|------------|
| **assets** | Asset Count, Critical Assets, Asset Coverage | Aus Asset-Entity |
| **risks** | Risk Count, High Risks, MTTR, Risk Reduction Rate | Aus Risk-Entity |
| **controls** | Control Coverage, Implementation %, Effectiveness | Aus Control-Entity |
| **incidents** | Open Incidents, MTTR, Incident Trend | Aus Incident-Entity |
| **bcm** | BIA Coverage, BC Plan Status, RTO/RPO Compliance | Aus BusinessProcess |
| **audits** | Audit Completion, Finding Closure Rate | Aus InternalAudit |
| **training** | Training Completion Rate, Overdue Trainings | Aus Training-Entity |
| **compliance** | Framework Coverage, Gap Count | Aus ComplianceRequirement |

**Management Report KPIs (NEU):**

| KPI | Formel | Modul Required |
|-----|--------|----------------|
| MTTR (Mean Time to Resolve) | Avg(resolvedAt - reportedAt) | incidents |
| Risk Reduction Rate | (Closed Risks / Total Risks) * 100 | risks |
| Control Effectiveness | Avg(linked risk reduction per control) | controls, risks |
| Training Completion Rate | (Completed / Assigned) * 100 | training |
| Audit Finding Closure Rate | (Closed / Total Findings) * 100 | audits |
| Document Review Status | (Current / Total) * 100 | documents |
| Supplier Risk Score | Weighted Avg(supplier risk ratings) | assets (suppliers) |
| BCM Readiness | (Tested BC Plans / Total) * 100 | bcm |

### 🔄 Phase 7E.3: DORA Compliance Dashboard

**Analog zum NIS2-Dashboard, spezifisch für DORA:**

**Key Metrics:**
- ICT Risk Management Score (Art. 6-16)
- Incident Reporting Compliance (Art. 17-23): 4h/72h/1 Monat
- Third-Party Risk Coverage (Art. 28-44)
- Resilience Testing Status (Art. 24-27)
- BCM/RTO/RPO Compliance

**Features:**
- Register of Information Overview
- Third-Party Concentration Risk
- TLPT (Threat-Led Penetration Testing) Tracking
- Incident Timeline mit DORA-spezifischen Fristen

### Deliverables

**Services:**
- [x] `ComplianceWizardService` - Core Wizard Logic (103KB, 6 Frameworks)
- [x] `ModuleAwareKpiService` - In DashboardStatisticsService.getManagementKPIs() integriert
- [x] `WizardProgressService` - Session/Progress Tracking
- [x] `DoraComplianceService` - In DoraComplianceController integriert (5 Säulen)

**Controller:**
- [x] `ComplianceWizardController` - Wizard UI & API (7 Endpoints)
- [x] `DoraComplianceController` - DORA Dashboard

**Templates:**
- [x] `compliance_wizard/` - Wizard Templates (6 Frameworks)
- [x] `dora_compliance/dashboard.html.twig` - DORA Dashboard

**Entities:**
- [x] `WizardSession` - Progress Tracking mit Status, Score, Recommendations

**Commands:**
- [x] `app:wizard-report` - Generate Wizard Report (Console, JSON, PDF)

**Tests:**
- [x] `ComplianceWizardServiceTest` - 14 Tests
- [x] `ComplianceWizardControllerTest` - 9 Tests
- [x] `DoraComplianceControllerTest` - 4 Tests
- [x] `WizardProgressServiceTest` - 8 Tests

### Acceptance Criteria

- [x] ✅ ISO 27001 Wizard vollständig funktionsfähig
- [x] ✅ NIS2 Wizard mit Art. 21/23 Prüfung
- [x] ✅ DORA Wizard mit allen 5 Säulen
- [x] ✅ Module-Awareness: KPIs nur wenn Modul aktiv
- [x] ✅ DORA Dashboard analog zu NIS2
- [x] ✅ PDF Export für Wizard-Ergebnisse
- [x] ✅ Direkte Links zu relevanten Modulen
- [x] ✅ i18n (DE/EN)

---

---

## 🚀 Phase 8: Enterprise Features (In Entwicklung)

**Zeitraum:** Dez 2025 - ...
**Status:** 🚧 In Entwicklung
**Priorität:** HOCH

### ✅ Phase 8A: Mobile PWA (Progressive Web App)

**Status:** ✅ Abgeschlossen (Dez 2025)
**Effort:** ~8 Stunden

#### Implementierte Features

**1. Web App Manifest**
- App-Name, Icons, Theme-Farben
- Shortcuts für Dashboard, Risks, Controls, Incidents
- Standalone Display Mode
- Kategorien: Business, Productivity, Security

**2. Service Worker**
- Cache-First für statische Assets (CSS, JS, Images)
- Network-First für API-Aufrufe
- Offline Fallback Page
- Background Sync vorbereitet
- Push Notifications vorbereitet
- Automatische Cache-Updates

**3. PWA Icons**
- 8 Icon-Größen (72x72 bis 512x512)
- Maskable Icons für Android
- Apple Touch Icons für iOS

**4. Offline Support**
- Stylische Offline-Seite (Cyberpunk Theme)
- Anzeige gecachter Seiten
- Automatische Reconnect-Erkennung
- Offline-Indikator in Header

**5. Install Prompt (A2HS)**
- "App installieren" Button im Header
- beforeinstallprompt Event Handling
- Responsive (Icon-only auf Mobile)

#### Deliverables
- [x] `public/manifest.json` - Web App Manifest
- [x] `public/sw.js` - Service Worker (250+ LOC)
- [x] `public/offline.html` - Offline Fallback Page
- [x] `public/icons/` - PWA Icons (8 Größen)
- [x] `templates/base.html.twig` - PWA Meta Tags & SW Registration
- [x] `translations/messages.*.yaml` - PWA Translations (DE/EN)

#### Acceptance Criteria
- [x] ✅ App installierbar (Chrome, Edge, Safari)
- [x] ✅ Offline-Seite wird angezeigt
- [x] ✅ Statische Assets werden gecacht
- [x] ✅ Install-Button erscheint wenn verfügbar
- [x] ✅ Offline-Indikator funktioniert
- [x] ✅ Apple-Geräte unterstützt (Touch Icons)

---

### 📅 Phase 8B-8G: Geplante Features

| Phase | Feature | Status | Beschreibung |
|-------|---------|--------|--------------|
| 8B | Kubernetes Deployment | 📅 | Cloud-native Container-Orchestrierung |
| 8C | Advanced API (GraphQL) | 📅 | GraphQL API, Webhooks |
| 8D | Integration Marketplace | 📅 | Slack, Teams, JIRA Anbindungen |
| 8E | White-Label Support | 📅 | Eigenes Branding für Kunden |
| 8F | AI-Features | 📅 | Risk Scoring, Auto-Classification |
| 8G | Interactive Help & Onboarding | 📅 | Fairy-Shortcuts, Guided Tours, Contextual Help |

---

## 📅 Zukünftige Phasen (Backlog)

### Phase 9: Global Expansion (Vision)
- 🔄 Real-time Collaboration (WebSocket)
- 🔄 Advanced Workflow Automation
- 🔄 Multi-Cloud Deployment (AWS, Azure, GCP)

---

## 📈 Projekt-Metriken

### Aktueller Stand (Dez 2025 - Phase 7 vollständig abgeschlossen)
- **Codezeilen:** ~55,000+ LOC
- **Entities:** 47 Doctrine Entities (+CustomReport, +WizardSession)
- **Controllers:** 64 Controllers (+ReportBuilderController, RoleDashboardController, ComplianceWizardController, DoraComplianceController)
- **Templates:** 260+ Twig Templates (+Report Builder, Role Dashboards, Compliance Wizards, DORA)
- **Services:** 55+ Business Logic Services (+ReportBuilderService, RoleDashboardService, ComplianceWizardService)
- **Commands:** 34+ Console Commands (+app:wizard-report)
- **Tests:** 3800+ Tests, 100% passing
- **API Endpoints:** 50+ REST Endpoints (+17 Report Builder API, +7 Wizard API)
- **Report Types:** 25+ Widgets (Custom Report Builder)
- **Translation Files:** 113 YAML files (+report_builder, +dashboards, +wizard, +dora, +kpi)

---

## 🎯 Qualitätsziele (Ongoing)

Diese Ziele sind nicht phasengebunden, sondern kontinuierliche Qualitätsmetriken:

| Metrik | Aktuell | Ziel | Status |
|--------|---------|------|--------|
| **Test Coverage** | ~65% | 80%+ | 🔄 In Arbeit |
| **Test Success Rate** | 100% | 100% | ✅ Erreicht |
| **PHP Syntax Errors** | 0 | 0 | ✅ Erreicht |
| **Twig Template Errors** | 0 | 0 | ✅ Erreicht |
| **Container Lint Errors** | 0 | 0 | ✅ Erreicht |
| **i18n Abdeckung** | ~98% | 100% | 🔄 In Arbeit |

### Test Coverage Verbesserung (Backlog)
- 📅 Service Tests für alle 51+ Services
- 📅 Controller Tests für alle 60 Controllers
- 📅 Repository Tests für komplexe Queries
- 📅 Integration Tests für Workflows

---

## 🏆 Zertifizierungsbereitschaft

### ISO 27001:2022
- **Aktuell:** 96%+ ✅ (Zertifizierungsbereit)
- **Phase 7A abgeschlossen:** 98%+ ✅ (Management Reporting für A.5.7, A.5.35)

### ISO 22301:2019 (BCM)
- **Aktuell:** 100% ✅
- **Phase 7A abgeschlossen:** 100% ✅ (Enhanced BCM Reporting)

### NIS2 Directive (EU 2022/2555)
- **Aktuell:** 92%+ ✅ (Phase 6H + 7A abgeschlossen)
- **Reporting:** Art. 23 Compliance durch Scheduled Reports

---

## 📞 Weitere Informationen

- **Projekt-README:** [README.md](README.md)
- **Module Completeness Audit:** [docs/phases/MODULE_COMPLETENESS_AUDIT.md](docs/phases/MODULE_COMPLETENESS_AUDIT.md)
- **Phase Reports:** [docs/phases/](docs/phases/)
- **Issue Tracker:** [GitHub Issues](https://github.com/moag1000/Little-ISMS-Helper/issues)

---

**Stand:** 2026-01-01
**Version:** 2.3
**Letzte Änderung:** Cyberpunk Fairy UX Patterns implementiert, Phase 8G (Interactive Help) zur Roadmap hinzugefügt
**Nächste Aktualisierung:** Nach Abschluss Phase 8B (Kubernetes Deployment)
