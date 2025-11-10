# 🗺️ Little ISMS Helper - Roadmap

Dieses Dokument enthält die vollständige Projekt-Roadmap mit allen Phasen, Meilensteinen und geplanten Features.

**Status-Legende:** ✅ Abgeschlossen | 🚧 In Entwicklung | 🔄 Geplant | 📅 Backlog | ⏸️ Deferred

---

## ✅ Phase 1: Core ISMS (Abgeschlossen)

**Zeitraum:** Projekt-Start
**Status:** ✅ 100% Abgeschlossen

### Implementierte Features
- ✅ 9 Core Entities (Asset, Risk, Control, Incident, etc.)
- ✅ Statement of Applicability mit 93 ISO 27001:2022 Controls
- ✅ Grundlegende Controller & Views
- ✅ KPI Dashboard

**Dokumentation:** Siehe README.md

---

## ✅ Phase 2: Data Reuse & Multi-Framework (Abgeschlossen)

**Zeitraum:** Nach Phase 1
**Status:** ✅ 100% Abgeschlossen
**Bericht:** [docs/PHASE2_COMPLETENESS_REPORT.md](docs/PHASE2_COMPLETENESS_REPORT.md)

### Implementierte Features
- ✅ Business Continuity Management (BCM)
- ✅ Multi-Framework Compliance (ISO 27001, TISAX, DORA)
- ✅ Cross-Framework Mappings & Transitive Compliance
- ✅ Vollständige Entity-Beziehungen
- ✅ Automatische KPIs
- ✅ Progressive Disclosure UI
- ✅ Symfony UX Integration (Stimulus, Turbo)

**Zeitersparnis:** ~10,5 Stunden (95%) pro Audit-Zyklus durch automatisierte Analysen

---

## ✅ Phase 3: User Management & Security (Abgeschlossen)

**Zeitraum:** Nach Phase 2
**Status:** ✅ 100% Abgeschlossen
**Bericht:** [docs/PHASE3_COMPLETENESS_REPORT.md](docs/PHASE3_COMPLETENESS_REPORT.md)

### Implementierte Features
- ✅ Multi-Provider Authentication (Local, Azure OAuth/SAML)
- ✅ RBAC mit 5 System-Rollen & 29 Permissions
- ✅ Automatisches Audit Logging
- ✅ Multi-Language Support (DE, EN)
- ✅ User Management UI

---

## ✅ Phase 4: CRUD & Workflows (Abgeschlossen)

**Zeitraum:** Nach Phase 3
**Status:** ✅ 100% Abgeschlossen
**Bericht:** [docs/PHASE4_COMPLETENESS_REPORT.md](docs/PHASE4_COMPLETENESS_REPORT.md)

### Implementierte Features
- ✅ Vollständige CRUD für alle Module
- ✅ 5 Form Types mit Validierung
- ✅ Workflow-Engine (Approval, Rejection, Cancellation)
- ✅ Risk Assessment Matrix (5x5 Visualisierung)
- ✅ 30+ Professional Templates

---

## ✅ Phase 5: Reporting & Integration (Abgeschlossen)

**Zeitraum:** Nach Phase 4
**Status:** ✅ 100% Abgeschlossen
**Bericht:** [docs/PHASE5_COMPLETENESS_REPORT.md](docs/PHASE5_COMPLETENESS_REPORT.md)

### Implementierte Features
- ✅ PDF/Excel Export System (5 Report-Typen)
- ✅ REST API (30 Endpoints, OpenAPI 3.0)
- ✅ Automated Notification Scheduler (5 Typen)
- ✅ Premium Features (Dark Mode, Global Search, Quick View)
- ⏸️ Document Management (Foundation, deferred)

---

## 🚧 Phase 6: Module Completeness & Quality Assurance (In Entwicklung)

**Zeitraum:** Aktuell
**Status:** 🚧 ~70% Abgeschlossen
**Audit:** [docs/MODULE_COMPLETENESS_AUDIT.md](docs/MODULE_COMPLETENESS_AUDIT.md)

### Überblick

Phase 6 konzentriert sich auf die Vervollständigung aller Module und die Sicherstellung der Zertifizierungsbereitschaft.

**Aktueller Stand:**
- **Technische Vollständigkeit:** ~70% (Lücken: Form Types, Tests, Workflows)
- **ISO 27001:2022 Compliance:** 94.5% ✅
- **Multi-Standard Compliance:** 84% Durchschnitt (mit NIS2 + BSI)
  - ISO 22301:2019 (BCM): 100% ✅
  - ISO 19011:2018 (Audit): 95% ⚠️
  - ISO 31000:2018 (Risk): 95% ⚠️
  - ISO 27005:2022 (Risk Security): 100% ✅
  - EU DORA: 85% ⚠️
  - TISAX/VDA ISA: 75% ⚠️
  - NIS2 Directive: 68% ⚠️ (KRITISCH)
  - BSI IT-Grundschutz 200-4: 68% ⚠️ (HOCH)
- **Zertifizierungsbereitschaft:** JA (mit Minor Findings in Asset Management)

---

### 🔥 Phase 6A: Form Types (Priorität KRITISCH)

**Status:** 🔄 Geplant
**Aufwand:** 1-2 Tage
**Impact:** Hoch

#### Fehlende Form Types
- 🔄 ISMSObjectiveType (Controller existiert bereits)
- 🔄 WorkflowType
- 🔄 WorkflowInstanceType
- 🔄 ComplianceFrameworkType
- 🔄 ComplianceRequirementType
- 🔄 ComplianceMappingType

#### Akzeptanzkriterien
- [ ] Alle 6 Form Types implementiert
- [ ] Symfony Validation Constraints hinzugefügt
- [ ] Integration in bestehende Controller
- [ ] Twig-Templates erstellt

---

### 🧪 Phase 6B: Test Coverage (Priorität KRITISCH)

**Status:** 🔄 Geplant
**Aufwand:** 3-4 Tage
**Impact:** Sehr hoch

#### Ziele
- 🔄 Entity Tests für 17 Module ohne Tests
- 🔄 Controller Tests für kritische Module
- 🔄 Service Tests für Business Logic
- 🔄 Test Coverage: 26% → 80%+

#### Module ohne Tests (17)
1. AuditLog
2. BCMProcess
3. BIAScenario
4. ComplianceFramework
5. ComplianceMapping
6. ComplianceRequirement
7. DataBreach
8. Document
9. ISMSContext
10. ISMSObjective
11. ManagementReview
12. Notification
13. Process
14. Training
15. Workflow
16. WorkflowInstance
17. WorkflowStep

