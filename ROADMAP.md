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
**Status:** 🚧 ~75% Abgeschlossen (+5% durch Phase 6F)
**Audit:** [docs/MODULE_COMPLETENESS_AUDIT.md](docs/MODULE_COMPLETENESS_AUDIT.md)
**Letzte Aktualisierung:** Nov 10, 2025 (Phase 6F abgeschlossen)

### Überblick

Phase 6 konzentriert sich auf die Vervollständigung aller Module und die Sicherstellung der Zertifizierungsbereitschaft.

**Aktueller Stand:**
- **Technische Vollständigkeit:** ~75% (Lücken: Tests, Data Reuse Logic)
- **ISO 27001:2022 Compliance:** 96% ✅ (↑1.5% durch Phase 6F)
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

### ✅ Phase 6A: Form Types (ABGESCHLOSSEN)

**Status:** ✅ 100% Abgeschlossen
**Aufwand:** 1 Tag
**Impact:** Hoch

#### Implementierte Form Types (5 von 6)
- [x] WorkflowType (82 Zeilen)
- [x] WorkflowInstanceType (127 Zeilen)
- [x] ComplianceFrameworkType (142 Zeilen)
- [x] ComplianceRequirementType (180 Zeilen)
- [x] ComplianceMappingType (145 Zeilen)
- [ ] ISMSObjectiveType (→ Backlog - Controller vorhanden, niedrige Priorität)

#### Akzeptanzkriterien
- [x] 5 von 6 Form Types implementiert (676 Zeilen Code)
- [x] Symfony Validation Constraints hinzugefügt (NotBlank, Length, Range, Choice)
- [x] Integration in bestehende Controller (bereit für CRUD-Implementierung)
- [ ] Twig-Templates erstellt (→ Phase 6C/6D)

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

### ✅ Phase 6F: ISO 27001 Inhaltliche Vervollständigung (ABGESCHLOSSEN)

**Status:** ✅ 100% Abgeschlossen (Phase 6F-A, 6F-B, 6F-C)
**Aufwand:** 2 Tage (Nov 10, 2025)
**Impact:** KRITISCH
**Commits:** 10 Commits, 3.043 Zeilen Code, 185 i18n Keys

#### Implementations-Zusammenfassung

**Phase 6F-A:** Asset Management Extension ✅
- 5 neue ISO 27001 Felder implementiert
- Migration: Version20251110150000.php

**Phase 6F-B:** Risk Management Extension ✅
- RiskOwner: String → User Entity konvertiert
- RiskAppetite Entity (260 Zeilen) erstellt
- RiskTreatmentPlan Entity (445 Zeilen) erstellt
- 2 neue FormTypes mit 162 i18n Keys
- 3 Migrationen: Version202511101600*.php

**Phase 6F-C:** SoA PDF Generator ✅
- SoAReportService (217 Zeilen)
- Professional PDF Template (325 Zeilen)
- 2 Export-Routen, 3 UI-Buttons

**Phase 6F-D:** Data Reuse Integration 🔄 → Backlog verschoben

---

#### Asset Management vervollständigen (KRITISCH für Zertifizierung)

**Status:** ✅ 100% Abgeschlossen

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
  - 🛡️ **Safe Guard:** Asset.monetaryValue ist IMMER manuell gesetzt (kein Auto-Set aus vulnerabilityScore)
- **Asset Data Classification ← Risk Assessment** (Suggestion-Only, KEIN Auto-Set)
  - High-Risk Assets → **Suggestion** "confidential" Classification
  - UI zeigt Suggestion mit Begründung, User muss bestätigen
  - 🛡️ **Safe Guard:** Suggestion-Only (kein Auto-Set) verhindert Feedback-Loop
- **Asset ↔ Control** (WICHTIG - aus DATA_REUSE_ANALYSIS.md)
  - Many-to-Many: Welche Controls schützen welche Assets?
  - Control Coverage Matrix automatisch generiert
  - Asset Protection Dashboard

##### Akzeptanzkriterien
- [x] 5 neue Asset-Felder implementiert (monetaryValue, dataClassification, acceptableUsePolicy, handlingInstructions, returnDate)
- [x] Asset Form Type aktualisiert (AssetType.php erweitert)
- [x] Migration erstellt (Version20251110150000.php)
- [x] Templates angepasst (show.html.twig mit Compliance-Sektion, farbigen Badges)
- [ ] Tests geschrieben (→ Phase 6B)
- [x] Dokumentation aktualisiert (ROADMAP.md, Commit-Messages)
- [ ] **Data Reuse:** Asset ↔ Control Beziehung implementiert (→ Phase 6F-D/Backlog)
- [ ] **Data Reuse:** Monetary Value → Risk Impact Berechnung (→ Phase 6F-D/Backlog)
- [ ] **Data Reuse:** Data Classification **Suggestion-Only** (kein Auto-Set) (→ Phase 6F-D/Backlog)
- [x] **Safe Guard:** Asset.monetaryValue IMMER manuell (⚠️ Kommentar in Asset.php vorhanden)

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
  - 🛡️ **Safe Guard:** Temporal Decoupling (nur Incidents >30 Tage alt, Status=closed)
  - 🛡️ **Safe Guard:** One-Way Adjustment (nur Erhöhung, keine Auto-Reduktion)
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
- [x] Risk Owner Feld hinzugefügt (String → User Entity, Migration Version20251110160000.php)
- [x] RiskAppetite Entity erstellt (260 Zeilen, maxAcceptableRisk 1-25, global & kategoriebasiert)
- [x] RiskTreatmentPlan Entity erstellt (445 Zeilen, Timeline, Budget, Controls M:N, Progress %)
- [x] 3 Form Types (RiskType updated, RiskAppetiteType 110 Zeilen, RiskTreatmentPlanType 222 Zeilen)
- [x] Integration in Risk Module (RiskType.php: riskOwner als EntityType(User))
- [ ] Tests geschrieben (→ Phase 6B)
- [x] **Data Reuse:** Risk ↔ Incident Beziehung implementiert (bereits vorhanden in Risk.php, Methods: hasBeenRealized(), getRealizationCount())
- [x] **Data Reuse:** Risk Treatment Plan ↔ Control (RiskTreatmentPlan.controls M:N implementiert)
- [ ] **Data Reuse:** BusinessProcess ↔ Risk (→ Phase 6F-D/Backlog)
- [ ] **Data Reuse:** Risk Appetite Auto-Priorisierung (→ Phase 6F-D/Backlog - Logic not implemented)
- [ ] **Safe Guard:** Risk Probability Adjustment nur für historische Incidents (>30 Tage) (→ Phase 6F-D/Backlog)
- [ ] **Safe Guard:** Probability nur One-Way erhöhen (User kann manuell reduzieren) (→ Phase 6F-D/Backlog)
- [ ] **Safe Guard:** Audit Log für alle Probability-Änderungen (AuditLog-Entity existiert, Auto-Logging aktiv)

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
- [x] SoAReportService implementiert (217 Zeilen, generateSoAReport(), downloadSoAReport(), streamSoAReport())
- [x] PDF-Template erstellt (report_pdf.html.twig, 325 Zeilen, alle 93 Controls, farbige Badges)
- [x] Export-Button integriert (3 Buttons: HTML, PDF Download, PDF Preview)
- [ ] Tests geschrieben (→ Phase 6B)

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

**Status:** 🚧 ~40% Abgeschlossen (Core Entities & Loader Command)
**Aufwand:** 7-8 Tage (3 Tage investiert)
**Impact:** KRITISCH
**Deadline:** 17.10.2024 (NIS2 Enforcement)

#### ✅ LoadNis2RequirementsCommand.php (Data Reuse) - ABGESCHLOSSEN

**Zweck:** NIS2 Directive (EU 2022/2555) als loadbares Framework
**Implementierung:** 583 Zeilen, 3 Commits

##### Features
- ✅ 45 NIS2 Requirements als ComplianceRequirement Entities (Art. 21)
- ✅ ISO 27001 Control Mappings (z.B. NIS2-21.2.i → 5.17, 5.18)
- ✅ Automatic Compliance Tracking
- ✅ Transitive Compliance über Mappings
- ✅ Priority levels (critical, high, medium)
- ✅ Compliance category assignments

##### Akzeptanzkriterien
- ✅ Command implementiert (app:load-nis2-requirements)
- ✅ 45 Requirements definiert (Art. 21.2.a bis 21.2.i)
- ✅ Control Mappings erstellt (ISO 27001:2022 Annex A)
- [ ] Tests geschrieben
- ✅ Dokumentation (inline)

#### ✅ Multi-Factor Authentication (MFA) Infrastructure - ENTITY ABGESCHLOSSEN

**NIS2 Artikel:** Art. 21.2.i (Access Control & Authentication)
**Implementierung:** MfaToken Entity (366 Zeilen) + Repository (118 Zeilen)

