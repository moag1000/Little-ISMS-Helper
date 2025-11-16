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
- **Aktueller Stand**: ~45% Coverage (geschätzt nach 6 neuen Service-Tests)
- **Ziel**: 60% Coverage
- **Fortschritt** (November 2025):
  - ✅ **17 Service-Tests** (11 existierend + 6 neu):
    - TenantContextTest ✅
    - RiskMatrixServiceTest ✅
    - RiskIntelligenceServiceTest ✅
    - RiskImpactCalculatorServiceTest ✅
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
  - ✅ **26 Entity-Tests** für alle Haupt-Entities
  - 📊 **Total: 43 Test-Dateien, 1.688 neue Test-Zeilen (85 neue Tests)**
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

---

## Mittelfristig (1 Monat)

### 1. Workflow System

#### Workflow UI Templates erstellen
- **Ziel**: Wiederverwendbare Workflow-Templates für gängige ISMS-Prozesse
- **Priorität**: Mittel
- **Aufwand**: ~7-10 Tage
- **Templates**:
  - [ ] Risk Assessment Workflow
    - Risiko identifizieren → Bewerten → Behandlung planen → Review
  - [ ] Control Implementation Workflow
    - Planung → Implementierung → Test → Review → Approval
  - [ ] Incident Response Workflow
    - Melden → Klassifizieren → Untersuchen → Beheben → Dokumentieren
  - [ ] Document Review Workflow
    - Erstellen → Review → Approval → Veröffentlichung → Revision
  - [ ] Training Workflow
    - Planen → Einladen → Durchführen → Follow-up → Zertifizierung
- **Features**:
  - [ ] Drag & Drop Workflow Builder
  - [ ] Status-Tracking
  - [ ] Automatische Benachrichtigungen
  - [ ] Deadline-Management
  - [ ] Approval-Mechanismus
  - [ ] Audit-Trail
- **Erfolgskriterien**:
  - Workflows sind wiederverwendbar
  - Anpassbar an Tenant-spezifische Anforderungen
  - Dashboard zeigt Workflow-Status

### 2. Compliance Framework

#### Compliance Framework CRUD komplettieren
- **Ziel**: Vollständiges CRUD für Compliance Frameworks
- **Priorität**: Hoch
- **Aufwand**: ~7-10 Tage
- **Features**:
  - [ ] Framework-Verwaltung:
    - [ ] ISO 27001:2022
    - [ ] BSI IT-Grundschutz
    - [ ] DSGVO
    - [ ] Custom Frameworks
  - [ ] Control-Mapping:
    - [ ] Framework Controls ↔ Eigene Controls
    - [ ] Gap-Analysis
    - [ ] Compliance-Score Berechnung
  - [ ] Evidence Collection:
    - [ ] Nachweise zu Controls verknüpfen
    - [ ] Automatische Compliance-Reports
  - [ ] Framework Updates:
    - [ ] Versionierung von Frameworks
    - [ ] Migration bei Framework-Updates
  - [ ] Multi-Framework Support:
    - [ ] Ein Control mehreren Frameworks zuordnen
    - [ ] Übersicht über Framework-Overlap
- **Erfolgskriterien**:
  - CRUD für alle Entities komplett
  - Gap-Analysis funktioniert
  - Compliance-Score ist aussagekräftig
  - Export für Audits möglich

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
- **Workflow Templates**: 0 → 5
- **Compliance Frameworks**: 1 → 4
- **PWA Score**: 0 → 90+
- **Code Coverage**: 60% → 70%

---

## Next Steps

1. **Diese Woche**:
   - ~~Bulk Delete Dialog Component erstellen~~ ✅ Bereits implementiert
   - ~~Table Scope Attributes hinzufügen~~ ✅ Abgeschlossen
   - Accessible Form Component Migration starten
   - Test Coverage Analyse durchführen

2. **Nächste Woche**:
   - Erste 5 Forms migrieren
   - Unit Tests für kritische Services schreiben
   - Compliance Module UX Improvements reviewen

3. **Monat 1**:
   - Alle kurzfristigen Ziele abschließen
   - Mit Workflow-Templates starten
   - Compliance Framework Design finalisieren