#### Akzeptanzkriterien
- [ ] Test Coverage ≥ 80%
- [ ] Alle kritischen Pfade getestet
- [ ] CI/CD Pipeline erfolgreich

---

### 🔧 Phase 6C: Workflow-Management (Priorität WICHTIG)

**Status:** 🔄 Geplant
**Aufwand:** 2-3 Tage
**Impact:** Hoch

#### Ziele
- 🔄 Workflow CRUD vervollständigen (aktuell nur 35%)
- 🔄 WorkflowInstance CRUD vervollständigen (aktuell nur 30%)
- 🔄 Templates erstellen (6+ neue Templates)
- 🔄 Tests implementieren

#### Fehlende Komponenten
- WorkflowType (Form)
- WorkflowInstanceType (Form)
- Templates für Create/Edit/Show
- Unit Tests
- Integration Tests

#### Akzeptanzkriterien
- [ ] Vollständiges CRUD für Workflow
- [ ] Vollständiges CRUD für WorkflowInstance
- [ ] 6+ neue Twig-Templates
- [ ] Test Coverage ≥ 80%

---

### 📊 Phase 6D: Compliance-Detail-Management (Priorität WICHTIG)

**Status:** 🔄 Geplant
**Aufwand:** 2-3 Tage
**Impact:** Mittel

#### Ziele
- 🔄 ComplianceFrameworkController (dediziert, vollständiges CRUD)
- 🔄 ComplianceRequirementController (dediziert, vollständiges CRUD)
- 🔄 ComplianceMappingController (dediziert, vollständiges CRUD)
- 🔄 Templates erstellen (12+ neue Templates)

#### Akzeptanzkriterien
- [ ] 3 dedizierte Controller
- [ ] 3 Form Types
- [ ] 12+ Twig-Templates
- [ ] REST API Endpoints

---

### 🏛️ Phase 6F: ISO 27001 Inhaltliche Vervollständigung (Priorität HOCH)

**Status:** 🔄 Geplant
**Aufwand:** 2-3 Tage
**Impact:** KRITISCH

#### Asset Management vervollständigen (KRITISCH für Zertifizierung)

**Aktueller Status:** Grundlegende CRUD vorhanden, aber wichtige ISO 27001-konforme Felder fehlen

##### Fehlende Features
1. **Acceptable Use Policy Field**
   - Neues Feld in Asset Entity
   - Formular-Integration
   - Template-Anpassung

2. **Monetary Value**
   - Finanzieller Wert des Assets
   - Währungs-Unterstützung
   - ROI-Berechnung Integration

3. **Handling Instructions**
   - Text-Feld für Asset-spezifische Anweisungen
   - Markdown-Unterstützung
   - Integration in Asset-Details

4. **Data Classification**
   - Enum: public/internal/confidential/restricted
   - Farbcodierung in UI
   - Filter nach Classification
   - Automatische Schutzbedarf-Ableitung

5. **Asset Return Workflow**
   - Status-Erweiterung (in_use, returned, disposed)
   - Return-Datum Feld
   - Return-Formular
   - Return-Benachrichtigungen

##### Data Reuse Integration 🔄
- **Asset Monetary Value → Risk Impact** (Auto-Berechnung)
  - Risk.financialImpact wird aus affectedAssets.monetaryValue berechnet
  - Zeitersparnis: ~15 Min pro Risk Assessment
- **Asset Data Classification ← Risk Assessment** (Auto-Ableitung)
  - High-Risk Assets → automatisch "confidential" Classification
  - CIA-Bewertung informiert Classification
- **Asset → Control ↔ Asset** (WICHTIG - aus DATA_REUSE_ANALYSIS.md)
  - Many-to-Many: Welche Controls schützen welche Assets?
  - Control Coverage Matrix automatisch generiert
  - Asset Protection Dashboard

##### Akzeptanzkriterien
- [ ] 5 neue Asset-Felder implementiert
- [ ] Asset Form Type aktualisiert
- [ ] Migration erstellt
- [ ] Templates angepasst
- [ ] Tests geschrieben
- [ ] Dokumentation aktualisiert
- [ ] **Data Reuse:** Asset ↔ Control Beziehung implementiert
- [ ] **Data Reuse:** Monetary Value → Risk Impact Berechnung
- [ ] **Data Reuse:** Data Classification Auto-Ableitung

#### Risk Management vervollständigen

##### Fehlende Features
1. **Risk Owner als User-Referenz**
   - ManyToOne Beziehung zu User Entity
   - Owner-Auswahl in Formular
   - Owner-Benachrichtigungen

2. **Risk Appetite Entity**
   - Neue Entity für Risikobereitschaft
   - Globale und kategoriebasierte Appetite Levels
   - Integration in Risk Assessment

3. **Risk Treatment Plan Entity**
   - Dedizierte Entity für Behandlungspläne
   - Timeline-Tracking
   - Verantwortlichkeiten
   - Status-Verfolgung

##### Data Reuse Integration 🔄
- **Risk ↔ Incident** (KRITISCH - aus DATA_REUSE_ANALYSIS.md)
  - Many-to-Many: Welche Risiken wurden durch Incidents realisiert?
  - Risk Validation: "Dieses Risiko trat 3x ein im letzten Jahr"
  - Probability Adjustment basierend auf realisierten Incidents
  - Zeitersparnis: ~30 Min pro Risk Review
- **Risk Treatment Plan → Control** (Implementation Tracking)
  - RiskTreatmentPlan.implementedControls (ManyToMany)
  - Treatment-Wirksamkeit durch Control-Effectiveness messbar
  - Automatische Progress-Berechnung
- **BusinessProcess ↔ Risk** (WICHTIG - aus DATA_REUSE_ANALYSIS.md)
  - Many-to-Many: Welche Risiken betreffen welche Prozesse?
  - Risk Priority aus BIA.rto/rpo abgeleitet
  - Business-aligned Risk Treatment
- **Risk Appetite → Risk Assessment** (Auto-Priorisierung)
  - Risiken über Risk Appetite = automatisch High Priority
  - Dashboard: "5 Risiken überschreiten Appetite"

##### Akzeptanzkriterien
- [ ] Risk Owner Feld hinzugefügt
- [ ] RiskAppetite Entity erstellt
- [ ] RiskTreatmentPlan Entity erstellt
- [ ] 3 Form Types
- [ ] Integration in Risk Module
- [ ] Tests geschrieben
- [ ] **Data Reuse:** Risk ↔ Incident Beziehung implementiert
- [ ] **Data Reuse:** Risk Treatment Plan ↔ Control
- [ ] **Data Reuse:** BusinessProcess ↔ Risk
- [ ] **Data Reuse:** Risk Appetite Auto-Priorisierung