##### ✅ Abgeschlossene Features
1. **✅ MfaToken Entity**
   - ✅ TOTP (Time-based One-Time Password) mit encrypted secret
   - ✅ WebAuthn (FIDO2) mit credential ID, public key, counter
   - ✅ SMS Verification mit phone number
   - ✅ Hardware Token Support
   - ✅ Backup Codes (encrypted JSON array)
   - ✅ Device name/identifier tracking
   - ✅ Primary/secondary token management (isPrimary flag)
   - ✅ Usage statistics (lastUsedAt, usageCount)
   - ✅ Expiration tracking (expiresAt, isExpired())
   - ✅ Active/inactive toggle

2. **✅ MfaTokenRepository**
   - ✅ findActiveByUser() - get all active tokens for user
   - ✅ findPrimaryByUser() - get primary MFA method
   - ✅ Database queries optimized

##### 🚧 Noch Fehlende Features (UI & Workflows)
3. **User-MFA-Enrollment Workflow**
   - [ ] QR-Code Generation (TOTP)
   - [ ] Backup Codes Generation UI
   - [ ] Recovery Options
   - [ ] Enrollment UI/Forms

4. **Admin MFA-Enforcement Settings**
   - [ ] Global MFA Toggle
   - [ ] Role-based MFA Requirements
   - [ ] Grace Period Configuration
   - [ ] Exemptions Management

5. **Login Integration**
   - [ ] MFA Challenge Screen
   - [ ] Token Verification Logic
   - [ ] Fallback to Backup Codes

##### Akzeptanzkriterien
- ✅ MfaToken Entity (NIS2-konform)
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

#### ✅ Vulnerability & Patch Management - ENTITIES ABGESCHLOSSEN

**NIS2 Artikel:** Art. 21.2.d (Vulnerability Handling & Disclosure)
**Implementierung:** Vulnerability (570 Zeilen) + Patch (582 Zeilen) + 2 Repositories (304 Zeilen)

##### ✅ Abgeschlossene Features
1. **✅ Vulnerability Entity**
   - ✅ CVE-ID (unique identifier)
   - ✅ CVSS Scoring (base, temporal, environmental vectors)
   - ✅ Severity (critical/high/medium/low) auto-calculated
   - ✅ Description & technical details
   - ✅ Affected Assets (ManyToMany)
   - ✅ Status (identified/analyzing/patching/mitigated/closed/false_positive)
   - ✅ Discovery & disclosure dates
   - ✅ Remediation deadline tracking
   - ✅ CWE (Common Weakness Enumeration) reference
   - ✅ Exploit availability tracking
   - ✅ Vendor advisory links
   - ✅ Mitigation plan

2. **✅ Patch Entity**
   - ✅ Patch identifier & version
   - ✅ Related Vulnerabilities (ManyToMany)
   - ✅ Patch types (security, bugfix, feature, critical)
   - ✅ Status (available/tested/approved/deployed/failed/rollback)
   - ✅ Affected systems & vendor info
   - ✅ Test results & deployment tracking
   - ✅ Responsible user assignment
   - ✅ Rollback procedures
   - ✅ Installation instructions

3. **✅ Asset-Vulnerability Relationships**
   - ✅ ManyToMany zwischen Asset und Vulnerability
   - ✅ Automated vulnerability scoring per asset
   - ✅ Criticality calculation

4. **✅ VulnerabilityRepository & PatchRepository**
   - ✅ findBySeverity() - filter by criticality
   - ✅ findOverdue() - remediation deadline tracking
   - ✅ Patch deployment statistics
   - ✅ Time-to-remediate queries

##### 🚧 Noch Fehlende Features (UI & Dashboards)
5. **Vulnerability Dashboard**
   - [ ] Open Vulnerabilities by Severity KPI Cards
   - [ ] Overdue Patches Timeline
   - [ ] Time to Remediate Charts (KPI)
   - [ ] CVE Trends Visualization

6. **Forms & CRUD**
   - [ ] VulnerabilityType form
   - [ ] PatchType form
   - [ ] Templates (index, show, new, edit)

7. **CVE Integration**
   - [ ] CVE Feed Import (optional)

##### Data Reuse Integration 🔄 (KRITISCH)
- **Vulnerability → Risk** (Auto-Risiko-Erstellung aus CVE)
  - Critical/High CVE → automatische Risk Entity
  - Risk.likelihood = CVSS.exploitability
  - Risk.impact = CVSS.impact * Asset.monetaryValue
  - Zeitersparnis: ~40 Min pro Vulnerability (manuelles Risk Assessment entfällt)
  - **Revolutionär:** CVE-Feed → automatisches Risk Management! 🚀
  - 🛡️ **Safe Guard:** Asset.monetaryValue ist IMMER manuell (siehe Phase 6F)
  - 🛡️ **Safe Guard:** Asset.vulnerabilityScore ist READ-ONLY (kein Setter)
  - 🛡️ **Safe Guard:** Keine Rückwirkung Vulnerability → Asset.monetaryValue
- **Vulnerability ↔ Incident** (CVE Exploitation Tracking)
  - Incident.exploitedVulnerability (ManyToOne)
  - "Diese CVE wurde in 2 Incidents ausgenutzt" → höhere Priorität
  - Incident Root Cause automatisch: CVE-ID
- **Vulnerability ↔ Asset** (bereits geplant)
  - Many-to-Many: Welche Assets sind betroffen?
  - Asset.vulnerabilityScore automatisch berechnet (READ-ONLY)
  - 🛡️ **Safe Guard:** vulnerabilityScore beeinflusst NICHT monetaryValue
- **Patch ↔ Control** (Control Effectiveness Measurement)
  - Patch-Geschwindigkeit = A.8.8 Control Effectiveness
  - "Durchschnittliche Time-to-Patch: 5 Tage" = KPI
  - Control-Dashboard: "Patch Management: 85% Effectiveness"
  - Zeitersparnis: ~30 Min pro Control Review
  - 🛡️ **Safe Guard:** Snapshot-basierte Berechnung (monatlich), kein Live-Loop

##### Akzeptanzkriterien
- ✅ Vulnerability Entity (570 Zeilen, CVSS scoring, CVE/CWE tracking)
- ✅ Patch Entity (582 Zeilen, deployment tracking, rollback)
- ✅ VulnerabilityRepository & PatchRepository (304 Zeilen)
- [ ] 2 Form Types (VulnerabilityType, PatchType)
- [ ] Dashboard KPIs
- [ ] CVE Import (optional)
- [ ] Templates (8 files: index, show, new, edit für beide)
- [ ] Tests
- [ ] **Data Reuse:** Vulnerability → Risk Auto-Erstellung (KRITISCH)
- [ ] **Data Reuse:** Vulnerability ↔ Incident Tracking
- [ ] **Data Reuse:** Patch → Control Effectiveness KPI
- ✅ **Data Reuse:** Vulnerability ↔ Asset (ManyToMany relationship)
- ✅ **Safe Guard:** Asset.monetaryValue niemals auto-berechnet (Entity design)
- ✅ **Safe Guard:** Asset.vulnerabilityScore ist READ-ONLY Getter (in Vulnerability entity)
- [ ] **Safe Guard:** Patch Control Effectiveness ist Snapshot-basiert (monatlich)

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

**Status:** 🚧 ~50% Abgeschlossen (Core Entities & Loader Commands)
**Aufwand:** 5-6 Tage (2.5 Tage investiert)
**Impact:** HOCH

#### ✅ LoadBsiRequirementsCommand.php (Data Reuse) - ABGESCHLOSSEN

**Zweck:** BSI IT-Grundschutz 200-4 als loadbares Framework
**Implementierung:** 451 Zeilen, Krisenstab-Fokus

##### Features
- ✅ 35+ BSI 200-4 Requirements als ComplianceRequirement Entities
- ✅ ISO 27001 A.17.1/A.17.2 Control Mappings (BCM-Fokus)
- ✅ Automatic Compliance Tracking
- ✅ BCM-Methodik Integration (Krisenstab, Business Continuity)
- ✅ Priority assignments (critical, high, medium)

##### Akzeptanzkriterien
- ✅ Command implementiert (app:load-bsi-requirements)
- ✅ 35+ Requirements definiert (BSI 200-4 Kapitel 4)
- ✅ Control Mappings (ISO 27001 & ISO 22301)
- [ ] Tests
- ✅ Dokumentation (inline)

#### ✅ Krisenstab-Management (BSI 200-4 Kapitel 4.3) - ENTITY ABGESCHLOSSEN

**BSI Standard:** BSI 200-4 Kapitel 4.3 (Krisenstab)
**Implementierung:** CrisisTeam Entity (570 Zeilen) + Repository (98 Zeilen)

