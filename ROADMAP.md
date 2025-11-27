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

## 🚧 Phase 6: Module Completeness & Quality Assurance (In Entwicklung)

**Zeitraum:** Aktuell
**Status:** 🚧 ~80% Abgeschlossen
**Audit:** [docs/phases/MODULE_COMPLETENESS_AUDIT.md](docs/phases/MODULE_COMPLETENESS_AUDIT.md)

### Überblick
Phase 6 konzentriert sich auf die Vervollständigung aller Module und die Sicherstellung der Zertifizierungsbereitschaft.

**Aktueller Stand:**
- **Technische Vollständigkeit:** ~90%
- **Test Coverage:** ~65% (1689 Tests, 5066 Assertions - Ziel: 80%+)
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

### 🚧 In Arbeit
- 🧪 Phase 6B: Test Coverage (Aktuell: ~65%, Ziel: 80%+)
  - ✅ Umfangreiche Test-Suite (1689 Tests, 5066 Assertions)
  - ✅ 100% Success Rate
  - ✅ Compliance-Tests für Multi-Framework Support
  - ✅ Workflow Service Tests (6 neue Test-Klassen)
  - 🔄 Weitere Controller-Tests ausstehend

### 🔄 Ausstehend
- 🔄 Phase 6E: Datenbank-Konsistenz & Constraints
- 🔄 Phase 6G: Advanced Compliance Features (TISAX AL3, DORA TPP)
- 🔄 Phase 6I: BSI IT-Grundschutz Integration
- 🔄 Phase 6J: Performance Optimierung
- 🔄 Phase 6K: Internationalisierung (i18n) Vervollständigung
- 🔄 Phase 6M: Docker Production Hardening

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

### Aktueller Stand (Nov 2025 - Phase 6)
- **Codezeilen:** ~43,600+ LOC
- **Entities:** 39 Doctrine Entities
- **Controllers:** 38+ Controllers
- **Templates:** 197+ Twig Templates
- **Services:** 47+ Business Logic Services
- **Commands:** 31+ Console Commands
- **Tests:** 1689 tests, 5066 assertions (100% passing)
- **Test Coverage:** ~65% (Ziel Phase 6B: 80%+)
- **API Endpoints:** 30+ REST Endpoints
- **Report Types:** 11 (6 PDF + 5 Excel)

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

**Stand:** 2025-11-27
**Version:** 1.2
**Letzte Änderung:** Phase 6N (Automated Workflows) abgeschlossen, v2.1.0 Release
**Nächste Aktualisierung:** Nach Abschluss Phase 6B (Test Coverage)