#### Statement of Applicability Report

##### Fehlende Features
1. **SoA PDF Generator Service**
   - Professionelles SoA-PDF mit allen 93 Controls
   - Implementierungs-Status
   - Begründungen
   - Zugeordnete Risiken
   - Verantwortlichkeiten

2. **Professional SoA Template**
   - Twig-Template für SoA-Report
   - ISO 27001-konformes Layout
   - Tabellen und Formatting
   - Export-Button im SoA-Modul

##### Akzeptanzkriterien
- [ ] SoAReportService implementiert
- [ ] PDF-Template erstellt
- [ ] Export-Button integriert
- [ ] Tests geschrieben

---

### 🌐 Phase 6G: Multi-Standard Compliance Vervollständigung (Priorität MITTEL)

**Status:** 🔄 Geplant
**Aufwand:** 3-4 Tage
**Impact:** MITTEL (branchenspezifisch)

#### Audit Management Erweiterung (ISO 19011)

##### Fehlende Features
1. **AuditorCompetence Entity**
   - Auditor-Qualifikationsverwaltung
   - Competence Level (junior/senior/lead)
   - Certification Tracking
   - Experience Tracking

2. **Training-Integration**
   - Verknüpfung zu Training Entity
   - Automatische Kompetenz-Updates
   - Training Gap Analysis

##### Data Reuse Integration 🔄
- **AuditorCompetence ↔ Training** (Auto-Qualification Tracking)
  - Training.completedBy → automatische Competence-Updates
  - Training Gap Analysis für Auditoren
  - Certification Expiry → Training Reminder
  - Zeitersparnis: ~20 Min pro Auditor-Verwaltung

##### Akzeptanzkriterien
- [ ] AuditorCompetence Entity
- [ ] Competence Form Type
- [ ] Templates
- [ ] Training Integration
- [ ] Tests
- [ ] **Data Reuse:** Training ↔ AuditorCompetence Auto-Update

#### Risk Communication Log (ISO 31000)

##### Fehlende Features
1. **RiskCommunication Entity**
   - Stakeholder Engagement Tracking
   - Communication Type (meeting/report/email/presentation)
   - Date & Participants
   - Summary & Outcomes

2. **Stakeholder-Verwaltung**
   - Stakeholder Entity oder User-Erweiterung
   - Stakeholder-Kategorien
   - Communication Preferences

##### Data Reuse Integration 🔄
- **RiskCommunication ↔ Risk** (Communication Tracking)
  - ManyToMany: Welche Risiken wurden mit wem kommuniziert?
  - Communication Gap Analysis: "Risiko XYZ nicht mit Management besprochen"
  - Automatic Reminder: "High Risk ohne Communication in 30 Tagen"

##### Akzeptanzkriterien
- [ ] RiskCommunication Entity
- [ ] Form Type
- [ ] Templates
- [ ] Integration in Risk Module
- [ ] Tests
- [ ] **Data Reuse:** RiskCommunication ↔ Risk

#### DORA Compliance (nur für Financial Entities)

##### Fehlende Features
1. **ICTThirdPartyProvider Entity**
   - TPP Register
   - Critical/Important Classification
   - Contract Management
   - Risk Assessment

2. **TLPTExercise Entity**
   - Threat-Led Penetration Testing
   - Exercise Planning
   - Results Tracking
   - Remediation Follow-up

##### Data Reuse Integration 🔄
- **ICTThirdPartyProvider ↔ Risk** (Third-Party Risk Assessment)
  - Auto-Risiko-Erstellung für Critical/Important TPPs
  - Risk.thirdPartyProvider (ManyToOne)
  - Risk Aggregation: "10 Risiken durch TPP XYZ"
  - Zeitersparnis: ~25 Min pro TPP Risk Assessment
- **TLPTExercise ↔ Vulnerability** (Findings Integration)
  - TLPT Findings → automatische Vulnerability-Erstellung
  - Severity Mapping (TLPT → CVSS)
  - Remediation Tracking

##### Akzeptanzkriterien
- [ ] 2 neue Entities
- [ ] 2 Form Types
- [ ] Templates
- [ ] DORA-spezifische Reports
- [ ] Tests
- [ ] **Data Reuse:** ICTThirdPartyProvider ↔ Risk
- [ ] **Data Reuse:** TLPTExercise ↔ Vulnerability

#### TISAX Compliance (nur für Automotive Industry)

##### Fehlende Features
1. **Asset.php Erweiterung**
   - Assessment Level (AL1/AL2/AL3)
   - Protection Need
   - Prototype Fields

2. **TISAXAssessment Entity**
   - Assessment Planning
   - Maturity Level Tracking
   - Findings Management
   - Re-Assessment Scheduling

##### Data Reuse Integration 🔄
- **TISAXAssessment ↔ Asset** (AL-Level Tracking per Asset)
  - Assessment Results → automatische Asset.assessmentLevel Updates
  - Asset.protectionNeed ← CIA-Bewertung (Auto-Ableitung)
  - Assessment Gap Analysis: "20 Assets ohne AL-Level"
- **TISAXAssessment ↔ Control** (Maturity Assessment)
  - Assessment.findings → Control Improvement Actions
  - Maturity Level per Control Category

##### Akzeptanzkriterien
- [ ] Asset erweitert
- [ ] TISAXAssessment Entity
- [ ] Form Types
- [ ] Templates
- [ ] TISAX-Reports
- [ ] Tests
- [ ] **Data Reuse:** TISAXAssessment ↔ Asset (AL-Level Auto-Update)
- [ ] **Data Reuse:** Asset.protectionNeed ← CIA Auto-Ableitung

---

### 🇪🇺 Phase 6H: NIS2 Directive Compliance (Priorität KRITISCH)

**Status:** 🔄 Geplant
**Aufwand:** 7-8 Tage
**Impact:** KRITISCH
**Deadline:** 17.10.2024 (NIS2 Enforcement)

#### LoadNis2RequirementsCommand.php (Data Reuse)

**Zweck:** NIS2 Directive (EU 2022/2555) als loadbares Framework

##### Features
- 45 NIS2 Requirements als ComplianceRequirement Entities
- ISO 27001 Control Mappings (z.B. NIS2-21.2.i → 5.17, 5.18)
- Automatic Compliance Tracking
- Transitive Compliance über Mappings

##### Akzeptanzkriterien
- [ ] Command implementiert
- [ ] 45 Requirements definiert
- [ ] Control Mappings erstellt
- [ ] Tests geschrieben
- [ ] Dokumentation

#### Multi-Factor Authentication (MFA) Implementation (KRITISCH)