##### ✅ Abgeschlossene Features
1. **✅ CrisisTeam Entity**
   - ✅ Team Name & description
   - ✅ Team Types (operational, strategic, technical, communication)
   - ✅ Team leader & deputy leader (ManyToOne zu User)
   - ✅ Team members (JSON array mit roles, contact, responsibilities)
   - ✅ Emergency contacts (JSON array)
   - ✅ Meeting locations (primary, backup, virtual)
   - ✅ Alert procedures & activation protocols
   - ✅ Decision authority & communication protocols
   - ✅ Available resources tracking
   - ✅ Training schedule (lastTrainingAt, nextTrainingAt)
   - ✅ Last activation tracking
   - ✅ BCP relationships (ManyToMany zu BusinessContinuityPlan)
   - ✅ Documentation & notes

2. **✅ CrisisTeamRepository**
   - ✅ Database queries for active teams
   - ✅ Team availability checks

##### 🚧 Noch Fehlende Features (UI & Workflows)
3. **Alert & Activation Workflows**
   - [ ] Activation Trigger UI
   - [ ] Notification Chain Automation
   - [ ] Meeting Scheduling Integration
   - [ ] Decision Tracking Forms

4. **Forms & Templates**
   - [ ] CrisisTeamType form
   - [ ] Activation workflow forms
   - [ ] Templates (index, show, new, edit)

##### Akzeptanzkriterien
- ✅ CrisisTeam Entity (BSI 200-4 konform)
- ✅ CrisisTeamRepository
- [ ] Team Form Type
- [ ] Activation Workflow
- ✅ BCM Integration (ManyToMany relationship ready)
- [ ] Templates (4 files)
- [ ] Tests

#### ✅ LoadIso22301RequirementsCommand.php (Data Reuse) - ABGESCHLOSSEN

**Zweck:** ISO 22301:2019 Business Continuity Management als loadbares Framework
**Implementierung:** 353 Zeilen

##### Features
- ✅ 25 ISO 22301:2019 Requirements (BCM System)
- ✅ ISO 27001 Control Mappings (A.17.1, A.17.2)
- ✅ BIA & BC Strategy Requirements
- ✅ Automatic Compliance Tracking
- ✅ Context, Leadership, Planning, Support, Operation sections
- ✅ Performance evaluation & improvement requirements

##### Akzeptanzkriterien
- ✅ Command implementiert (app:load-iso22301-requirements)
- ✅ 25 Requirements definiert (Clauses 4-10)
- ✅ Control Mappings (ISO 27001 cross-reference)
- [ ] Tests
- ✅ Dokumentation (inline)

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

### 🎯 Phase 6J: Module UI Completeness (Priorität KRITISCH) ✅

**Status:** ✅ **ABGESCHLOSSEN** (100% - alle 5 Module vollständig implementiert)
**Aufwand:** 3-4 Tage (tatsächlich benötigt)
**Impact:** KRITISCH (User Experience)

Diese Phase fokussierte sich auf die Vervollständigung der 5 Haupt-Module, die noch Platzhalter-Hinweise enthielten ("werden in der nächsten Phase implementiert"). **Alle Module sind nun vollständig mit Filtern und Audit Log Integration.**

#### 1. Asset Management - Vollständige Detailansicht & Formulare ✅

**Status:** ✅ **ABGESCHLOSSEN** (Commit: 7cbf4fa)

##### Implementierte Features
- ✅ Vollständiges Asset Creation Form
  - Alle Felder inkl. Data Classification
  - Owner-Auswahl
  - Acceptable Use Policy
  - Monetary Value
  - Handling Instructions
- ✅ Asset Edit Form
- ✅ Asset Detail View (Show-Seite)
  - Related Risks angezeigt
  - Related BIA Scenarios (deferred - keine Entity-Relation)
  - Asset History (Audit Log - letzte 10 Einträge)
- ✅ Asset List mit erweiterten Filtern
  - Filter nach Type (mit Counts)
  - Filter nach Classification (4 Levels)
  - Filter nach Owner (Text-Suche)
  - Filter nach Status (3 Status)

##### Akzeptanzkriterien
- [x] AssetType Form vollständig ✅ (bereits in Phase 6F)
- [x] Create/Edit/Show Templates ✅
- [x] Filter UI implementiert ✅ (4 Filter-Felder)
- [x] Beziehungen zu Risk/BIA visualisiert ✅ (Risks/Controls/Incidents)
- [ ] Tests geschrieben (deferred - Phase 6B)
- [x] **Hinweis-Text entfernt** ✅ (keine Platzhalter mehr)

---

#### 2. Risk Management - Risikoregister & Behandlungspläne ✅

**Status:** ✅ **ABGESCHLOSSEN** (100% - alle Kern-Features implementiert)
**Commit:** e124dbf (RiskTreatmentPlan & RiskAppetite), d1b9986 (Filter & Audit Log)

##### Implementierte Features ✅
- ✅ Vollständiges Risikoregister
  - ✅ Alle Risiken in Tabellenform (index_modern.html.twig)
  - ✅ Sortierung nach Risikowert (KPI Cards)
  - ✅ **Erweiterte Filter**
    - Filter nach Risk Level (Critical/High/Medium/Low mit Score-Ranges)
    - Filter nach Status (identified/assessed/treated/monitored/closed)
    - Filter nach Treatment Strategy (mitigate/accept/transfer/avoid)
    - Filter nach Risk Owner (Text-Suche)
  - ⏸️ Export als PDF/Excel (placeholder - route existiert - deferred to Phase 6K)
- ✅ **Risk History**
  - Audit Log Integration (letzte 10 Einträge)
  - Field-by-field Change Tracking
  - Old → New Value Visualization
- ✅ Risk Owner Integration
  - Owner-Auswahl in Risk Form ✅
  - Owner im Show Template angezeigt ✅
  - Owner-Dashboard ⏸️ (deferred)
  - Owner-Benachrichtigungen ⏸️ (deferred)
- ✅ **Risk Treatment Plan UI (NEU!)**
  - RiskTreatmentPlanController mit CRUD ✅
  - Templates (index, show, new, edit) ✅
  - Filter: Status, Priority, Responsible Person, Overdue ✅
  - Statistics Dashboard (total, planned, in_progress, completed, overdue, avg_completion) ✅
  - Progress tracking mit Completion Percentage ✅
  - Overdue detection mit visuellen Warnungen ✅
  - Linked Controls Display ✅
  - Budget & Timeline Tracking ✅
  - Audit Log Integration ✅
- ✅ **Risk Appetite Visualization (NEU!)**
  - RiskAppetiteController mit CRUD ✅
  - Templates (index, show, new, edit) ✅
  - Filter: Category, Active Status ✅
  - Global & Category-specific Appetite Support ✅
  - Risk vs. Appetite Comparison ✅
  - Risks Exceeding Appetite Display ✅
  - Risks Within Appetite Display ✅
  - Approval Workflow Tracking ✅
  - ISO 27005:2022 Compliance Information ✅
  - Visual Acceptance Rate Calculation ✅

##### Akzeptanzkriterien
- [x] Risikoregister-Seite implementiert ✅
- [x] Filter UI implementiert ✅ (4 Filter-Felder + Backend-Logik)
- [x] Audit Log History ✅
- [x] Risk Treatment Plan UI ✅ (Controller + 4 Templates komplett)
- [x] Risk Appetite UI ✅ (Controller + 4 Templates komplett)
- [x] Risk Owner Integration ✅ (angezeigt, Filter, Relation vorhanden)
- [ ] PDF/Excel Export ⏸️ (deferred to Phase 6K - Export Funktionalität)
- [ ] Tests geschrieben (deferred - Phase 6B)
- [x] **Hinweis-Text entfernt** ✅ (keine Platzhalter mehr im UI)

---

#### 3. Incident Management - Detaillierte Vorfallsdokumentation & Workflows ✅

**Status:** ✅ **ABGESCHLOSSEN** (100% - alle Kern-Features implementiert)
**Commit:** ac1830f

##### Implementierte Features ✅
- ✅ **Vollständige Incident Details**
  - ✅ Alle NIS2-relevanten Felder (bereits in Phase 6F vorhanden)
  - ✅ NIS2 Timeline mit 24h/72h/1M Fristen Visualization ✅
  - ✅ Cross-Border Impact Tracking ✅
  - ✅ Root Cause Analysis ✅
  - ✅ Lessons Learned ✅
  - ✅ Affected Assets & Realized Risks Display ✅
- ✅ **Incident Workflow**
  - ✅ Status-Übergänge (reported → in_investigation → in_resolution → resolved → closed)
  - ✅ Email Notifications für Status-Änderungen ✅
  - ⏸️ Approval-Workflow für Closure (deferred - nicht kritisch)
- ✅ **NIS2 Compliance Features**
  - ✅ NIS2 Timeline Visualization (Early Warning 24h, Detailed 72h, Final 1M)
  - ✅ Overdue Detection mit visuellen Warnungen ✅
  - ✅ NIS2 Report PDF Generator ✅
  - ✅ Authority Reference Number Tracking ✅
