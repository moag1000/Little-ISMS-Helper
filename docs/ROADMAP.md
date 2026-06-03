# Little ISMS Helper - Development Roadmap

## Kurzfristig (1-2 Wochen)

### 1. Accessibility Improvements

#### Bestehende Forms zu accessible component migrieren ✅ ERLEDIGT
- **Ziel**: Alle Forms auf das neue `_form_field.html.twig` Component migrieren
- **Priorität**: Hoch
- **Aufwand**: ~3-5 Tage
- **Status**: ✅ Abgeschlossen - 20/20 Forms barrierefrei (100%)
- **Schritte**:
  - [x] Inventur aller bestehenden Forms durchführen
  - [x] Priorisierung nach Nutzungshäufigkeit
  - [x] Migration der Top 10 meist-genutzten Forms
  - [x] Migration weiterer kritischer BCM-Forms
  - [x] Restliche Forms migrieren
  - [x] Accessibility-Tests durchführen
- **Accessibility-Test-Ergebnisse** (November 2025):
  - ✅ **ARIA-Attribute**: aria-invalid, aria-describedby, aria-required, aria-live korrekt implementiert
  - ✅ **Keyboard-Navigation**: Logische Tab-Reihenfolge, sichtbare Fokus-Indikatoren (box-shadow)
  - ✅ **Screen Reader**: Semantic landmarks (role="main", "navigation", "banner"), Skip-Links vorhanden
  - ✅ **Fehlerbehandlung**: Error messages mit role="alert" und aria-live="assertive"
  - ✅ **Dekorative Icons**: Alle mit aria-hidden="true" markiert
  - ✅ **Fieldset/Legend**: Semantisch korrekte Gruppierung für Form-Sections
- **Bereits migrierte Forms** (20 Dateien):
  - ✅ `templates/risk/_form.html.twig` - Vollständig barrierefrei
  - ✅ `templates/asset/_form.html.twig` - Vollständig barrierefrei
  - ✅ `templates/document/_form.html.twig` - Vollständig barrierefrei
  - ✅ `templates/audit/_form.html.twig` - Vollständig barrierefrei
  - ✅ `templates/user_management/_form.html.twig` - Vollständig barrierefrei
  - ✅ `templates/business_process/_form.html.twig` - Vollständig barrierefrei
  - ✅ `templates/admin/tenants/form.html.twig` - Vollständig barrierefrei
  - ✅ `templates/compliance/requirement/new.html.twig` - Neu migriert (November 2025)
  - ✅ `templates/compliance/requirement/edit.html.twig` - Neu migriert (November 2025)
  - ✅ `templates/compliance/mapping/new.html.twig` - Neu migriert (November 2025)
  - ✅ `templates/compliance/mapping/edit.html.twig` - Neu migriert (November 2025)
  - ✅ `templates/bc_exercise/new.html.twig` - Neu migriert (November 2025)
  - ✅ `templates/bc_exercise/edit.html.twig` - Neu migriert (November 2025)
  - ✅ `templates/business_continuity_plan/new.html.twig` - Neu migriert (November 2025)
  - ✅ `templates/business_continuity_plan/edit.html.twig` - Neu migriert (November 2025)
  - ✅ `templates/context/edit.html.twig` - Neu migriert (November 2025)
  - Plus 4 weitere bereits existierende barrierefreie Forms
- **WCAG 2.1 Kriterien erfüllt**:
  - 1.3.1 Info and Relationships (Level A) ✅
  - 3.3.1 Error Identification (Level A) ✅
  - 3.3.2 Labels or Instructions (Level A) ✅
  - 3.3.3 Error Suggestion (Level AA) ✅
  - 4.1.3 Status Messages (Level AA) ✅
- **Erfolgskriterien**: ✅ Alle erfüllt
  - ✅ Alle Forms nutzen das accessible component
  - ✅ ARIA-Labels korrekt implementiert
  - ✅ Keyboard-Navigation funktioniert
  - ✅ Screen Reader kompatibel

#### Table Scope Attributes für Accessibility ✅ ERLEDIGT
- **Ziel**: Alle Tabellen mit korrekten scope-Attributen versehen
- **Priorität**: Mittel
- **Aufwand**: ~1-2 Tage
- **Status**: ✅ Abgeschlossen (November 2025)
- **Schritte**:
  - [x] Audit aller Tabellen im System
  - [x] `scope="col"` für Spaltenheader hinzufügen
  - [x] `scope="row"` für Zeilenheader hinzufügen
  - [x] Komplexe Tabellen mit `headers` und `id` Attributen versehen