**NIS2 Artikel:** Art. 21.2.i (Access Control & Authentication)

##### Fehlende Features
1. **MfaToken Entity**
   - TOTP (Time-based One-Time Password)
   - WebAuthn (FIDO2)
   - SMS Backup Codes
   - Hardware Token Support

2. **User-MFA-Enrollment Workflow**
   - QR-Code Generation (TOTP)
   - Backup Codes Generation
   - Recovery Options
   - Enrollment UI

3. **Admin MFA-Enforcement Settings**
   - Global MFA Toggle
   - Role-based MFA Requirements
   - Grace Period Configuration
   - Exemptions Management

4. **MFA-enabled Field in User Entity**
   - Boolean Feld
   - MFA Type (totp/webauthn/sms)
   - Enrollment Date
   - Last Verified

##### Akzeptanzkriterien
- [ ] MfaToken Entity
- [ ] MFA Service (TOTP, WebAuthn)
- [ ] Enrollment UI
- [ ] Login Integration
- [ ] Admin Settings
- [ ] Tests
- [ ] Dokumentation

#### Incident Reporting Timelines (NIS2 Art. 23) (KRITISCH)

**NIS2 Artikel:** Art. 23 (Incident Notification)

##### Fehlende Features
1. **Incident.php Erweiterung**
   - `earlyWarningReportedAt` (DateTime) - 24h Frist
   - `detailedNotificationReportedAt` (DateTime) - 72h Frist
   - `finalReportSubmittedAt` (DateTime) - 1 Monat Frist
   - `nis2Category` (Enum: operational/security/privacy/availability)
   - `crossBorderImpact` (Boolean)
   - `affectedMemberStates` (Array)

2. **Timeline-Tracking UI**
   - Countdown-Timer für Fristen
   - Status-Ampel (rot/gelb/grün)
   - Automated Reminders
   - Report-Templates

3. **NIS2-Incident-Report Generator**
   - PDF-Report für Behörden
   - Structured Data Export
   - Attachment Support

##### Data Reuse Integration 🔄
- **Incident Timeline → Notification** (Auto-Alerts bei Frist-Ablauf)
  - 20h vor 24h-Frist: Auto-Benachrichtigung an Incident Owner
  - 68h vor 72h-Frist: Escalation an Management
  - 7 Tage vor 1-Monat-Frist: Final Report Reminder
  - Zeitersparnis: ~45 Min pro Incident (manuelle Frist-Verfolgung entfällt)
- **Incident ↔ Asset** (KRITISCH - aus DATA_REUSE_ANALYSIS.md)
  - Many-to-Many: Welche Assets waren betroffen?
  - Asset Incident History automatisch
  - High-Risk Asset Identification

##### Akzeptanzkriterien
- [ ] 6 neue Incident-Felder
- [ ] Migration
- [ ] Form Type Update
- [ ] Timeline UI
- [ ] Report Generator
- [ ] Automated Notifications
- [ ] Tests
- [ ] **Data Reuse:** Incident Timeline → Notification Auto-Alerts
- [ ] **Data Reuse:** Incident ↔ Asset Beziehung

#### Vulnerability Management (NIS2 Art. 21.2.d) (KRITISCH)

**NIS2 Artikel:** Art. 21.2.d (Vulnerability Handling & Disclosure)

##### Fehlende Features
1. **Vulnerability Entity**
   - CVE-ID (unique)
   - CVSS Score & Vector
   - Severity (critical/high/medium/low)
   - Description
   - Affected Assets (ManyToMany)
   - Status (open/patched/mitigated/accepted)
   - Discovery Date
   - Disclosure Date
   - Remediation Deadline

2. **Patch Entity**
   - Patch-ID
   - Related Vulnerabilities (ManyToMany)
   - Patch Status (planned/testing/deployed/verified)
   - Deployment Date
   - Responsible User
   - Rollback Plan

3. **Asset-Vulnerability Relationships**
   - ManyToMany zwischen Asset und Vulnerability
   - Impact Assessment per Asset
   - Prioritization

4. **Vulnerability Dashboard**
   - Open Vulnerabilities by Severity
   - Overdue Patches
   - Time to Remediate (KPI)
   - CVE Trends

##### Data Reuse Integration 🔄 (KRITISCH)
- **Vulnerability ↔ Risk** (Auto-Risiko-Erstellung aus CVE)
  - Critical/High CVE → automatische Risk Entity
  - Risk.likelihood = CVSS.exploitability
  - Risk.impact = CVSS.impact * Asset.monetaryValue
  - Zeitersparnis: ~40 Min pro Vulnerability (manuelles Risk Assessment entfällt)
  - **Revolutionär:** CVE-Feed → automatisches Risk Management! 🚀
- **Vulnerability ↔ Incident** (CVE Exploitation Tracking)
  - Incident.exploitedVulnerability (ManyToOne)
  - "Diese CVE wurde in 2 Incidents ausgenutzt" → höhere Priorität
  - Incident Root Cause automatisch: CVE-ID
- **Vulnerability ↔ Asset** (bereits geplant)
  - Many-to-Many: Welche Assets sind betroffen?
  - Asset Vulnerability Score automatisch
- **Patch ↔ Control** (Control Effectiveness Measurement)
  - Patch-Geschwindigkeit = A.8.8 Control Effectiveness
  - "Durchschnittliche Time-to-Patch: 5 Tage" = KPI
  - Control-Dashboard: "Patch Management: 85% Effectiveness"
  - Zeitersparnis: ~30 Min pro Control Review

##### Akzeptanzkriterien
- [ ] Vulnerability Entity
- [ ] Patch Entity
- [ ] 2 Form Types
- [ ] Dashboard KPIs
- [ ] CVE Import (optional)
- [ ] Templates
- [ ] Tests
- [ ] **Data Reuse:** Vulnerability → Risk Auto-Erstellung (KRITISCH)
- [ ] **Data Reuse:** Vulnerability ↔ Incident Tracking
- [ ] **Data Reuse:** Patch → Control Effectiveness KPI
- [ ] **Data Reuse:** Vulnerability ↔ Asset

#### Supply Chain Security (NIS2 Art. 21.2.e)

**NIS2 Artikel:** Art. 21.2.e (Supply Chain Security)

##### Fehlende Features
1. **Supplier Risk Assessment Integration**
   - Risk.php Erweiterung: `supplierRelated` (Boolean)
   - Supplier-specific Risk Categories

2. **Third-Party Security Monitoring**
   - Security Assessments Tracking
   - Contract Security Requirements
   - Incident Reporting from Suppliers

