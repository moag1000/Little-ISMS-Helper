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

## 🚀 Phase 7: Advanced Analytics & Management Reporting (Geplant)

**Zeitraum:** Nach Phase 6
**Status:** 🔄 Geplant
**Priorität:** HOCH (Management Requirements)

### Überblick
Phase 7 führt umfassende Management-Reporting-Funktionen und erweiterte Analytics-Dashboards ein, um Executives und Management fundierte Entscheidungen zu ermöglichen.

**Business Value:**
- ✅ Compliance mit Berichts-Anforderungen (ISO 27001 Annex A.5.7, NIS2 Art. 23)
- ✅ Management Visibility & Decision Support
- ✅ Audit-Ready Dokumentation
- ✅ Predictive Risk Management

---

### 🔄 Phase 7A: Management Reporting System

**Priorität:** KRITISCH
**Status:** 🔄 Geplant
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
- [ ] ReportCenterController (Management Dashboard)
- [ ] RiskReportService (Business Logic)
- [ ] BCMReportService (Business Logic)
- [ ] AuditReportService (Business Logic)
- [ ] PdfReportGenerator (Enhanced with Charts)
- [ ] PowerPointExportService (New)
- [ ] Report Templates (10+ Templates)
- [ ] Scheduled Report Command (Console)
- [ ] Report Access Control (Voter)
- [ ] Unit Tests (80%+ Coverage)

#### Acceptance Criteria
- [ ] ✅ Alle 6 Report-Kategorien implementiert
- [ ] ✅ PDF/Excel/PPT Export funktioniert
- [ ] ✅ Scheduled Reports per E-Mail
- [ ] ✅ Role-Based Access Control
- [ ] ✅ Charts in PDF Reports (ChartJS → PDF)
- [ ] ✅ Report History & Audit Log
- [ ] ✅ i18n (DE/EN)
- [ ] ✅ Print-optimiertes Layout
- [ ] ✅ 80%+ Test Coverage

---

### 🔄 Phase 7B: Advanced Analytics Dashboards

**Priorität:** HOCH
**Status:** 🔄 Geplant
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
- [ ] Enhanced AnalyticsController
- [ ] ComplianceAnalyticsService
- [ ] RiskForecastService (ML/Stats)
- [ ] ControlEffectivenessService
- [ ] AssetCriticalityService
- [ ] Chart Components (Stimulus)
- [ ] Analytics Templates (Enhanced)
- [ ] API Documentation
- [ ] Unit Tests (80%+ Coverage)

#### Acceptance Criteria
- [ ] ✅ Multi-Framework Comparison funktioniert
- [ ] ✅ Predictive Analytics liefert Forecasts
- [ ] ✅ Control Effectiveness Metrics korrekt
- [ ] ✅ Asset Criticality Matrix visualisiert
- [ ] ✅ Alle Charts responsive & interaktiv
- [ ] ✅ Performance: <2s Ladezeit
- [ ] ✅ Export: Alle Dashboards als PDF
- [ ] ✅ 80%+ Test Coverage

---

### 🔄 Phase 7C: Custom Report Builder (MVP)

**Priorität:** MEDIUM
**Status:** 📅 Backlog
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
- ✅ KPI Widget Library (20+ Widgets)
- ✅ Custom Date Ranges
- ✅ Filter Configuration
- ✅ Layout Templates (1-Col, 2-Col, Dashboard)
- ✅ Export as Template
- ✅ Version History

#### Deliverables
- [ ] ReportBuilderController
- [ ] Widget System (Reusable Components)
- [ ] Template Engine (User-Created)
- [ ] Report Configuration Storage (JSON)
- [ ] Visual Designer UI (Drag & Drop)
- [ ] Widget Library
- [ ] Template Marketplace (Optional)

#### Acceptance Criteria
- [ ] ✅ Users können eigene Reports erstellen
- [ ] ✅ 20+ Widgets verfügbar
- [ ] ✅ Templates speicherbar & teilbar
- [ ] ✅ Export funktioniert (PDF/Excel)
- [ ] ✅ Intuitive UI (User Testing)

---

### 🔄 Phase 7D: Role-Based Dashboards

**Priorität:** MEDIUM
**Status:** 📅 Backlog
**Estimated Effort:** 15-20 Stunden

#### Scope

**Spezifische Dashboards für Rollen:**

1. **CISO Dashboard**
   - Compliance Status (All Frameworks)
   - High/Critical Risk Overview
   - Audit Readiness Score
   - Incident Trends
   - Budget vs. Actual (Risk Treatment)

2. **Risk Manager Dashboard**
   - Risk Treatment Pipeline
   - Top Risks by Category
   - Treatment Progress
   - Risk Appetite Alerts
   - Mitigation Effectiveness

3. **Auditor Dashboard**
   - Evidence Collection Status
   - Finding Tracker
   - Audit Schedule Timeline
   - Non-Conformities
   - Corrective Actions

4. **Board Dashboard** (High-Level)
   - Red/Amber/Green Status Indicators
   - Trend Arrows (↑↓)
   - Top 3 Critical Items
   - Compliance Summary (%)
   - Executive Summary Card

#### Deliverables
- [ ] Role-Based Dashboard Routes
- [ ] Dashboard Configuration per Role
- [ ] Auto-Redirect based on User Role
- [ ] Dashboard Templates (4 Roles)
- [ ] Customization Options

---

### 📊 Phase 7 Summary