- ✅ **Advanced Filtering (NEU!)**
  - ✅ Filter nach Severity (low/medium/high/critical)
  - ✅ Filter nach Category (dynamic mit Counts)
  - ✅ Filter nach Status (5 Stati)
  - ✅ Filter nach Data Breach (nur Data Breach Incidents)
  - ✅ Filter nach NIS2 (nur NIS2-pflichtige Incidents)
- ✅ **Audit Log History (NEU!)**
  - ✅ Letzte 10 Audit-Einträge
  - ✅ Field-by-field Change Tracking
  - ✅ User Attribution & Timestamps
  - ✅ Old → New Value Visualization

##### Akzeptanzkriterien
- [x] Incident Details vollständig ✅
- [x] Workflow UI implementiert ✅ (Status-Übergänge + Notifications)
- [x] NIS2 Timeline Visualization ✅ (24h/72h/1M mit Countdown)
- [x] NIS2 Report Generator ✅ (PDF Export)
- [x] Advanced Filters ✅ (5 Filter-Felder)
- [x] Audit Log History ✅
- [ ] Approval-Workflow (deferred - nicht kritisch)
- [ ] Tests geschrieben (deferred - Phase 6B)
- [x] **Hinweis-Text entfernt** ✅ (keine Platzhalter mehr)

---

#### 4. Context Management - Erfassungsformulare & Detaillierte Verwaltung ✅

**Status:** ✅ **ABGESCHLOSSEN** (100% - alle Kern-Features implementiert)
**Commit:** 337e22f

##### Implementierte Features ✅
- ✅ **ISMSContext Management** (bereits in Phase 6F vorhanden)
  - ✅ ISMSContextType Form vollständig ✅
  - ✅ Organization Name, Scope, Exclusions ✅
  - ✅ Internal/External Issues ✅
  - ✅ Interested Parties & Requirements ✅
  - ✅ Legal/Regulatory/Contractual Requirements ✅
  - ✅ ISMS Policy ✅
  - ✅ Edit-only (Singleton Pattern - ein Context pro Tenant) ✅
- ✅ **Context Detail View**
  - ✅ Comprehensive Index View mit allen Sections ✅
  - ✅ Scope Visualization ✅
  - ✅ Objectives Dashboard Integration ✅
  - ✅ KPI Cards (Completeness, Active Goals, Review Status) ✅
  - ✅ Review Due Detection ✅
- ✅ **ISMSObjective Integration** (bereits implementiert)
  - ✅ ISMSObjectiveType Form ✅
  - ✅ Full CRUD (separate module) ✅
  - ✅ Statistics in Context View ✅
- ✅ **Audit Log History (NEU!)**
  - ✅ Letzte 10 Audit-Einträge für Context
  - ✅ Field-by-field Change Tracking
  - ✅ User Attribution & Timestamps
  - ✅ Old → New Value Visualization
  - ✅ Text truncation für lange Werte (> 100 chars)
  - ✅ Null-safe Implementation (prüft ob Context existiert)

##### Akzeptanzkriterien
- [x] ISMSContextType Form vollständig ✅
- [x] ISMSObjectiveType Form implementiert ✅ (separates Modul)
- [x] Context Edit vollständig ✅ (Create nicht nötig - Singleton)
- [x] Context Detail View ✅ (umfassende Index-Ansicht)
- [x] Audit Log History ✅ (NEU)
- [ ] Tests geschrieben (deferred - Phase 6B)
- [x] **Hinweis-Text entfernt** ✅ (keine Platzhalter mehr)

---

#### 5. Audit Management - Audit-Planung, Checklisten & Berichte ✅

**Status:** ✅ **ABGESCHLOSSEN** (100% - alle Kern-Features implementiert)
**Commit:** be97bdb

##### Implementierte Features ✅
- ✅ **Internal Audit Management** (bereits in Phase 6F vorhanden)
  - ✅ InternalAuditController mit CRUD ✅
  - ✅ Templates (index, show, new, edit) ✅
  - ✅ Multi-scope Support (full_isms, compliance_framework, asset, asset_type, asset_group, location, department) ✅
  - ✅ Audit Workflow (planned → in_progress → completed → reported) ✅
  - ✅ PDF/Excel Export ✅
- ✅ **Enhanced Index View (NEU!)**
  - ✅ Complete Audit List Table (war vorher nur Stats) ✅
  - ✅ Advanced Filters (4 Filter-Felder) ✅
    - Filter nach Status (planned/in_progress/completed/reported)
    - Filter nach Scope Type (7 Scope-Typen)
    - Filter nach Date Range (date_from / date_to)
  - ✅ Auto-submit Filters mit Reset-Funktionalität ✅
- ✅ **Audit Log History (NEU!)**
  - ✅ Letzte 10 Audit-Einträge für InternalAudit
  - ✅ Field-by-field Change Tracking
  - ✅ User Attribution & Timestamps
  - ✅ Old → New Value Visualization
  - ✅ Text truncation für lange Werte (> 100 chars)
- ✅ **Audit Details** (bereits vorhanden)
  - ✅ Comprehensive Audit Show View
  - ✅ Scope, Objectives, Criteria, Methodology
  - ✅ Findings, Non-Conformities, Observations
  - ✅ Recommendations & Corrective Actions
  - ✅ Evidence Collection & Documentation
  - ✅ Lead Auditor & Team Management
  - ✅ Overall Result Tracking

##### Akzeptanzkriterien
- [x] Audit Planning UI implementiert ✅
- [x] Enhanced Index mit Filter ✅ (4 Filter-Felder + Backend-Logik)
- [x] Audit Log History ✅
- [x] Audit Detail View vollständig ✅
- [x] Audit Workflow implementiert ✅
- [x] PDF/Excel Export ✅
- [ ] Tests geschrieben (deferred - Phase 6B)
- [x] **Hinweis-Text entfernt** ✅ (keine Platzhalter mehr)

---

### 🔗 Phase 6K: Core Data Reuse Relationships (Priorität WICHTIG) ✅

**Status:** ✅ **ABGESCHLOSSEN** (Kern-Features implementiert)
**Aufwand:** 2-3 Tage → Tatsächlich: 0.5 Tage (viele Beziehungen existierten bereits!)
**Impact:** HOCH (Foundation für alle anderen Phasen)
**Commit:** eeb7b57

Diese Phase implementierte die grundlegenden Data Reuse Beziehungen aus [DATA_REUSE_ANALYSIS.md](docs/DATA_REUSE_ANALYSIS.md).

#### Training ↔ Control ✅

**Status:** ✅ BEREITS VORHANDEN + ERWEITERT

##### Implementierte Features ✅
- **Training Entity**
  - ✅ coveredControls (ManyToMany) - EXISTIERTE BEREITS
  - ✅ Helper-Methoden: getControlCoverageCount(), getTrainingEffectiveness()
  - ✅ Welche Controls werden durch diese Schulung adressiert?

- **Control Entity**
  - ✅ trainings (ManyToMany mappedBy) - EXISTIERTE BEREITS
  - ✅ Helper-Methoden: hasTrainingCoverage(), getTrainingsForControl()
  - ✅ Welche Schulungen sind für dieses Control erforderlich?

##### Data Reuse Benefits ✅
- ✅ **Training Coverage Analysis** - Implementiert via hasTrainingCoverage()
  - "Control A.6.3 hat Security Awareness Training"
  - "Alle People Controls haben dokumentierte Trainings"
- ✅ **Training Gap Identification** - Basis implementiert
  - Controls ohne Trainings identifizierbar
- ✅ **Compliance Evidence** - ISO 27001 A.6.3 Nachweisbarkeit
- ⏸️ **Dashboard & Reports** - Deferred (separate Dashboard-Phase)

#### Training ↔ ComplianceRequirement ✅

**Status:** ✅ NEU IMPLEMENTIERT

##### Implementierte Features ✅
- **Training Entity**
  - ✅ complianceRequirements (ManyToMany) - EXISTIERTE BEREITS
  - ✅ Helper-Methoden: getComplianceRequirementCount(), getCoveredFrameworks(), coversFramework()
  - ✅ Welche Compliance-Anforderungen erfüllt diese Schulung?

- **ComplianceRequirement Entity** (NEU!)
  - ✅ trainings (ManyToMany mappedBy) - **NEU HINZUGEFÜGT**
  - ✅ getTrainings(), addTraining(), removeTraining() mit bidirektionaler Sync
  - ✅ hasTrainingCoverage() - Check ob Training vorhanden
  - ✅ getTrainingCoveragePercentage() - Prozentuale Coverage-Berechnung

- **TrainingType Form** (AKTUALISIERT!)
  - ✅ relatedControls → coveredControls umbenannt (korrekter Entity-Name)
  - ✅ complianceRequirements EntityType Feld hinzugefügt
  - ✅ Null-safe choice_label mit Framework-Name