##### Akzeptanzkriterien
- [ ] Risk Entity erweitert
- [ ] Supplier Risk Templates
- [ ] Reporting Integration
- [ ] Tests

---

### 🇩🇪 Phase 6I: BSI IT-Grundschutz & Additional Standards (Priorität HOCH)

**Status:** 🔄 Geplant
**Aufwand:** 5-6 Tage
**Impact:** HOCH

#### LoadBsiRequirementsCommand.php (Data Reuse)

**Zweck:** BSI IT-Grundschutz 200-4 als loadbares Framework

##### Features
- 35 BSI 200-4 Requirements als ComplianceRequirement Entities
- ISO 22301 Control Mappings
- Automatic Compliance Tracking
- BCM-Methodik Integration

##### Akzeptanzkriterien
- [ ] Command implementiert
- [ ] 35 Requirements definiert
- [ ] Control Mappings
- [ ] Tests
- [ ] Dokumentation

#### Krisenstab-Management (BSI 200-4 Kapitel 4.3) (HOCH)

**BSI Standard:** BSI 200-4 Kapitel 4.3 (Krisenstab)

##### Fehlende Features
1. **CrisisTeam Entity**
   - Team Name
   - Team Members (ManyToMany zu User)
   - Team Roles (Leiter, Stellvertreter, Mitglieder)
   - Responsibilities
   - Contact Information
   - Availability (24/7 Rufbereitschaft)
   - Alert Mechanisms
   - Activation Criteria

2. **Alert & Activation Workflows**
   - Activation Trigger
   - Notification Chain
   - Meeting Scheduling
   - Decision Tracking

3. **Integration mit BCM**
   - BIA-Scenario → CrisisTeam Assignment
   - Process → CrisisTeam Responsibility

##### Akzeptanzkriterien
- [ ] CrisisTeam Entity
- [ ] Team Form Type
- [ ] Activation Workflow
- [ ] BCM Integration
- [ ] Templates
- [ ] Tests

#### LoadIso22301RequirementsCommand.php (Data Reuse)

**Zweck:** ISO 22301:2019 als loadbares Framework

##### Features
- 25 ISO 22301 Requirements
- ISO 27001 Control Mappings
- BIA & BC Strategy Requirements
- Automatic Compliance Tracking

##### Akzeptanzkriterien
- [ ] Command implementiert
- [ ] 25 Requirements definiert
- [ ] Control Mappings
- [ ] Tests
- [ ] Dokumentation

#### Penetration Testing Management (MITTEL)

##### Fehlende Features
1. **PenetrationTest Entity**
   - Test Type (internal/external/web-app/social-engineering)
   - Scope Definition
   - Test Date & Duration
   - Tester (internal/external)
   - Findings (ManyToMany zu Vulnerability)
   - Executive Summary
   - Status (planned/in-progress/completed/remediation)

2. **Findings Integration**
   - PT-Findings → Vulnerability Creation
   - Automated Risk Assessment
   - Remediation Tracking

##### Data Reuse Integration 🔄
- **PenetrationTest ↔ Vulnerability** (bereits geplant, gut!)
  - PT Findings → automatische Vulnerability-Erstellung
  - Severity Mapping (PT Finding → CVSS)
  - Vulnerability → Risk → Control (Full Chain!)
  - Zeitersparnis: ~60 Min pro PT (manuelle Vulnerability-Erfassung entfällt)

##### Akzeptanzkriterien
- [ ] PenetrationTest Entity
- [ ] Form Type
- [ ] Vulnerability Integration
- [ ] Templates
- [ ] Tests
- [ ] **Data Reuse:** PenetrationTest → Vulnerability Auto-Erstellung

#### Cryptography Management (MITTEL)

##### Fehlende Features
1. **CryptographicKey Entity**
   - Key-ID
   - Algorithm (AES-256, RSA-4096, etc.)
   - Key Length
   - Purpose (encryption/signing/authentication)
   - Creation Date
   - Expiration Date
   - Rotation Schedule
   - Owner
   - Storage Location

2. **Key Lifecycle Management**
   - Key Generation
   - Key Distribution
   - Key Rotation
   - Key Revocation
   - Key Archival

##### Data Reuse Integration 🔄
- **CryptographicKey ↔ Asset** (Key-Asset-Mapping)
  - Many-to-Many: Welche Assets nutzen welche Keys?
  - Asset.encryptionKeys Collection
  - Asset Protection: "Asset X verschlüsselt mit AES-256 Key Y"
  - Zeitersparnis: ~20 Min pro Asset Cryptography Review
- **CryptographicKey ↔ Control** (A.8.24 Cryptography Evidence)
  - Control A.8.24 Effectiveness = Key Rotation Compliance
  - "95% der Keys innerhalb Rotation Schedule" = Control Score
  - Expiry Alerts → Control Improvement Actions
- **CryptographicKey → Notification** (Rotation Reminders)
  - 30 Tage vor Expiry: Key Owner Notification
  - 7 Tage vor Expiry: Escalation

##### Akzeptanzkriterien
- [ ] CryptographicKey Entity
- [ ] Form Type
- [ ] Lifecycle Workflow
- [ ] Templates
- [ ] Tests
- [ ] **Data Reuse:** CryptographicKey ↔ Asset
- [ ] **Data Reuse:** CryptographicKey ↔ Control (A.8.24)
- [ ] **Data Reuse:** Key Rotation → Notification

---

### 🎯 Phase 6J: Module UI Completeness (Priorität KRITISCH)

**Status:** 🔄 Geplant
**Aufwand:** 3-4 Tage
**Impact:** KRITISCH (User Experience)

Diese Phase fokussiert sich auf die Vervollständigung der 5 Haupt-Module, die aktuell noch Platzhalter-Hinweise enthalten ("werden in der nächsten Phase implementiert").

#### 1. Asset Management - Vollständige Detailansicht & Formulare

**Aktueller Hinweis:** "Detailansicht und Erfassungsformulare werden in der nächsten Phase implementiert."

##### Fehlende Features
- 🔄 Vollständiges Asset Creation Form
  - Alle Felder inkl. Data Classification
  - Owner-Auswahl
  - Acceptable Use Policy
  - Monetary Value
  - Handling Instructions
- 🔄 Asset Edit Form
- 🔄 Asset Detail View (Show-Seite)
  - Related Risks anzeigen
  - Related BIA Scenarios anzeigen
  - Asset History (Audit Log)
- 🔄 Asset List mit erweiterten Filtern
  - Filter nach Type
  - Filter nach Classification
  - Filter nach Owner
  - Filter nach Status