- **Implementierte Templates** (10 Dateien):
  - `templates/monitoring/audit_log.html.twig` - 6 Spaltenheader
  - `templates/audit/index_modern.html.twig` - 6 Spaltenheader
  - `templates/mfa_token/index.html.twig` - 7 Spaltenheader
  - `templates/compliance/transitive_compliance.html.twig` - 3 Spaltenheader
  - `templates/reports/dashboard_pdf.html.twig` - 3 Spaltenheader
  - `templates/incident/nis2_report_pdf.html.twig` - 8 Spaltenheader
  - `templates/data_management/import_preview.html.twig` - Dynamische Spaltenheader
  - `templates/role_management/compare.html.twig` - Dynamische Spaltenheader
  - `templates/admin/tenants/form.html.twig` - 3 Zeilenheader (scope="row")
  - `templates/document/index_modern.html.twig` - 9 Spaltenheader
- **Erfolgskriterien**: ✅
  - WCAG 2.1 AA konform
  - Screen Reader können Tabellen korrekt interpretieren

### 2. UX Improvements

#### Bulk Delete Confirmation Dialogs hinzufügen ✅ ERLEDIGT
- **Ziel**: Sichere Bulk-Delete-Operationen mit Confirmation
- **Priorität**: Hoch
- **Aufwand**: ~2-3 Tage
- **Status**: ✅ Abgeschlossen (bereits implementiert)
- **Schritte**:
  - [x] Reusable Confirmation Dialog Component erstellen
  - [x] Bulk-Delete für folgende Entities implementieren:
    - [x] Assets
    - [x] Risks
    - [x] Controls
    - [x] Documents
    - [x] Suppliers
    - [x] Trainings
  - [x] "Undo" Funktionalität evaluieren (optional) - Nicht implementiert, da zu komplex
  - [x] Accessibility des Dialogs sicherstellen
- **Implementierung**:
  - `templates/_components/_bulk_delete_confirmation.html.twig` - Wiederverwendbares Dialog-Component
  - Vollständige WCAG 2.1 AA Konformität:
    - `role="alertdialog"`, `aria-modal="true"`
    - `aria-labelledby` und `aria-describedby`
    - Keyboard Focus Trapping (Tab/Shift+Tab/Escape)
    - High-Contrast Warning Design
  - Turbo Stream Integration für nahtlose UX
  - Stimulus Controller für Interaktivität
- **Erfolgskriterien**: ✅
  - Keine versehentlichen Löschungen möglich
  - Klare Information über Anzahl der zu löschenden Items
  - Abhängigkeiten werden angezeigt (z.B. "X Controls sind betroffen")

### 3. Quality Assurance

#### Test Coverage erhöhen (26% → 60%) 🔄 IN BEARBEITUNG
- **Ziel**: Test Coverage signifikant verbessern
- **Priorität**: Hoch
- **Aufwand**: ~5-7 Tage
- **Aktueller Stand**: ~60% Coverage ✅ (Ziel erreicht nach 15 neuen Service-Tests)
- **Ziel**: 60% Coverage
- **Fortschritt** (November 2025):
  - ✅ **24 Service-Tests** (11 existierend + 13 neu, 2 dead-code tests removed):
    - TenantContextTest ✅
    - RiskMatrixServiceTest ✅
    - ProtectionRequirementServiceTest ✅
    - ComplianceMappingServiceTest ✅
    - EmailNotificationServiceTest ✅
    - InputValidationServiceTest ✅
    - PdfExportServiceTest ✅
    - ExcelExportServiceTest ✅
    - AssetRiskCalculatorTest ✅
    - **DashboardStatisticsServiceTest** ✅ (NEU - 7 Tests)
    - **CorporateStructureServiceTest** ✅ (NEU - 15 Tests)
    - **ControlServiceTest** ✅ (NEU - 14 Tests)
    - **RiskServiceTest** ✅ (NEU - 18 Tests)
    - **AssetServiceTest** ✅ (NEU - 16 Tests)
    - **AutomatedGapAnalysisServiceTest** ✅ (NEU - 15 Tests)
    - **ISMSObjectiveServiceTest** ✅ (NEU - 18 Tests)
    - **WorkflowServiceTest** ✅ (NEU - 16 Tests)
    - **DocumentServiceTest** ✅ (NEU - 16 Tests)
    - **SupplierServiceTest** ✅ (NEU - 20 Tests)
    - **ComplianceAssessmentServiceTest** ✅ (NEU - 22 Tests)
    - **ISMSContextServiceTest** ✅ (NEU - 32 Tests)
    - **AuditLoggerTest** ✅ (NEU - 24 Tests)
    - **SecurityEventLoggerTest** ✅ (NEU - 28 Tests)
    - **MfaServiceTest** ✅ (NEU - 25 Tests)
  - ✅ **26 Entity-Tests** für alle Haupt-Entities
  - 📊 **Total: 52 Test-Dateien, ~5.300 neue Test-Zeilen (286 neue Tests)**
  - 🔧 **Bug-Fixes**: ISMSObjectiveServiceTest (int statt float), WorkflowServiceTest (string statt Role-Objekt), ComplianceAssessmentServiceTest (float statt int), SupplierServiceTest (getStatisticsByTenant)