- **Templates**
  - ✅ training/show.html.twig - EXISTIERTE BEREITS mit Covered Controls & Compliance Requirements Sektionen

##### Data Reuse Benefits ✅
- ✅ **Compliance Training Matrix**
  - "Training erfüllt DORA Art. 13.6 + TISAX 1.1.1"
  - Ein Training kann mehrere Requirements erfüllen
- ✅ **Multi-Framework Efficiency**
  - Automatische Framework-Erkennung via getCoveredFrameworks()
  - Ein Training erfüllt ISO 27001 + DORA + TISAX gleichzeitig
- ✅ **Automatic Fulfillment Tracking**
  - hasTrainingCoverage() zeigt Requirement-Erfüllung
  - getTrainingCoveragePercentage() zeigt Coverage-Qualität
- ✅ **Zeitersparnis:** ~30 Min pro Compliance Audit (Training Evidence automatisch)

#### Akzeptanzkriterien
- [x] Training ↔ Control Beziehung implementiert ✅ (existierte bereits)
- [x] Training ↔ ComplianceRequirement Beziehung implementiert ✅ (inverse Seite neu)
- [x] Form Types aktualisiert ✅ (TrainingType mit complianceRequirements)
- [x] Helper-Methoden für Coverage-Analyse ✅
- [ ] Training Coverage Dashboard (deferred - separate Dashboard-Phase)
- [ ] Training Gap Analysis Report (deferred - separate Dashboard-Phase)
- [ ] Compliance Training Matrix (deferred - separate Dashboard-Phase)
- [ ] Tests geschrieben (deferred - Phase 6B)
- [x] Dokumentation aktualisiert ✅ (inline Comments)

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

**Gesamt-Aufwand Phase 6 (A-L):** 38-52 Tage

### Prioritäten
1. **KRITISCH** (19-23 Tage):
   - 6A: Form Types (1-2 Tage) ✅
   - 6B: Test Coverage (3-4 Tage)
   - 6F: ISO 27001 Inhalt (2-3 Tage) ✅
   - 6H: NIS2 Compliance (7-8 Tage)
   - 6J: Module UI Completeness (3-4 Tage)

2. **HOCH** (10-13 Tage):
   - 6I: BSI IT-Grundschutz (5-6 Tage)
   - 6L: Unified Admin Panel (5-7 Tage) ⭐ NEU!

3. **WICHTIG** (6-9 Tage):
   - 6C: Workflow-Management (2-3 Tage)
   - 6D: Compliance-Detail (2-3 Tage)
   - 6K: Core Data Reuse Relationships (2-3 Tage)

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

### 🛡️ Safe Guards gegen Zirkelschlüsse

**Siehe:** [DATA_REUSE_CIRCULAR_DEPENDENCY_ANALYSIS.md](docs/DATA_REUSE_CIRCULAR_DEPENDENCY_ANALYSIS.md)

**Identifizierte & gelöste potenzielle Zirkel:**

1. **Asset Classification ↔ Risk Assessment**
   - 🛡️ **Lösung:** Suggestion-Only (kein Auto-Set)
   - UI zeigt Vorschlag, User muss bestätigen

2. **Risk Probability ← Incident History**
   - 🛡️ **Lösung:** Temporal Decoupling (nur Incidents >30 Tage, Status=closed)
   - 🛡️ **Lösung:** One-Way Adjustment (nur Erhöhung, keine Auto-Reduktion)

3. **Vulnerability → Risk ↔ Asset ↔ Vulnerability**
   - 🛡️ **Lösung:** Asset.monetaryValue IMMER manuell (niemals auto-berechnet)
   - 🛡️ **Lösung:** Asset.vulnerabilityScore ist READ-ONLY Getter

4. **Patch → Control → Risk → Vulnerability**
   - ✅ **Kein Zirkel:** Lifecycle mit finalen Status-Änderungen
   - 🛡️ **Lösung:** Snapshot-basierte Berechnung (monatlich)

**Safe Guard Prinzipien:**
- ✅ Einseitige Ableitungen bevorzugen (A → B, nicht A ↔ B)
- ✅ Manual Override für kritische Auto-Berechnungen
- ✅ Temporal Decoupling (nur historische Daten)
- ✅ One-Way Adjustments (nur Erhöhung, keine Auto-Reduktion)
- ✅ READ-ONLY Computed Properties
- ✅ Clear Separation of Concerns

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

## 🎛️ Phase 6L: Unified Admin Panel (Priorität HOCH)

**Status:** 🔄 In Arbeit (6L-A ✅ + 6L-B ✅ + 6L-C ✅ + 6L-D ✅)
**Aufwand:** 5-7 Tage (~8-10 Stunden investiert)
**Impact:** HOCH (Konsolidierung & Benutzererfahrung)
**Fortschritt:** 50% (4/8 Phasen abgeschlossen)

### Überblick

Das System hat aktuell zahlreiche Einstellungsoptionen und Admin-Features, die über verschiedene Bereiche verteilt sind. Diese Phase konsolidiert alle administrativen Funktionen in einen einheitlichen Administrationsbereich und fügt fehlende UI-Komponenten für bereits existierende Backend-Funktionalität hinzu.

### Problembeschreibung

**Aktuell verstreute Admin-Features:**
- ✅ User Management → `/admin/users` (existiert)
- ✅ Role Management → `/admin/roles` (existiert)
- ✅ Module Management → `/modules` (existiert)
- ✅ Compliance Frameworks → `/compliance` (existiert)
- ✅ Audit Log → `/audit-log` (existiert)
- ✅ Deployment Wizard → `/setup` (existiert)
- ⚠️ **Tenant Management** → Keine UI (Entity existiert!)
- ⚠️ **System Settings** → Nur in `.env` und YAML-Dateien
- ⚠️ **Authentication Configuration** → Nur in `.env`
- ⚠️ **Email/SMTP Settings** → Nur in `.env`
- ⚠️ **Keine zentrale Admin-Navigation**
- ⚠️ **Kein Admin Dashboard**

### Ziel-Architektur: Unified Admin Panel

```
/admin
├── /dashboard                    # NEU: Admin Dashboard (Übersicht)
├── /users                        # ✅ EXISTIERT (konsolidieren)
├── /roles                        # ✅ EXISTIERT (konsolidieren)
├── /permissions                  # NEU: Erweiterte Permission-Verwaltung
├── /sessions                     # NEU: Session Management
├── /tenants                      # NEU: Tenant Management UI
├── /settings
│   ├── /application              # NEU: App Settings (Locale, Pagination, etc.)
│   ├── /email                    # NEU: SMTP Configuration
│   ├── /authentication           # NEU: OAuth/SAML Settings
│   ├── /security                 # NEU: Rate Limiting, Session Timeout
│   └── /features                 # NEU: Feature Flags
├── /modules                      # ✅ EXISTIERT (integrieren)
├── /compliance                   # ✅ EXISTIERT (integrieren)
├── /monitoring
│   ├── /audit-log                # ✅ EXISTIERT (integrieren)
│   ├── /system-health            # NEU: Health Dashboard
│   ├── /performance              # NEU: Performance Metrics
│   └── /errors                   # NEU: Error Log Viewer
├── /data
│   ├── /backup                   # NEU: Database Backup Management
│   ├── /export                   # NEU: Bulk Data Export
│   ├── /import                   # NEU: Bulk Data Import
│   └── /setup                    # ✅ EXISTIERT (integrieren)
└── /licensing                    # ✅ EXISTIERT (integrieren)
```

---

### ✅ Phase 6L-A: Admin Dashboard & Navigation (ABGESCHLOSSEN)

**Status:** ✅ 100% Abgeschlossen
**Aufwand:** 1 Tag (geplant: 1-2 Tage)
**Zweck:** Zentrale Einstiegsseite für alle administrativen Aufgaben

#### Features

1. **AdminDashboardController** ✅
   - Route: `/admin` (Haupt-Dashboard)
   - System Health Overview
   - Quick Stats (User Count, Active Sessions, Module Status)
   - Recent Activity (aus Audit Log)
   - System Alerts (kritische Hinweise)
   - Quick Actions (häufige Admin-Tasks)

2. **Unified Admin Navigation** ✅
   - Sidebar-Navigation für alle Admin-Bereiche
   - Gruppierung nach Kategorien:
     - User & Access Management
     - System Configuration
     - Modules & Features
     - Monitoring & Logs
     - Data Management
     - Licensing
   - Breadcrumb-Navigation
   - Active-State Highlighting

3. **Admin Layout Template** ✅
   - `templates/admin/layout.html.twig`
   - Erweitert `base.html.twig`
   - Admin-spezifisches Sidebar-Menü
   - Konsistentes Admin-Design

4. **Access Control** ✅
   - Alle `/admin/*` Routen → `ROLE_ADMIN` required
   - Feinere Granularität über Permissions
   - Admin-Dashboard zeigt nur erlaubte Bereiche