##### Akzeptanzkriterien
- [ ] AssetType Form vollständig
- [ ] Create/Edit/Show Templates
- [ ] Filter UI implementiert
- [ ] Beziehungen zu Risk/BIA visualisiert
- [ ] Tests geschrieben
- [ ] **Hinweis-Text entfernt** aus translations/messages.de.yaml und messages.en.yaml

---

#### 2. Risk Management - Risikoregister & Behandlungspläne

**Aktueller Hinweis:** "Risikoregister und Behandlungspläne werden in der nächsten Phase implementiert."

##### Fehlende Features
- 🔄 Vollständiges Risikoregister
  - Alle Risiken in Tabellenform
  - Sortierung nach Risikowert
  - Filter nach Likelihood, Impact, Treatment
  - Export als PDF/Excel
- 🔄 Risk Treatment Plan UI
  - RiskTreatmentPlan Entity Integration
  - Treatment Timeline
  - Verantwortlichkeiten
  - Status-Tracking
- 🔄 Risk Owner Integration
  - Owner-Auswahl in Risk Form
  - Owner-Dashboard (meine Risiken)
  - Owner-Benachrichtigungen
- 🔄 Risk Appetite Visualization
  - Risk Appetite Levels anzeigen
  - Appetit vs. Tatsächliches Risiko
  - Ampel-System

##### Akzeptanzkriterien
- [ ] Risikoregister-Seite implementiert
- [ ] Risk Treatment Plan UI
- [ ] Risk Owner Integration
- [ ] Risk Appetite UI
- [ ] PDF/Excel Export
- [ ] Tests geschrieben
- [ ] **Hinweis-Text entfernt** aus translations

---

#### 3. Incident Management - Detaillierte Vorfallsdokumentation & Workflows

**Aktueller Hinweis:** "Detaillierte Vorfallsdokumentation und Workflows werden in der nächsten Phase implementiert."

##### Fehlende Features
- 🔄 Vollständige Incident Details
  - Alle NIS2-relevanten Felder
  - Timeline mit 24h/72h/1M Fristen
  - Cross-Border Impact
  - Affected Member States
  - Root Cause Analysis
  - Lessons Learned
- 🔄 Incident Workflow
  - Status-Übergänge (reported → investigating → contained → resolved → closed)
  - Approval-Workflow für Incident Closure
  - Automated Notifications
- 🔄 Incident Timeline Visualization
  - Visueller Timeline mit Meilensteinen
  - Countdown für NIS2 Fristen
  - Status-Ampel
- 🔄 Incident Report Generator
  - NIS2-konformer Incident Report
  - PDF-Export für Behörden
  - Attachment-Management

##### Akzeptanzkriterien
- [ ] Incident Details vollständig
- [ ] Workflow UI implementiert
- [ ] Timeline Visualization
- [ ] NIS2 Report Generator
- [ ] Tests geschrieben
- [ ] **Hinweis-Text entfernt** aus translations

---

#### 4. Context Management - Erfassungsformulare & Detaillierte Verwaltung

**Aktueller Hinweis:** "Erfassungsformulare und detaillierte Verwaltung werden in der nächsten Phase implementiert."

##### Fehlende Features
- 🔄 ISMSContext Create/Edit Form
  - Vollständige Formular-Felder
  - Organization Name, Scope Description
  - Internal/External Issues
  - Interested Parties
  - Legal/Regulatory Requirements
- 🔄 ISMSObjective CRUD
  - ISMSObjectiveType Form
  - Create/Edit/Show/Delete
  - Objective-Tracking (Target Date, Progress)
  - Objective-Reports
- 🔄 Context Detail View
  - Scope Visualization
  - Objectives Dashboard
  - Context History

##### Akzeptanzkriterien
- [ ] ISMSContextType Form vollständig
- [ ] ISMSObjectiveType Form implementiert
- [ ] Context & Objectives CRUD vollständig
- [ ] Context Detail View
- [ ] Tests geschrieben
- [ ] **Hinweis-Text entfernt** aus translations

---

#### 5. Audit Management - Audit-Planung, Checklisten & Berichte

**Aktueller Hinweis:** "Audit-Planung, Checklisten und Berichte werden in der nächsten Phase implementiert."

##### Fehlende Features
- 🔄 Audit Planning UI
  - Audit Scope Definition
  - Audit Schedule (Jahresplan)
  - Auditor Assignment
  - Audit Checklist Selection
- 🔄 Audit Checklists
  - Checklist Entity (optional)
  - ISO 27001 Clause-based Checklists
  - Control-based Checklists
  - Checklist Progress Tracking
- 🔄 Audit Execution
  - Finding Creation während Audit
  - Evidence Collection
  - Non-Conformity Tracking
- 🔄 Audit Reports
  - Audit Report Generator (PDF)
  - ISO 19011-konforme Berichte
  - Finding Summary
  - Recommendations
  - Follow-up Plan
- 🔄 AuditorCompetence Integration
  - Auditor-Qualifikation anzeigen
  - Competence Requirements

##### Akzeptanzkriterien
- [ ] Audit Planning UI implementiert
- [ ] Audit Checklists (Entity oder JSON-basiert)
- [ ] Audit Execution Workflow
- [ ] Audit Report Generator
- [ ] AuditorCompetence Integration
- [ ] Tests geschrieben
- [ ] **Hinweis-Text entfernt** aus translations

---

### 🔗 Phase 6K: Core Data Reuse Relationships (Priorität WICHTIG)

**Status:** 🔄 Geplant
**Aufwand:** 2-3 Tage
**Impact:** HOCH (Foundation für alle anderen Phasen)

Diese Phase implementiert die grundlegenden Data Reuse Beziehungen aus [DATA_REUSE_ANALYSIS.md](docs/DATA_REUSE_ANALYSIS.md), die noch nicht in anderen Phasen abgedeckt sind.

#### Training ↔ Control (WICHTIG)

**Problem:** Keine Verknüpfung zwischen Schulungen und Controls

##### Features
- **Training Entity Erweiterung**
  - relatedControls (ManyToMany)
  - Welche Controls erfordern diese Schulung?

- **Control Entity Erweiterung**
  - requiredTrainings (ManyToMany)
  - Welche Schulungen sind für dieses Control erforderlich?

##### Data Reuse Benefits 🔄
- **Training Coverage Analysis**
  - "Control A.6.3 erfordert Security Awareness Training"
  - "80% der Mitarbeiter geschult für Control A.6.3"
- **Training Gap Identification**
  - "Control A.5.16 hat 0 zugeordnete Trainings" → Gap!
  - Dashboard: "10 Controls ohne Training Coverage"