**Total Estimated Effort:** 105-140 Stunden
**Priority Distribution:**
- 🔴 **KRITISCH:** Phase 7A (Management Reporting) → 40-50h
- 🟠 **HOCH:** Phase 7B (Advanced Analytics) → 30-40h
- 🟡 **MEDIUM:** Phase 7C (Custom Report Builder) → 20-30h
- 🟡 **MEDIUM:** Phase 7D (Role Dashboards) → 15-20h

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

## 🚧 Phase 7E: Compliance Wizards & Module-Aware KPIs (In Entwicklung)

**Priorität:** KRITISCH
**Status:** 🚧 In Entwicklung
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
- [ ] `ComplianceWizardService` - Core Wizard Logic
- [ ] `ModuleAwareKpiService` - Module-filtered KPIs
- [ ] `WizardProgressService` - Session/Progress Tracking
- [ ] `DoraComplianceService` - DORA-specific metrics

**Controller:**
- [ ] `ComplianceWizardController` - Wizard UI & API
- [ ] `DoraComplianceController` - DORA Dashboard

**Templates:**
- [ ] `compliance_wizard/` - Wizard Templates (6+ Frameworks)
- [ ] `dora_compliance/dashboard.html.twig` - DORA Dashboard

**Entities:**
- [ ] `WizardSession` - Progress Tracking (optional)

**Commands:**
- [ ] `app:wizard-report` - Generate Wizard Report PDF

### Acceptance Criteria

- [ ] ✅ ISO 27001 Wizard vollständig funktionsfähig
- [ ] ✅ NIS2 Wizard mit Art. 21/23 Prüfung
- [ ] ✅ DORA Wizard mit allen 5 Säulen
- [ ] ✅ Module-Awareness: KPIs nur wenn Modul aktiv
- [ ] ✅ DORA Dashboard analog zu NIS2
- [ ] ✅ PDF Export für Wizard-Ergebnisse
- [ ] ✅ Direkte Links zu relevanten Modulen
- [ ] ✅ i18n (DE/EN)
- [ ] ✅ 80%+ Test Coverage

---

## 📅 Zukünftige Phasen (Backlog)

### Phase 8: Enterprise Features (Vision)
- 🔄 Mobile PWA (Progressive Web App)
- 🔄 Kubernetes Deployment
- 🔄 Advanced API Features (GraphQL, Webhooks)
- 🔄 Integration Marketplace (Slack, Teams, JIRA)
- 🔄 White-Label Support
- 🔄 AI-gestützte Features (Risk Scoring, Auto-Classification)

### Phase 9: Global Expansion (Vision)
- 🔄 Real-time Collaboration (WebSocket)
- 🔄 Advanced Workflow Automation
- 🔄 Blockchain-based Audit Trail
- 🔄 Quantum-Safe Cryptography
- 🔄 Multi-Cloud Deployment (AWS, Azure, GCP)

---

## 📈 Projekt-Metriken

### Aktueller Stand (Dez 2025 - Phase 6 abgeschlossen)
- **Codezeilen:** ~45,000+ LOC
- **Entities:** 43 Doctrine Entities
- **Controllers:** 56 Controllers (inkl. WelcomeController)
- **Templates:** 200+ Twig Templates
- **Services:** 47+ Business Logic Services
- **Commands:** 31+ Console Commands
- **Tests:** 3652 Tests, 9607 Assertions, 100% passing
- **Test Coverage:** ~65%
- **API Endpoints:** 30+ REST Endpoints
- **Report Types:** 11 (6 PDF + 5 Excel)
- **Translation Files:** 99 YAML files (49+ domains × 2 languages)

### Erwarteter Stand (Phase 7 Ende)
- **Controllers:** +3 (ReportCenter, Enhanced Analytics, ReportBuilder)
- **Services:** +6 (Reporting, Forecast, Analytics Services)
- **Templates:** +15-20 (Report Templates, Dashboards)
- **API Endpoints:** +10 (Analytics APIs)
- **Report Types:** ~25+ (Risk, BCM, Audit, Compliance, Custom)
- **Test Coverage:** 80%+ (maintained)

---

## 🏆 Zertifizierungsbereitschaft

### ISO 27001:2022
- **Aktuell:** 94.5% ✅ (Zertifizierungsbereit mit Minor Findings)
- **Nach Phase 6F:** 98%+ ✅
- **Nach Phase 7A:** 99%+ ✅ (A.5.7 Threat Intelligence - vollständig)

### ISO 22301:2019 (BCM)
- **Aktuell:** 100% ✅
- **Nach Phase 7A:** 100% ✅ (Enhanced Reporting)

### NIS2 Directive (EU 2022/2555)
- **Aktuell:** 90%+ ✅ (Phase 6H abgeschlossen)
- **Nach Phase 7A:** 98%+ ✅ (Enhanced Dashboard & Analytics)

---

## 📞 Weitere Informationen

- **Projekt-README:** [README.md](README.md)
- **Module Completeness Audit:** [docs/phases/MODULE_COMPLETENESS_AUDIT.md](docs/phases/MODULE_COMPLETENESS_AUDIT.md)
- **Phase Reports:** [docs/phases/](docs/phases/)
- **Issue Tracker:** [GitHub Issues](https://github.com/moag1000/Little-ISMS-Helper/issues)

---

**Stand:** 2025-12-12
**Version:** 1.6
**Letzte Änderung:** Phase 6 abgeschlossen (Docker Hardening, Welcome Page, 3652 Tests)
**Nächste Aktualisierung:** Nach Abschluss Phase 7A (Management Reporting)