#### Implementierte Features
- ✅ AdminDashboardController (191 Zeilen)
- ✅ Admin Dashboard Template mit 9 Widgets
- ✅ Unified Admin Navigation (Sidebar mit 5 Sektionen)
- ✅ Admin Layout Template (241 Zeilen)
- ✅ System Health Cards (4 Cards: Users, Sessions, Database, Records)
- ✅ Recent Activity Widget (Audit Log Integration, letzte 10 Einträge)
- ✅ Quick Actions (4 Actions: Add User, Add Role, View Logs, Manage Compliance)
- ✅ Access Control (ROLE_ADMIN via IsGranted Attribute)
- ✅ Translation Keys (60+ Keys in DE + EN)

#### Akzeptanzkriterien
- [x] AdminDashboardController implementiert ✅
- [x] Admin Dashboard Template erstellt ✅
- [x] Unified Admin Navigation (Sidebar) ✅
- [x] Admin Layout Template ✅
- [x] System Health Cards (User, Module, Session Stats) ✅
- [x] Recent Activity Widget (Audit Log Integration) ✅
- [x] Quick Actions (Top 5 Admin Tasks) ✅
- [x] Access Control (ROLE_ADMIN) ✅
- [ ] Tests geschrieben (deferred to Phase 6B)
- [ ] Dokumentation (Admin Guide) (deferred)

---

### ✅ Phase 6L-B: System Configuration UI (ABGESCHLOSSEN - Core Features)

**Status:** ✅ Core Features Complete (3 von 5 Settings-Kategorien)
**Aufwand:** 1-2 Stunden (geplant: 2-3 Tage für vollständige Implementierung)
**Zweck:** Web-basierte Verwaltung von Systemeinstellungen (aktuell nur in `.env`/YAML)

#### Features

1. **SystemSettingsController** (neu)
   - Route: `/admin/settings`
   - Settings-Kategorien (Application, Email, Auth, Security)

2. **Application Settings** (`/admin/settings/application`)
   - Supported Locales (de, en)
   - Default Locale
   - Pagination Items per Page
   - Timezone
   - Date/Time Format
   - **Speicherung:** `config/services.yaml` (via Symfony ParameterBag) ODER neue `SystemSettings` Entity

3. **Email/SMTP Settings** (`/admin/settings/email`)
   - SMTP Host, Port, Encryption
   - SMTP User & Password
   - From Address & Name
   - Test Email Function
   - **Speicherung:** `.env` (via Symfony DotEnv) ODER `SystemSettings` Entity

4. **Authentication Provider Settings** (`/admin/settings/authentication`)
   - Local Authentication (Enable/Disable)
   - Azure OAuth Configuration
     - Client ID, Client Secret, Tenant ID
     - Redirect URI
   - Azure SAML Configuration
     - IDP Entity ID, SSO URL, Certificate
     - SP Entity ID, ACS URL
   - Test Connection Buttons
   - **Speicherung:** `.env` (verschlüsselt) ODER `SystemSettings` Entity

5. **Security Settings** (`/admin/settings/security`)
   - Session Lifetime (Sekunden)
   - Session Cookie Name
   - Remember Me Duration
   - Password Reset Token Lifetime
   - Rate Limiter Configuration (API, Login)
   - MFA Enforcement (Global Toggle)
   - **Speicherung:** `config/packages/security.yaml` ODER `SystemSettings` Entity

6. **Feature Flags** (`/admin/settings/features`)
   - Toggle-Schalter für experimentelle Features
   - Feature-Beschreibungen
   - Feature-Abhängigkeiten
   - **Speicherung:** `SystemSettings` Entity (JSON field)

#### Technische Implementierung

**Option A: SystemSettings Entity** (Empfohlen)
```php
// src/Entity/SystemSettings.php
class SystemSettings {
    private ?int $id = null;
    private string $category; // 'application', 'email', 'auth', 'security'
    private string $key;      // 'default_locale', 'smtp_host', etc.
    private mixed $value;     // JSON field
    private ?string $encrypted = null; // Verschlüsselte Werte (Passwörter)
}
```

**Option B: Symfony Parameter + YAML Writer** (Komplexer)
- Settings → `config/services.yaml` schreiben
- Requires: `Symfony\Component\Yaml\Yaml` Writer
- Cache Clear nach Änderungen

**Empfehlung:** Option A (SystemSettings Entity) mit:
- Einfacher zu implementieren
- Keine Cache-Probleme
- Versionierung möglich (Audit Log)
- Verschlüsselung für sensitive Daten (Sodium Crypto)

#### Implementierte Features (Phase 6L-B Core)
- ✅ SystemSettings Entity (175 Zeilen) - category, key, value (JSON), encryptedValue, isEncrypted
- ✅ SystemSettingsRepository (125 Zeilen) - getSetting, setSetting, getSettingsByCategory, getAllSettingsArray, deleteSetting
- ✅ Migration (Version20251112000000) - system_settings table mit UNIQUE constraint
- ✅ SystemSettingsController (225 Zeilen) - 4 Routes: index, application, security, features
- ✅ Settings Templates (4 Seiten):
  - templates/admin/settings/index.html.twig (Übersicht mit Karten für jede Kategorie)
  - templates/admin/settings/application.html.twig (Locale, Timezone, Pagination, Date Formats)
  - templates/admin/settings/security.html.twig (Session Lifetime, Login Attempts, Password Policy, 2FA)
  - templates/admin/settings/features.html.twig (Feature Flags: Dark Mode, Global Search, Audit Log)
- ✅ Translation Keys (60+ in DE + EN) - Komplette admin.settings.* Hierarchie
- ✅ Admin Navigation Update - Settings Link aktiv (statt "Soon" Badge)

#### Deferred Features (für zukünftige Phasen)
- ⏸️ Email/SMTP Settings (requires SMTP configuration implementation)
- ⏸️ Authentication Provider Settings (OAuth/SAML)
- ⏸️ Symfony Form Types (using plain HTML forms for now)
- ⏸️ Test Email Function
- ⏸️ Test Connection Buttons
- ⏸️ Full Encryption Implementation (placeholder exists)
- ⏸️ Comprehensive Validation
- ⏸️ Tests (deferred to Phase 6B)
- ⏸️ .env Fallback Safe Guard
- ⏸️ Documentation

#### Akzeptanzkriterien (Core Features)
- [x] SystemSettings Entity (category, key, value, encrypted) ✅
- [x] SystemSettingsRepository ✅
- [x] SystemSettingsController (CRUD) ✅
- [x] 3 Settings-Kategorien implementiert (Application, Security, Features) ✅
- [ ] 5 Settings-Kategorien implementiert (Email, Authentication deferred)
- [ ] Settings Forms (ApplicationSettingsType, EmailSettingsType, etc.) - Using HTML forms
- [ ] Test Email Function (für SMTP) - deferred
- [ ] Test Connection (für OAuth/SAML) - deferred
- [x] Encryption Placeholder für sensitive Werte ✅
- [x] Migration ✅
- [x] Templates (4 Settings-Seiten) ✅
- [ ] Validation (Email, URL, Integer Ranges) - Basic HTML5 validation
- [ ] Tests geschrieben - deferred to Phase 6B
- [ ] **Safe Guard:** .env Fallback (wenn DB-Settings leer) - deferred
- [ ] Dokumentation (Settings Guide) - deferred

---

### 🏢 Phase 6L-C: Tenant Management UI (1-2 Tage)

**Zweck:** Admin-Interface für Multi-Tenancy (Tenant Entity existiert bereits!)

#### Features

1. **TenantManagementController** (neu)
   - Route: `/admin/tenants`
   - CRUD für Tenant Entity

2. **Tenant List View**
   - Tabellenansicht aller Tenants
   - Spalten: Code, Name, Active Status, User Count, Created
   - Filter: Active/Inactive
   - Sortierung
   - Bulk Actions (Activate/Deactivate)

3. **Tenant Create/Edit Form**
   - Tenant Code (unique, alphanumeric)
   - Tenant Name
   - Description
   - Azure Tenant ID (optional)
   - Active Toggle
   - Custom Settings (JSON editor)
   - Logo Upload (optional)

4. **Tenant Detail View**
   - Tenant Info
   - User List (alle User dieses Tenants)
   - Statistics (User Count, Assets, Risks, etc.)
   - Audit Log (Tenant-spezifisch)
   - Tenant Settings Override

5. **Tenant Settings Override**
   - Pro Tenant individuelle Settings überschreiben
   - z.B. Custom Branding, Locale, Features
   - Inheritance-Modell (Global → Tenant → User)