- **Compliance Evidence**
  - ISO 27001 A.6.3 Nachweisbarkeit
  - "Alle People Controls haben dokumentierte Trainings"
- **Zeitersparnis:** ~25 Min pro Control Review (Training-Mapping automatisch)

#### Training ↔ ComplianceRequirement (NÜTZLICH)

**Problem:** Awareness-Requirements nicht mit Trainings verknüpft

##### Features
- **Training Entity Erweiterung**
  - fulfilledRequirements (ManyToMany)
  - Welche Compliance-Anforderungen erfüllt diese Schulung?

- **ComplianceRequirement Entity Erweiterung**
  - requiredTrainings (ManyToMany)
  - DORA Art. 13.6, TISAX People Controls

##### Data Reuse Benefits 🔄
- **Compliance Training Matrix**
  - "DORA Art. 13.6 erfordert ICT Risk Training"
  - "Training durchgeführt → Requirement automatisch erfüllt"
- **Multi-Framework Efficiency**
  - Ein Training erfüllt mehrere Requirements (ISO 27001 + DORA + TISAX)
- **Automatic Fulfillment Tracking**
  - "TISAX 1.1.1 (Awareness) erfüllt durch 3 Trainings"
- **Zeitersparnis:** ~30 Min pro Compliance Audit (Training Evidence automatisch)

#### Akzeptanzkriterien
- [ ] Training ↔ Control Beziehung implementiert
- [ ] Training ↔ ComplianceRequirement Beziehung implementiert
- [ ] Form Types aktualisiert
- [ ] Training Coverage Dashboard
- [ ] Training Gap Analysis Report
- [ ] Compliance Training Matrix
- [ ] Tests geschrieben
- [ ] Dokumentation aktualisiert

---

### ✨ Phase 6E: Polish & Optimization (Priorität OPTIONAL)

**Status:** 📅 Backlog
**Aufwand:** 1-2 Tage
**Impact:** Niedrig

#### Ziele
- 📅 Code-Review und Refactoring
- 📅 Dokumentation vervollständigen
- 📅 UX-Verbesserungen
- 📅 Performance-Optimierung

#### Akzeptanzkriterien
- [ ] Code-Review durchgeführt
- [ ] PSR-12 Compliance geprüft
- [ ] Dokumentation aktualisiert
- [ ] Performance-Tests

---

## 📊 Phase 6 Zusammenfassung

**Gesamt-Aufwand Phase 6 (A-K):** 33-45 Tage

### Prioritäten
1. **KRITISCH** (19-23 Tage):
   - 6A: Form Types (1-2 Tage)
   - 6B: Test Coverage (3-4 Tage)
   - 6F: ISO 27001 Inhalt (2-3 Tage)
   - 6H: NIS2 Compliance (7-8 Tage)
   - 6J: Module UI Completeness (3-4 Tage)

2. **HOCH** (5-6 Tage):
   - 6I: BSI IT-Grundschutz (5-6 Tage)

3. **WICHTIG** (6-9 Tage):
   - 6C: Workflow-Management (2-3 Tage)
   - 6D: Compliance-Detail (2-3 Tage)
   - 6K: Core Data Reuse Relationships (2-3 Tage) ⭐ NEU!

4. **MITTEL** (3-4 Tage):
   - 6G: Multi-Standard (3-4 Tage)

5. **OPTIONAL** (1-2 Tage):
   - 6E: Polish & Optimization (1-2 Tage)

### 🔄 Data Reuse Impact

**Neue Data Reuse Beziehungen in Phase 6:**

| Beziehung | Phase | Zeitersparnis | Impact |
|-----------|-------|---------------|--------|
| Asset ↔ Control | 6F | ~15 Min/Control Review | Control Coverage Matrix |
| Asset Monetary Value → Risk Impact | 6F | ~15 Min/Risk | Auto-Berechnung |
| Risk ↔ Incident | 6F | ~30 Min/Risk Review | Risk Validation |
| Risk Treatment Plan → Control | 6F | ~20 Min/Treatment Plan | Implementation Tracking |
| BusinessProcess ↔ Risk | 6F | ~25 Min/Process Review | Business-aligned Risk |
| Incident ↔ Asset | 6H | ~10 Min/Incident | Asset Incident History |
| Incident Timeline → Notification | 6H | ~45 Min/Incident | Auto-Alerts |
| **Vulnerability → Risk** | 6H | **~40 Min/CVE** | **Auto-Risk aus CVE** 🚀 |
| Vulnerability ↔ Incident | 6H | ~15 Min/Incident | CVE Exploitation Tracking |
| Patch → Control | 6H | ~30 Min/Control Review | Effectiveness KPI |
| CryptographicKey ↔ Asset | 6I | ~20 Min/Asset | Key-Asset-Mapping |
| CryptographicKey ↔ Control | 6I | ~15 Min/Control | A.8.24 Evidence |
| PenetrationTest → Vulnerability | 6I | ~60 Min/PT | Auto-Vulnerability |
| Training ↔ Control | 6K | ~25 Min/Control Review | Training Coverage |
| Training ↔ ComplianceRequirement | 6K | ~30 Min/Audit | Compliance Evidence |
| ICTThirdPartyProvider ↔ Risk | 6G | ~25 Min/TPP | Third-Party Risk |
| TISAXAssessment ↔ Asset | 6G | ~20 Min/Assessment | AL-Level Tracking |

**Total Zeitersparnis:** ~450+ Minuten (7.5+ Stunden) pro Audit-Zyklus zusätzlich!

**Revolutionäre Features:**
- 🚀 **CVE → Automatisches Risk Management** (Phase 6H)
- 🚀 **PT Findings → Auto-Vulnerability → Auto-Risk** (Phase 6I)
- 🚀 **Training → Auto-Compliance-Evidence** (Phase 6K)

### Erwartete Vollständigkeit nach Phase 6

| Bereich | Aktuell | Nach Phase 6 | Ziel |
|---------|---------|--------------|------|
| **Technisch** | ~70% | ~95% | 95%+ |
| **ISO 27001 Inhalt** | 94.5% | 98%+ | 98%+ |
| **Multi-Standard** | 84% | 95%+ | 95%+ |
| **NIS2 Directive** | 68% ⚠️ | 95%+ ✅ | 95%+ |
| **BSI IT-Grundschutz** | 68% ⚠️ | 95%+ ✅ | 95%+ |
| **Test Coverage** | 26% | 80%+ | 80%+ |
| **Module mit vollständigem CRUD** | 70% | 95%+ | 95%+ |
| **Data Reuse Beziehungen** | 45% (9/20) | 100% ✅ (20/20) | 100% |
| **Zertifizierungsbereitschaft** | JA (Minor Findings) | 100% ✅ | 100% |