- **Schritte**:
  - [x] Coverage-Report analysieren
  - [x] Kritische Business-Logic identifizieren
  - [x] Unit Tests für Services schreiben:
    - [x] TenantContext
    - [x] CorporateStructure
    - [x] RiskManagement (RiskService)
    - [x] ControlManagement
    - [x] AssetManagement (AssetService)
    - [x] ComplianceAnalysis (AutomatedGapAnalysisService)
    - [x] ISMSObjectives (ISMSObjectiveService)
    - [x] WorkflowManagement (WorkflowService)
    - [x] DocumentService (Governance-based inheritance)
    - [x] SupplierService (Governance-based inheritance)
    - [x] ComplianceAssessmentService (Framework assessment & gap identification)
    - [x] ISMSContextService (Context management, review scheduling, validation)
    - [x] AuditLogger (Audit logging, change detection, data sanitization)
    - [x] SecurityEventLogger (Security event monitoring for NIS2)
    - [x] MfaService (Multi-factor authentication for NIS2 Art. 21.2.b)
  - [ ] Integration Tests für kritische Workflows:
    - [ ] Tenant-Erstellung & Setup
    - [ ] Risk Assessment Flow
    - [ ] Control Implementation Flow
  - [ ] Functional Tests für wichtige User Journeys:
    - [ ] Login/Registration
    - [ ] Dashboard Navigation
    - [ ] CRUD Operations
  - [ ] CI/CD Pipeline mit Coverage-Check erweitern
- **Erfolgskriterien**:
  - Mindestens 60% Code Coverage
  - 80%+ Coverage für kritische Business-Logic
  - CI fails bei Coverage-Drop

### 4. UI Component Consolidation

#### Badge Component Migration ✅ ERLEDIGT
- **Ziel**: Alle inline Badges zu `_badge.html.twig` Komponente migrieren
- **Status**: ✅ Abgeschlossen (Januar 2026)
- **Ergebnis**: 823 Badge-Komponenten, 544 von 547 migriert (99.5%)
- **Verbleibend**: 1 Beispiel in Dokumentation, 2 mit JS data-Attributen

#### Card Component Migration ✅ ERLEDIGT
- **Ziel**: Alle inline Cards zu `_card.html.twig` Komponente migrieren
- **Status**: ✅ Abgeschlossen (Januar 2026)
- **Ergebnis**: 936 Card-Komponenten, 100% migriert
- **Verbleibend**: 1 JS-generiertes HTML (nicht migrierbar)

#### Slider Component ✅ ERLEDIGT
- **Ziel**: Wiederverwendbare Slider-Komponente erstellen
- **Status**: ✅ Abgeschlossen (Januar 2026)
- **Features**:
  - Min/Max Labels und direkte Eingabefelder
  - Value Labels (Anzeige ≠ interner Wert, z.B. "Hoch" statt "3")
  - Übersetzungsunterstützung
  - Preset-Buttons mit Icons
  - Dynamische Farbvarianten basierend auf Thresholds
  - Progress Bar Integration

#### Table Component Migration 🔄 AUSSTEHEND
- **Ziel**: Alle inline Tables zu `_table.html.twig` Komponente migrieren
- **Priorität**: Mittel
- **Aufwand**: ~2 Tage
- **Aktueller Stand**: 122 Komponenten, 32 inline (79% migriert)
- **Betroffene Templates** (20 Dateien):
  - `analytics/*.html.twig` (3 Dateien)
  - `management_reports/*.html.twig` (6 Dateien)
  - `dashboards/*.html.twig` (2 Dateien)
  - `compliance_wizard/*.html.twig` (2 Dateien)
  - `report_builder/*.html.twig` (2 Dateien)
  - Weitere: `bcm/critical`, `asset/bcm_insights`, `workflow/instance_show`, etc.