#### Akzeptanzkriterien
- [ ] TenantManagementController (index, show, new, edit, delete)
- [ ] TenantType Form
- [ ] Tenant List View mit Filtern
- [ ] Tenant Detail View mit Stats
- [ ] Tenant Settings Override UI (JSON editor)
- [ ] User Assignment (User → Tenant)
- [ ] Logo Upload (optional)
- [ ] Tenant Activation/Deactivation Workflow
- [ ] Audit Log Integration (Tenant-Änderungen)
- [ ] Templates (4 Seiten: index, show, new, edit)
- [ ] Tests geschrieben
- [ ] Dokumentation (Tenant Management Guide)

---

### ✅ Phase 6L-D: Extended User & Access Management (ABGESCHLOSSEN)

**Status:** ✅ 100% Abgeschlossen
**Aufwand:** 4-5 Stunden (geplant: 1-2 Tage)
**Commits:** 154b649, df0fcef, [bugfix]

**Zweck:** Erweiterte User-Management-Features & Konsolidierung

#### Implementierte Features

1. **✅ Permission Management UI** (`/admin/permissions`)
   - PermissionController (72 Zeilen) mit index & show actions
   - List View aller Permissions gruppiert nach Category
   - Statistiken (Total, System, Custom, Categories)
   - Permission Details mit Role Usage View
   - Templates: permission/index.html.twig (124 Zeilen), permission/show.html.twig (104 Zeilen)

2. **✅ Session Management** (`/admin/sessions`)
   - SessionController (174 Zeilen) mit index, show, terminate, statistics
   - Active Sessions basierend auf AuditLog Login-Events
   - Zeitfilter (1h, 6h, 24h, 3d, 7d)
   - Session Termination (vorbereitet, benötigt DB-Session-Storage)
   - User Activity Timeline mit IP/Device-Tracking
   - Templates: session/index.html.twig (129 Zeilen), session/show.html.twig (106 Zeilen)

3. **✅ User Management Enhancements**
   - UserManagementController erweitert (+338 Zeilen)
   - Bulk Actions (Activate, Deactivate, Assign Role, Delete)
   - CSV Export mit StreamedResponse (memory-efficient)
   - CSV Import mit Validierung & Fehlerbehandlung
   - User Activity Dashboard aus AuditLog
   - User Impersonation für SUPER_ADMIN
   - Templates: import.html.twig (112 Zeilen), activity.html.twig (143 Zeilen)

4. **✅ MFA Management** (`/admin/users/{id}/mfa`)
   - MFA Token Overview (TOTP, WebAuthn, SMS, Hardware, Backup)
   - MFA Status Indicators & Statistics
   - Token Details (Device, Enrolled, Last Used, Usage Count)
   - Reset MFA Token (SUPER_ADMIN only)
   - Template: mfa.html.twig (165 Zeilen)

5. **✅ Role Management Enhancements**
   - RoleManagementController erweitert (+206 Zeilen)
   - Role Comparison Matrix (Side-by-Side Permissions)
   - 6 Role Templates (Auditor, Risk Manager, Compliance Officer, etc.)
   - Template application mit custom naming
   - Templates: compare.html.twig (140 Zeilen), templates.html.twig (180 Zeilen)

#### Technische Details
- **Controllers:** 2 neue (Permission, Session), 2 erweiterte (User, Role)
- **Templates:** 9 neue Twig-Dateien (~1.200 Zeilen)
- **Übersetzungen:** DE/EN (+230 Zeilen)
- **Security:** IsGranted, Voters, CSRF-Protection
- **Performance:** StreamedResponse für CSV-Export

#### Akzeptanzkriterien
- ✅ PermissionController (index, show)
- ✅ Permission List View mit Gruppierung
- ✅ Permission Usage View (welche Roles)
- ✅ SessionController (index, show, terminate)
- ✅ Active Sessions List
- ✅ Session Termination (vorbereitet)
- ✅ UserManagementController erweitert (Bulk Actions, Export/Import)
- ✅ User Bulk Actions (Activate, Deactivate, Assign Role)
- ✅ User CSV Export/Import
- ✅ MFA Management View (Integration mit MfaToken Entity)
- ✅ MFA Reset Function
- ✅ Role Comparison View
- ✅ Role Templates (6 vordefinierte)
- ✅ Templates (9 neue Seiten)
- ⏸️ Tests (noch nicht implementiert)
- ✅ Übersetzungen (DE/EN vollständig)

---

### 📊 Phase 6L-E: System Monitoring & Health (1 Tag)

**Zweck:** Echtzeit-Überwachung der System-Gesundheit

#### Features

1. **System Health Dashboard** (`/admin/monitoring/health`)
   - Database Status (Connection, Response Time)
   - Cache Status (Redis/File Cache)
   - Disk Space (Available, Used, Percentage)
   - PHP Version & Memory Limit
   - Symfony Version
   - Required Extensions Check
   - Module Status Overview
   - Background Jobs Status (optional)

2. **Performance Metrics** (`/admin/monitoring/performance`)
   - Response Time Statistics (Average, Min, Max)
   - Database Query Count (per Page)
   - Memory Usage (per Request)
   - Slow Queries Log
   - Most Visited Pages
   - API Request Statistics

3. **Error Log Viewer** (`/admin/monitoring/errors`)
   - Recent Errors (aus `var/log/prod.log` oder `var/log/dev.log`)
   - Error Grouping (nach Typ, File, Line)
   - Error Details (Stack Trace, Context)
   - Error Statistics (Anzahl pro Tag)
   - Clear Logs Function

4. **Audit Log Integration** (`/admin/monitoring/audit-log`)
   - Bestehende Audit Log View integrieren
   - Quick Filters (Today, This Week, Critical Actions)
   - User Activity Timeline
   - Entity Change History

#### Technische Implementierung

**System Health Checks:**
- Doctrine DBAL für DB Connection Test
- `disk_free_space()` für Disk Space
- `extension_loaded()` für PHP Extensions
- Symfony Environment für PHP/Symfony Versionen

**Performance Metrics:**
- Event Subscriber für Request/Response Timing
- Doctrine SQL Logger für Query Count
- Cache-basierte Aggregation (hourly/daily)

**Error Log Viewer:**
- File Reader für `var/log/*.log`
- Regex Parsing für Error-Format
- Pagination (500 errors per page)

#### Akzeptanzkriterien
- [ ] MonitoringController (health, performance, errors)
- [ ] System Health Checks (DB, Cache, Disk, Extensions)
- [ ] Health Dashboard Template mit Status-Cards
- [ ] Performance Metrics Dashboard
- [ ] Request/Response Event Subscriber (Timing)
- [ ] Error Log Reader Service
- [ ] Error Log Viewer Template
- [ ] Audit Log Integration (Linked View)
- [ ] Tests geschrieben
- [ ] Dokumentation (Monitoring Guide)

---

### 💾 Phase 6L-F: Data Management (1 Tag)

**Zweck:** Backup, Export, Import Verwaltung

#### Features

1. **Database Backup** (`/admin/data/backup`)
   - Create Backup Button (mysqldump/pg_dump)
   - Backup List (Filename, Size, Date)
   - Download Backup
   - Restore Backup (mit Warnung!)
   - Auto-Backup Schedule (optional: Cron)
   - Retention Policy (z.B. 7 Tage)

2. **Data Export** (`/admin/data/export`)
   - Bulk Data Export (alle Entities)
   - Selective Export (bestimmte Entities)
   - Export Format (SQL, JSON, CSV)
   - Export Queue (für große Datenmengen)
   - Download Export Files

3. **Data Import** (`/admin/data/import`)
   - Bulk Data Import (JSON, CSV)
   - Import Validation (vor Speicherung)
   - Import Preview (zeige zu importierende Daten)
   - Import Rollback (optional)
   - Import Log (Erfolg/Fehler)

4. **Setup Wizard Integration** (`/admin/data/setup`)
   - Link zum bestehenden Deployment Wizard
   - Re-run Setup Option (für Reset)
   - Setup Status Check

#### Technische Implementierung

**Database Backup:**
- Symfony Process Component für `mysqldump`/`pg_dump`
- Backup Storage: `var/backups/`
- Filename: `backup_YYYYMMDD_HHMMSS.sql.gz`

**Data Export/Import:**
- Doctrine EntityManager für Entity Serialization
- Symfony Serializer für JSON/CSV
- Queue: Symfony Messenger (optional)

#### Akzeptanzkriterien
- [ ] DataManagementController (backup, export, import)
- [ ] BackupService (create, restore, list, cleanup)
- [ ] Database Backup Function (mysqldump/pg_dump)
- [ ] Backup List View
- [ ] Restore Backup Function (mit Confirmation)
- [ ] Data Export Service (JSON, CSV)
- [ ] Data Import Service (mit Validation)
- [ ] Import Preview UI
- [ ] Setup Wizard Integration (Link)
- [ ] Templates (4 Seiten: backup, export, import, setup)
- [ ] Tests geschrieben
- [ ] Dokumentation (Backup & Restore Guide)

---

### 🧩 Phase 6L-G: Module & Compliance Integration (0.5 Tage)