**Data Reuse Beziehungen Breakdown:**
- ✅ **Bereits implementiert (9):** Asset→Risk, Risk→Control, Control→Incident, Control→ComplianceRequirement, InternalAudit→Asset, BusinessProcess→Asset, ComplianceRequirement→ComplianceMapping (cross-framework), BCM→Asset (RTO/RPO), Incident→Control
- 🔄 **Phase 6 (11 neue):** Asset↔Control, Asset↔Incident, Risk↔Incident, Risk Treatment→Control, BusinessProcess↔Risk, Training↔Control, Training↔ComplianceRequirement, Vulnerability→Risk, Vulnerability↔Incident, Vulnerability↔Asset, Patch→Control, CryptographicKey↔Asset, CryptographicKey↔Control, PenetrationTest→Vulnerability, ICTThirdPartyProvider↔Risk, TISAXAssessment↔Asset, AuditorCompetence↔Training, RiskCommunication↔Risk
- **Gesamt nach Phase 6:** 20+ Data Reuse Beziehungen ✅

### Data Reuse: Loadbare Frameworks (Nach Phase 6)

| Framework | Status | Requirements | Mappings |
|-----------|--------|--------------|----------|
| ISO 27001:2022 | ✅ Vollständig | 93 Controls | Native |
| DORA (EU) | ✅ Vollständig | 30 Requirements | → ISO 27001 |
| TISAX (VDA ISA) | ✅ Vollständig | 32 Requirements | → ISO 27001 |
| **NIS2 (EU 2022/2555)** | 🔄 Phase 6H | 45 Requirements | → ISO 27001 |
| **BSI IT-Grundschutz 200-4** | 🔄 Phase 6I | 35 Requirements | → ISO 22301 |
| **ISO 22301:2019** | 🔄 Phase 6I | 25 Requirements | → ISO 27001 |
| ISO 19011:2018 | ✅ Entity-basiert | - | - |
| ISO 31000:2018 | ✅ Entity-basiert | - | - |
| ISO 27005:2022 | ✅ Entity-basiert | - | - |

**Total nach Phase 6:** 9 Frameworks, 260+ Requirements, vollautomatische Compliance-Tracking ✅

---

## 🚀 Phase 7: Enterprise Features (Geplant)

**Zeitraum:** Nach Phase 6
**Status:** 🔄 Geplant

### Implementierte Features
- ✅ Automated Testing (122 tests, 100% passing)
- ✅ CI/CD Pipeline (GitHub Actions)
- ✅ Docker Deployment

### Geplante Features
- 🔄 Multi-Tenancy Support (MSPs)
- 🔄 Advanced Analytics Dashboards
- 🔄 Mobile PWA
- 📅 Kubernetes Deployment

---

## 📅 Zukünftige Phasen (Backlog)

### Feature-Ideen
- JWT Authentication für Mobile Apps
- Real-time Notifications (WebSocket/Mercure)
- Advanced API Filters & Search
- Custom Report Builder
- Integration Marketplace (Slack, Teams, JIRA)
- AI-gestützte Risk Assessment
- Predictive Analytics für Incidents
- Automated Compliance Scoring

### Enterprise-Features
- Multi-Tenancy für MSPs
- White-Label Support
- Advanced Role-Based Dashboards
- Custom Workflows per Organization
- Advanced API Rate Limiting
- Webhook System
- SSO Integration (LDAP, Active Directory)

---

## 📈 Projekt-Metriken

### Aktueller Stand (Phase 6 Start)
- **Codezeilen:** ~31,650+ LOC
- **Entities:** 23 Doctrine Entities
- **Controllers:** 18+ Controllers
- **Templates:** 80+ Twig Templates
- **Services:** 12+ Business Logic Services
- **Commands:** 5+ Console Commands
- **Tests:** 122 tests, 228 assertions (100% passing)
- **Test Coverage:** ~26% (Ziel Phase 6: 80%+)
- **API Endpoints:** 30 REST Endpoints
- **Report Types:** 10 (5 PDF + 5 Excel)
- **Notification Types:** 5 automatisierte Typen

### Erwarteter Stand (Phase 6 Ende)
- **Entities:** ~32 Entities (+9: Vulnerability, Patch, MfaToken, RiskTreatmentPlan, RiskAppetite, CrisisTeam, PenetrationTest, CryptographicKey, RiskCommunication)
- **Controllers:** ~23+ Controllers (+5)
- **Templates:** ~130+ Templates (+50)
- **Commands:** ~9+ Commands (+4: NIS2, BSI, ISO 22301, weitere)
- **Tests:** ~400+ tests (Ziel: 80% Coverage)
- **Test Coverage:** 80%+
- **Report Types:** ~13 (SoA, NIS2 Incident, Audit, etc.)

---

## 🏆 Zertifizierungsbereitschaft

### ISO 27001:2022
- **Aktuell:** 94.5% ✅ (Zertifizierungsbereit mit Minor Findings)
- **Nach Phase 6F:** 98%+ ✅ (Vollständig Zertifizierungsbereit)

### ISO 22301:2019 (BCM)
- **Aktuell:** 100% ✅
- **Nach Phase 6I:** 100% ✅ (mit BSI IT-Grundschutz Integration)

### ISO 19011:2018 (Audit)
- **Aktuell:** 95% ⚠️
- **Nach Phase 6G:** 100% ✅ (mit AuditorCompetence)

### NIS2 Directive (EU 2022/2555)
- **Aktuell:** 68% ⚠️ (KRITISCH)
- **Nach Phase 6H:** 95%+ ✅ (Compliance-Ready)
- **Enforcement Datum:** 17.10.2024

### TISAX (VDA ISA)
- **Aktuell:** 75% ⚠️
- **Nach Phase 6G:** 95%+ ✅ (AL1/AL2-Ready)

### DORA (EU Financial Services)
- **Aktuell:** 85% ⚠️
- **Nach Phase 6G:** 95%+ ✅ (TPP Register + TLPT)

---

## 📞 Weitere Informationen

- **Projekt-README:** [README.md](README.md)
- **Module Completeness Audit:** [docs/MODULE_COMPLETENESS_AUDIT.md](docs/MODULE_COMPLETENESS_AUDIT.md)
- **Phase Reports:** [docs/](docs/) (PHASE2-5_COMPLETENESS_REPORT.md)
- **Issue Tracker:** [GitHub Issues](https://github.com/moag1000/Little-ISMS-Helper/issues)

---

**Stand:** 2025-11-10
**Version:** 1.0
**Nächste Aktualisierung:** Nach Abschluss Phase 6A