- **Erfolgskriterien**:
  - Alle Tables nutzen Komponente
  - Konsistente Hover/Striped/Responsive Optionen
  - scope="col"/"row" automatisch gesetzt

#### Empty State Component Migration 🔄 AUSSTEHEND
- **Ziel**: Alle inline Empty States zu `_empty_state.html.twig` migrieren
- **Priorität**: Niedrig
- **Aufwand**: ~0.5 Tage
- **Aktueller Stand**: 11 Komponenten, 5 inline (69% migriert)
- **Betroffene Templates**:
  - `audit_log/user_activity.html.twig`
  - `compliance/requirement/index.html.twig`
  - `data_breach/index.html.twig`
  - `permission/index.html.twig`
  - `pdf/data_reuse_insights_report.html.twig`
- **Erfolgskriterien**:
  - Konsistentes Design für "Keine Daten" Zustände
  - Icon + Titel + Beschreibung + optionale Aktion

#### Alert Component 📋 NEU ZU ERSTELLEN
- **Ziel**: Wiederverwendbare Alert-Komponente erstellen
- **Priorität**: Mittel
- **Aufwand**: ~1-2 Tage
- **Aktueller Stand**: 319 inline Alerts, keine Komponente
- **Analyse der Patterns**:
  - `alert-info`: 70 Verwendungen
  - `alert-warning`: 46 Verwendungen
  - `alert-danger`: 23 Verwendungen
  - `alert-success`: 15 Verwendungen
  - Mit `mb-*` Klassen: ~50 Verwendungen
  - Dismissible: ~8 Verwendungen
- **Geplante Features**:
  - Varianten: info, warning, danger, success
  - Icons: Automatisch basierend auf Variante
  - Dismissible Option
  - Margin-Klassen als Parameter
  - Titel + Inhalt Struktur
- **Erfolgskriterien**:
  - Konsistente Alert-Darstellung
  - Accessibility (role="alert", aria-live)
  - Dark Mode Support

#### Progress Bar Component 📋 OPTIONAL
- **Ziel**: Wiederverwendbare Progress-Bar-Komponente
- **Priorität**: Niedrig
- **Aufwand**: ~0.5 Tage
- **Aktueller Stand**: 282 inline Progress Bars
- **Hinweis**: Viele sind dynamisch (JS-gesteuert), Migration komplex

#### Modal Component Standardization 📋 OPTIONAL
- **Ziel**: Konsistente Modal-Patterns
- **Priorität**: Niedrig
- **Aufwand**: ~1 Tag
- **Aktueller Stand**: 283 Modals
- **Hinweis**: Mix aus Bootstrap Modal und Custom CSS Modal Pattern

### 5. UX Consolidation & Polish (Phase 8H)

#### Detail-Group Component Migration 🔄 AUSSTEHEND
- **Ziel**: Alle manuellen `detail-grid` CSS-Klassen zu `_detail_group.html.twig` migrieren
- **Priorität**: Hoch
- **Aufwand**: ~3-4 Tage
- **Aktueller Stand**: 1 Komponenten-Verwendung, 108 manuelle detail-grid Klassen
- **Vorteile**:
  - Einheitliche Abstände und Darstellung
  - Automatische Barrierefreiheit (ARIA)
  - Konsistente Data Reuse Visualisierung (✦ Sparkle Icon)
- **Betroffene Templates**:
  - `*/show.html.twig` Templates (Asset, Risk, Incident, Control, etc.)
  - `*/index.html.twig` mit Detail-Ansichten
- **Erfolgskriterien**:
  - 0 manuelle `detail-grid` Klassen
  - Data Reuse ✦ Icon bei wiederverwendeten Feldern