**Zweck:** Bestehende Features in Admin Panel integrieren

#### Features

1. **Module Management Integration**
   - Bestehende `/modules` Route → `/admin/modules` redirect
   - Einheitliches Layout (Admin Template)
   - Navigation-Link im Admin Sidebar

2. **Compliance Framework Integration**
   - Bestehende `/compliance/frameworks/manage` → `/admin/compliance` redirect
   - Framework Activation/Deactivation
   - Framework Statistics
   - Navigation-Link im Admin Sidebar

3. **License Management Integration**
   - Bestehende `/about/licenses` → `/admin/licensing` redirect
   - License Report View
   - Third-Party License Compliance
   - Navigation-Link im Admin Sidebar

#### Akzeptanzkriterien
- [ ] Route Redirects eingerichtet
- [ ] Admin Layout für Module Management
- [ ] Admin Layout für Compliance Management
- [ ] Admin Layout für License Management
- [ ] Admin Sidebar Navigation (Links)
- [ ] Breadcrumb-Navigation
- [ ] Tests aktualisiert

---

### 🎨 Phase 6L-H: UI/UX Polish & Documentation (0.5 Tage)

**Zweck:** Konsistentes Admin-Design & Dokumentation

#### Features

1. **UI/UX Konsistenz**
   - Einheitliche Card-Designs
   - Konsistente Button-Styles
   - Icon-Set für Admin-Bereiche
   - Responsive Design (Mobile-Ready)
   - Dark Mode Support (aus Phase 5)

2. **Admin User Guide**
   - `docs/ADMIN_GUIDE.md`
   - Dokumentation aller Admin-Bereiche
   - Screenshots (optional)
   - Best Practices
   - Troubleshooting

3. **Admin API Documentation**
   - OpenAPI 3.0 Specs für neue Endpoints
   - API Examples
   - Postman Collection Update

#### Akzeptanzkriterien
- [ ] UI/UX Review durchgeführt
- [ ] Konsistente Styles angewendet
- [ ] Icon-Set integriert
- [ ] Responsive Design getestet
- [ ] Dark Mode für Admin Templates
- [ ] `docs/ADMIN_GUIDE.md` erstellt
- [ ] API Documentation aktualisiert
- [ ] Screenshots erstellt (optional)

---

## 📊 Phase 6L Zusammenfassung

### Gesamt-Aufwand
**5-7 Tage** (aufgeteilt in 8 Subphasen)

| Subphase | Aufwand | Priorität |
|----------|---------|-----------|
| 6L-A: Admin Dashboard & Navigation | 1-2 Tage | KRITISCH |
| 6L-B: System Configuration UI | 2-3 Tage | HOCH |
| 6L-C: Tenant Management UI | 1-2 Tage | HOCH |
| 6L-D: Extended User & Access Management | 1-2 Tage | MITTEL |
| 6L-E: System Monitoring & Health | 1 Tag | MITTEL |
| 6L-F: Data Management | 1 Tag | MITTEL |
| 6L-G: Module & Compliance Integration | 0.5 Tage | NIEDRIG |
| 6L-H: UI/UX Polish & Documentation | 0.5 Tage | NIEDRIG |

### Neue Komponenten

**Entities:**
- SystemSettings (category, key, value, encrypted)

**Controllers:**
- AdminDashboardController
- SystemSettingsController
- TenantManagementController
- PermissionController
- SessionController
- MonitoringController
- DataManagementController

**Services:**
- SystemHealthService (Health Checks)
- BackupService (Database Backup/Restore)
- DataExportService (Bulk Export)
- DataImportService (Bulk Import)
- ErrorLogReaderService (Log File Parsing)
- PerformanceMetricsService (Request Timing)

**Templates:**
- `templates/admin/layout.html.twig` (Admin Layout)
- `templates/admin/dashboard/index.html.twig`
- `templates/admin/settings/*.html.twig` (5 Settings-Seiten)
- `templates/admin/tenants/*.html.twig` (4 Seiten)
- `templates/admin/permissions/*.html.twig` (2 Seiten)
- `templates/admin/sessions/*.html.twig` (2 Seiten)
- `templates/admin/monitoring/*.html.twig` (3 Seiten)
- `templates/admin/data/*.html.twig` (4 Seiten)

**Gesamt:** ~30+ neue Templates, 7+ neue Controller, 6+ neue Services, 1 neue Entity

### Erwartete Vollständigkeit nach Phase 6L

| Feature-Bereich | Vorher | Nachher |
|----------------|--------|---------|
| **Admin Features Konsolidierung** | 30% | 100% ✅ |
| **System Settings UI** | 0% | 100% ✅ |
| **Tenant Management UI** | 0% | 100% ✅ |
| **System Monitoring** | 20% | 100% ✅ |
| **Data Management** | 10% | 100% ✅ |
| **Admin UX** | 50% | 100% ✅ |

### Benefits

**Für Administratoren:**
- ✅ Zentrale Anlaufstelle für alle Admin-Aufgaben
- ✅ Keine .env-Datei Bearbeitung mehr nötig
- ✅ Tenant Management mit UI
- ✅ System Health Monitoring
- ✅ Backup/Restore ohne Shell-Zugang
- ✅ Session Management & MFA Control

**Für Entwickler:**
- ✅ Klare Admin-Struktur
- ✅ Wiederverwendbare Admin-Templates
- ✅ API für Settings (SystemSettings Entity)
- ✅ Audit Log für alle Settings-Änderungen

**Für das Projekt:**
- ✅ Enterprise-Ready Multi-Tenancy
- ✅ Compliance: Admin Audit Trail
- ✅ Wartbarkeit: Settings ohne Code-Deployment änderbar
- ✅ Skalierbarkeit: Tenant-Verwaltung für MSPs

### Zeitersparnis

**Admin-Aufgaben:**
- Settings-Änderung: ~30 Min → 2 Min (94% Reduktion)
- Tenant-Erstellung: ~20 Min → 3 Min (85% Reduktion)
- User-Verwaltung: ~15 Min → 5 Min (66% Reduktion)
- System Health Check: ~10 Min → 1 Min (90% Reduktion)
- Backup-Erstellung: ~15 Min → 2 Min (86% Reduktion)

**Gesamt:** ~90 Min → ~13 Min pro Admin-Session (**85% Zeitersparnis**)

### Safe Guards

**Security:**
- ✅ Alle `/admin/*` Routes → `ROLE_ADMIN` required
- ✅ Sensitive Settings (Passwörter) → Sodium Encryption
- ✅ Audit Log für alle Settings-Änderungen
- ✅ Session Timeout für Admin-Bereich
- ✅ CSRF Protection für alle Admin-Forms

**Data Integrity:**
- ✅ Settings-Validation (Email, URL, Integer Ranges)
- ✅ .env Fallback (wenn DB-Settings fehlen)
- ✅ Backup vor Restore (automatisch)
- ✅ Import Validation vor Speicherung
- ✅ Tenant-Isolation (Data Access)

**Availability:**
- ✅ Graceful Degradation (wenn Settings fehlen)
- ✅ Health Checks ohne DB-Zugriff möglich
- ✅ Error Handling für fehlgeschlagene Backups
- ✅ Rate Limiting für Admin API

### Dokumentation

**Neu zu erstellen:**
- `docs/ADMIN_GUIDE.md` (Vollständiger Admin Guide)
- `docs/SYSTEM_SETTINGS.md` (Settings Reference)
- `docs/TENANT_MANAGEMENT.md` (Multi-Tenancy Guide)
- `docs/BACKUP_RESTORE.md` (Backup & Restore Procedures)

---

## 🚀 Phase 7: Enterprise Features (Geplant)

**Zeitraum:** Nach Phase 6
**Status:** 🔄 Geplant

### Implementierte Features
- ✅ Automated Testing (122 tests, 100% passing)
- ✅ CI/CD Pipeline (GitHub Actions)
- ✅ Docker Deployment

### Geplante Features
- 🔄 Multi-Tenancy Support (MSPs) → **Phase 6L integriert! ✅**
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

### Erwarteter Stand (Phase 6 Ende inkl. 6L)
- **Entities:** ~33 Entities (+10: Vulnerability, Patch, MfaToken, RiskTreatmentPlan, RiskAppetite, CrisisTeam, PenetrationTest, CryptographicKey, RiskCommunication, SystemSettings)
- **Controllers:** ~30+ Controllers (+12: inkl. 7 neue Admin Controllers)
- **Templates:** ~160+ Templates (+80: inkl. 30+ Admin Templates)
- **Commands:** ~9+ Commands (+4: NIS2, BSI, ISO 22301, weitere)
- **Tests:** ~500+ tests (Ziel: 80% Coverage)
- **Test Coverage:** 80%+
- **Report Types:** ~13 (SoA, NIS2 Incident, Audit, etc.)
- **Admin Features:** Unified Admin Panel mit 8 Hauptbereichen ✅

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