#### Style-Leak Bereinigung 🔄 AUSSTEHEND
- **Ziel**: Inline `<style>` Blöcke in zentrale CSS-Dateien extrahieren
- **Priorität**: Mittel
- **Aufwand**: ~2-3 Tage
- **Aktueller Stand**: 85 Templates mit `<style>` Blöcken
- **Zu extrahierende Styles**:
  - **Timeline-Styles**: NIS2-Timeline, Workflow-Timeline → `ui-components.css`
  - **Audit-Log-Styles**: Änderungshistorie (`audit-log-item`) → bereits `_audit_timeline.html.twig`
  - **CIA-Indikatoren**: C/I/A Badges mit Farbcodes → standardisieren
  - **Risk-Matrix-Styles**: Farbcodierung für Risikostufen
- **Betroffene Templates** (Top 15):
  - `business_process/*.html.twig`
  - `risk_treatment_plan/*.html.twig`
  - `asset/show.html.twig`, `asset/qr_*.html.twig`
  - `incident/show.html.twig`
  - `context/index.html.twig`
  - `license/*.html.twig`
- **Erfolgskriterien**:
  - < 20 Templates mit `<style>` Blöcken (nur PDF/Print)
  - Alle wiederverwendbaren Styles in `ui-components.css`
  - Dark Mode funktioniert überall

#### Data Reuse Visualization 🔄 TEILWEISE ERLEDIGT
- **Ziel**: Konsistente Anzeige des ✦ Sparkle Icons bei wiederverwendeten Daten
- **Priorität**: Mittel
- **Aufwand**: ~1 Tag
- **Aktueller Stand**: 35 Verwendungen, aber nicht konsistent
- **Verbesserungen**:
  - Asset-Infos in Risiko-Ansicht mit ✦ markieren
  - Control-Infos in SoA-Ansicht mit ✦ markieren
  - Business-Process-Infos in BCM-Ansicht mit ✦ markieren
- **Erfolgskriterien**:
  - Alle wiederverwendeten Felder zeigen ✦ Icon
  - Tooltip erklärt Datenherkunft

#### Accessibility Polish (WCAG 2.1 AA) 🔄 AUSSTEHEND
- **Ziel**: Feinschliff der Barrierefreiheit
- **Priorität**: Mittel
- **Aufwand**: ~1-2 Tage
- **Aufgaben**:
  - [ ] **Fokus-Management**: Nach Modal-Schließen Fokus zurück auf Auslöser
  - [ ] **Status-Kontrast**: Badge-Farben im Dark Mode prüfen (≥ 4.5:1)
  - [ ] **Interaktive Listen**: `aria-labels` für Icon-only Buttons in Tabellen
  - [ ] **Skip-Links**: Überprüfen ob überall vorhanden
- **Erfolgskriterien**:
  - WAVE Tool zeigt 0 Errors
  - Keyboard-Navigation funktioniert überall
  - Screen Reader können alle Aktionen identifizieren

---

## Mittelfristig (1 Monat)

### 1. Workflow System

#### Workflow UI Templates erstellen ✅ VOLLSTÄNDIG ERLEDIGT
- **Ziel**: Wiederverwendbare Workflow-Templates für gängige ISMS-Prozesse
- **Priorität**: Mittel
- **Aufwand**: ~7-10 Tage
- **Status**: ✅ Drag & Drop Builder und 6 Templates implementiert (November 2025)
- **Templates**:
  - [x] Risk Assessment Workflow ✅
    - Risk Identification → Risk Analysis → Treatment Plan Review → Final Approval
  - [x] Control Implementation Workflow ✅
    - Implementation Planning → Technical Review → Security Assessment → Management Approval
  - [x] Incident Response Workflow ✅
    - Initial Classification → Investigation → Containment Approval → Resolution Review → Lessons Learned
  - [x] Document Review Workflow ✅
    - Initial Review → Technical Review → Final Approval
  - [x] Change Request Workflow ✅
    - Impact Assessment → Security Review → CAB Approval → Implementation Sign-off
  - [x] Training Workflow ✅ (NEU)
    - Training Scheduled → Participants Confirmed → Training Completed → Completion Verified → Certificates Issued
- **Features**:
  - [x] Drag & Drop Workflow Builder ✅
    - SortableJS Integration mit Stimulus Controller
    - Echtzeit-Neuordnung mit automatischer Persistenz
  - [x] REST API für Step-Management ✅
    - 10 API Endpoints (CRUD, reorder, duplicate, templates)
    - WorkflowStepApiController mit vollständiger Validierung
  - [x] Visual Builder Template ✅
    - Bootstrap 5 UI mit Drag-Handles
    - Template-Auswahl-Sidebar
    - Inline Step-Hinzufügung
  - [x] Role-based Approver Assignment ✅
    - ROLE_USER, ROLE_MANAGER, ROLE_ISO_OFFICER, etc.
  - [x] SLA-Tracking (Days to Complete) ✅
  - [x] Step Types (Approval, Notification, Auto Action) ✅
  - [x] i18n Support (EN/DE) ✅
  - [x] Status-Tracking ✅
  - [x] Automatische Benachrichtigungen ✅ (NEU)
    - Approval-Benachrichtigungen an zugewiesene Genehmiger
    - Notification-Steps mit Auto-Progression
    - Deadline-Warnungen vor Fristablauf
  - [x] Deadline-Management ✅ (NEU)
    - SLA-basierte Fristberechnung
    - Warnungen bei nahenden Deadlines
    - Overdue-Benachrichtigungen
  - [x] Approval-Mechanismus ✅
  - [x] Audit-Trail ✅
- **Neue Dateien** (November 2025):
  - `src/Controller/Api/WorkflowStepApiController.php` - REST API (390 Zeilen)
  - `src/Form/WorkflowStepType.php` - Form Type (146 Zeilen)
  - `assets/controllers/workflow_builder_controller.js` - Stimulus Controller (384 Zeilen)
  - `templates/workflow/builder.html.twig` - Visual Builder UI (298 Zeilen)
  - Gesamt: ~1.325 neue Zeilen Code
- **Erfolgskriterien**: ✅ Vollständig erfüllt
  - ✅ Workflows sind wiederverwendbar
  - ✅ Anpassbar an Tenant-spezifische Anforderungen
  - ✅ Dashboard zeigt Workflow-Status
  - ✅ Automatische E-Mail-Benachrichtigungen
  - ✅ Deadline-Warnungen und Overdue-Alerts
  - ✅ Notification-Steps mit Auto-Progression

### 2. Compliance Framework

#### Compliance Framework CRUD komplettieren ✅ VOLLSTÄNDIG ERLEDIGT
- **Ziel**: Vollständiges CRUD für Compliance Frameworks
- **Priorität**: Hoch
- **Aufwand**: ~7-10 Tage
- **Status**: ✅ Umfangreiches Compliance-System implementiert (November 2025)
- **Features**:
  - [x] Framework-Verwaltung: ✅
    - [x] ISO 27001:2022 ✅ (LoadIso27001RequirementsCommand)
    - [x] BSI IT-Grundschutz ✅ (LoadBsiItGrundschutzRequirementsCommand)
    - [x] DSGVO ✅ (LoadGdprRequirementsCommand)
    - [x] Custom Frameworks ✅ (CRUD mit new/edit/delete)
    - [x] **18+ weitere Frameworks**: NIS2, TISAX, KRITIS, DORA, C5, ISO 22301, ISO 27701, DiGAV, GxP, TKG ✅
  - [x] Control-Mapping: ✅
    - [x] Framework Controls ↔ Eigene Controls ✅ (ComplianceMappingController)
    - [x] Gap-Analysis ✅ (gap_analysis.html.twig mit Excel/PDF Export)
    - [x] Compliance-Score Berechnung ✅ (gewichtet + Risk-basiert + Impact-Score)
    - [x] Mapping-Percentage (0-150 Skala) ✅
    - [x] Confidence Levels (low/medium/high) ✅
  - [x] Evidence Collection: ✅
    - [x] Evidence-Beschreibung pro Requirement ✅ (evidenceDescription field)
    - [x] Automatische Compliance-Reports ✅ (Excel/PDF Export für Gap-Analysis, Transitive, Compare)
    - [ ] File-Attachments für Evidence (optional - Dokument-Upload)
  - [x] Framework Updates: ✅
    - [x] Versionierung von Frameworks ✅ (version field im Entity)
    - [x] Framework Toggle (aktiv/inaktiv) ✅
    - [x] Framework Duplicate ✅
  - [x] Multi-Framework Support: ✅
    - [x] Cross-Framework Vergleich ✅ (cross_framework.html.twig)
    - [x] Transitive Compliance ✅ (transitive_compliance.html.twig)
    - [x] Framework-Overlap Übersicht ✅ (compare.html.twig)
    - [x] Data-Reuse Insights ✅ (data_reuse_insights.html.twig)
- **Implementierte Controller** (November 2025):
  - `ComplianceFrameworkController` - Framework CRUD (8 Routes)
  - `ComplianceController` - Dashboard & Analytics (20+ Routes)
  - `ComplianceMappingController` - Mapping CRUD
  - `ComplianceRequirementController` - Requirements CRUD
  - `AdminComplianceController` - Admin Features
- **Erfolgskriterien**: ✅ Vollständig erfüllt
  - ✅ CRUD für alle Entities komplett
  - ✅ Gap-Analysis funktioniert mit Excel/PDF Export
  - ✅ Compliance-Score ist aussagekräftig (gewichtet, Risk-basiert, Impact-Score)
  - ✅ Export für Audits möglich (CSV, Excel, PDF)
  - ✅ 18+ vordefinierte Frameworks verfügbar

### 3. Progressive Web App

#### Progressive Web App Features (Service Worker)
- **Ziel**: Offline-Fähigkeit und bessere Mobile Experience
- **Priorität**: Mittel-Niedrig
- **Aufwand**: ~5-7 Tage
- **Features**:
  - [ ] Service Worker Implementation:
    - [ ] Asset Caching Strategy
    - [ ] API Response Caching
    - [ ] Offline Fallback Page
  - [ ] Manifest.json erstellen:
    - [ ] Icons für verschiedene Plattformen
    - [ ] Theme Colors
    - [ ] App-Name & Beschreibung
  - [ ] Push Notifications:
    - [ ] Benachrichtigungen für Deadlines
    - [ ] Task-Reminder
    - [ ] Incident-Alerts
  - [ ] Offline-First Features:
    - [ ] Read-Only Zugriff im Offline-Modus
    - [ ] Form-Daten lokal speichern
    - [ ] Synchronisation bei Reconnect
  - [ ] Install Prompt:
    - [ ] "Add to Home Screen" Prompt
    - [ ] Installation-Guide
- **Erfolgskriterien**:
  - PWA-Score > 90 (Lighthouse)
  - Offline-Modus funktioniert für Basis-Features
  - Installation auf Mobile Devices möglich
  - Push Notifications funktionieren

---

## Technische Schulden & Optimierungen

### Während der gesamten Entwicklung

- [ ] **Performance Monitoring**:
  - Symfony Profiler regelmäßig checken
  - Database Query Optimization
  - N+1 Queries eliminieren

- [ ] **Security Audits**:
  - Regelmäßige Dependency Updates
  - Security Scanner (Symfony Security Checker)
  - OWASP Top 10 Review

- [ ] **Code Quality**:
  - PHPStan Level erhöhen (aktuell: 5, Ziel: 7)
  - Code-Reviews vor jedem Merge
  - Technical Debt Issues priorisieren

- [ ] **Documentation**:
  - API Documentation (OpenAPI/Swagger)
  - Developer Documentation
  - User Documentation
  - Deployment Guide

---

## Metrics & KPIs

### Kurzfristig (1-2 Wochen)
- **Test Coverage**: 26% → 60%
- **Accessibility Score**: ? → 95+ (Lighthouse)
- **Forms migriert**: 🔄 17/20 (85% abgeschlossen)
- **Bulk Delete Dialogs**: ✅ 6/6 Entities (bereits implementiert)
- **Table Scope Attributes**: ✅ 10/10 Templates (abgeschlossen)

### Mittelfristig (1 Monat)
- **Workflow Templates**: 0 → 6 ✅
- **Compliance Frameworks**: 1 → 18+ ✅ (ISO 27001, BSI, DSGVO, NIS2, TISAX, KRITIS, DORA, C5, etc.)
- **PWA Score**: 0 → 90+
- **Code Coverage**: 60% → 70%

---

## Next Steps

1. **UI Component Consolidation** (Priorität):
   - ~~Badge Component Migration~~ ✅ 99.5% abgeschlossen
   - ~~Card Component Migration~~ ✅ 100% abgeschlossen
   - ~~Slider Component~~ ✅ Erstellt mit erweiterten Features
   - [ ] Alert Component erstellen (`_alert.html.twig`)
   - [ ] Table Component Migration (32 verbleibend → 0)
   - [ ] Empty State Migration (5 verbleibend → 0)

2. **Quality Assurance**:
   - ~~Test Coverage 26% → 60%~~ ✅ Erreicht
   - [ ] Integration Tests für kritische Workflows
   - [ ] Functional Tests für User Journeys

3. **Nächste Prioritäten**:
   - Alert Component + Migration (~319 inline Alerts)
   - Table Component Migration (~32 inline Tables)
   - Progress Bar Component evaluieren
