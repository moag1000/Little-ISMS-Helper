# Small ISMS Helper

Ein webbasiertes Tool zur Unterstützung des Informationssicherheitsmanagements (ISMS) nach ISO 27001 für kleine und mittelständische Unternehmen.

## Überblick

Der **Small ISMS Helper** ist eine PHP-basierte Webanwendung, die Organisationen bei der Implementierung und Verwaltung ihres Informationssicherheitsmanagementsystems (ISMS) nach ISO/IEC 27001 unterstützt. Das Tool hilft dabei:

- Unverzichtbare Kerndaten des ISMS zu erfassen
- Sicherheitsrelevante Informationen zu dokumentieren
- Key Performance Indicators (KPIs) für das ISMS zu generieren und zu überwachen
- Den Compliance-Status zu verfolgen
- Audits und Reviews zu unterstützen

## Funktionsumfang

### Implementierte Kernmodule

- **Statement of Applicability (SoA)**: Vollständige Verwaltung aller 93 ISO 27001:2022 Annex A Controls
  - Festlegung der Anwendbarkeit pro Control
  - Begründung für Anwendbarkeit/Nicht-Anwendbarkeit
  - Implementierungsstatus und -fortschritt
  - Verantwortlichkeiten und Zieldaten
  - Export-Funktion für Compliance-Nachweise

- **Asset Management**: Verwaltung von IT-Assets und Informationswerten
  - Erfassung mit CIA-Bewertung (Confidentiality, Integrity, Availability)
  - Asset-Typen und Eigentümer
  - Verknüpfung mit Risiken

- **Risk Assessment & Treatment**: Vollständiges Risikomanagement
  - Risikoidentifikation mit Bedrohungen und Schwachstellen
  - Risikobewertung (Wahrscheinlichkeit × Auswirkung)
  - Restrisiko-Berechnung nach Behandlung
  - Risikobehandlungsstrategien
  - Verknüpfung mit Assets und Controls

- **Incident Management**: Strukturierte Vorfallsbehandlung
  - Vorfallsdokumentation und -kategorisierung
  - Schweregrad-Bewertung
  - Sofortmaßnahmen und Root Cause Analysis
  - Korrektur- und Präventivmaßnahmen
  - Lessons Learned
  - Datenschutzverletzungen (Data Breach) Tracking

- **Internal Audit Management**: Audit-Planung und -Durchführung
  - Audit-Planung mit Geltungsbereich und Zielen
  - Audit-Team Verwaltung
  - Findings und Nichtkonformitäten
  - Beobachtungen und Empfehlungen

- **Management Review**: Managementbewertung des ISMS
  - Strukturierte Review-Dokumentation
  - Performance-Bewertung
  - Entscheidungen und Maßnahmen
  - Follow-up vorheriger Reviews

- **Training & Awareness**: Schulungsmanagement
  - Schulungsplanung und -durchführung
  - Teilnehmerverwaltung
  - Feedback-Erfassung

- **ISMS Context & Objectives**: Organisationskontext
  - ISMS-Geltungsbereich
  - Interessierte Parteien
  - Gesetzliche Anforderungen
  - ISMS-Ziele mit KPIs

- **Business Continuity Management (BCM)**: Business Impact Analysis und Kontinuitätsplanung
  - Geschäftsprozess-Verwaltung mit BIA-Daten
  - Recovery Time Objective (RTO), Recovery Point Objective (RPO), MTPD
  - Kritikalitätsbewertung und Impact-Scores
  - **Intelligente Datenwiederverwendung**: BCM-Daten fließen automatisch in Asset-Verfügbarkeitsanforderungen ein
  - Verknüpfung mit unterstützenden IT-Assets

- **Multi-Framework Compliance Management**: Mehrere Normen parallel verwalten
  - **TISAX (VDA ISA)**: Informationssicherheitsbewertung für die Automobilindustrie (32 Requirements)
  - **EU-DORA**: Digital Operational Resilience Act für Finanzdienstleister (30 Requirements)
  - **Hierarchische Requirements**: Core-Anforderungen mit detaillierten Sub-Requirements für granulare Audits
  - **Cross-Framework-Mappings**: Zeigt, wie Anforderungen verschiedener Normen sich gegenseitig erfüllen
  - **Transitive Compliance**: Berechnet automatisch, wie die Erfüllung einer Norm andere Normen unterstützt
  - **Mapping-Typen**: Vollständig, Teilweise, Übererfüllt mit Prozentangaben
  - **Automatische Fulfillment-Berechnung**: Nutzt bestehende ISO 27001-Daten für andere Frameworks
  - **Gap-Analyse**: Identifiziert Lücken und priorisiert Maßnahmen
  - **Flexible Audit-Scopes**: Audits können auf Frameworks, Assets, Standorte oder Abteilungen beschränkt werden
  - **Audit-Checklisten**: Automatische Generierung von Prüfchecklisten mit Verifizierungsstatus

- **KPI Dashboard**: Echtzeit-Kennzahlen
  - Asset-Anzahl
  - Risiko-Übersicht
  - Offene Vorfälle
  - Compliance-Status (implementierte Controls)
  - **Data Reuse Value**: Zeigt eingesparte Arbeitsstunden durch Datenwiederverwendung

## Moderne Benutzeroberfläche (Progressive Disclosure UI)

Das Tool implementiert das **Progressive Disclosure Pattern** für eine aufgeräumte, intuitive Bedienung ohne Funktionalitätsverlust:

### UI-Designprinzipien

- **Weniger ist mehr**: Essenzielle Informationen immer sichtbar, Details auf Abruf
- **Tab-basierte Navigation**: Logische Gruppierung von Informationen (Übersicht, Details, Lücken, Datennutzung)
- **Collapsible Sections**: Detailanforderungen unter Core-Anforderungen einklappbar
- **Circular Progress Charts**: Visuell ansprechende Compliance-Fortschrittsindikatoren
- **Interaktive Elemente**: Stimulus-Controller für dynamische Inhalte ohne Seitenneuladung
- **Responsive Layout**: Optimiert für Desktop und Tablet

### Implementierte UI-Features

- **Framework Dashboard**: Tab-Navigation mit Always-Visible Stats Bar (5 Key Metrics)
- **Compliance Overview**: Circular SVG Progress Charts mit Farbcodierung (grün ≥75%, gelb ≥50%, rot <50%)
- **Expandable Requirements**: Hierarchische Anforderungen mit Expand/Collapse-Funktionalität
- **Filter Panels**: Versteckt standardmäßig, auf Anfrage einblendbar
- **Minimale Buttons**: Reduktion von 9 auf 2 primäre Aktionen pro Card (~70% weniger visuelles Rauschen)

### Technologie

- **Symfony UX Stimulus**: Client-side Interaktivität ohne JavaScript-Framework
- **Symfony UX Turbo**: Schnelle Navigation ohne Full-Page-Reloads
- **CSS3 Animations**: Smooth Transitions für bessere UX

## Intelligente Datenwiederverwendung (Data Reuse Architecture)

Ein Kernprinzip des Small ISMS Helper ist die **maximale Wertschöpfung aus einmal erfassten Daten**. Daten werden nicht isoliert in Silos gespeichert, sondern intelligent über Module hinweg wiederverwendet:

### Implementierte Data Reuse-Muster

1. **BCM → Asset Protection Requirements**
   - RTO/RPO/MTPD-Daten aus der Business Impact Analysis
   - Automatische Ableitung von Verfügbarkeitsanforderungen für IT-Assets
   - Beispiel: Prozess mit RTO ≤ 1h → Asset-Verfügbarkeit "Very High" (5)

2. **Incident ↔ Asset (Betroffene Assets)**
   - Verknüpfung von Incidents mit betroffenen Assets (`Incident.affectedAssets`, `Asset.incidents`)
   - **Automatische Asset-Risikobewertung**: `Asset.getRiskScore()` kombiniert CIA-Werte, Incidents, Risiken und Control-Coverage
   - **Impact-Analyse**: `Incident.getTotalAssetImpact()` aggregiert CIA-Werte aller betroffenen Assets
   - **Kritische Assets erkennen**: `Incident.hasCriticalAssetsAffected()` identifiziert Hochrisiko-Vorfälle

3. **Incident ↔ Risk (Realisierte Risiken)**
   - Verknüpfung von Incidents mit materialisierten Risiken (`Incident.realizedRisks`, `Risk.incidents`)
   - **Risikovalidierung**: `Risk.wasAssessmentAccurate()` vergleicht Risikobewertung mit tatsächlichen Incidents
   - **Realisierungsfrequenz**: `Risk.getRealizationCount()` zeigt wie oft ein Risiko eingetreten ist
   - **Lerneffekt**: Risikobewertungen werden durch echte Vorfälle validiert und kalibriert

4. **Control ↔ Asset (Geschützte Assets)**
   - Verknüpfung von Controls mit geschützten Assets (`Control.protectedAssets`, `Asset.protectingControls`)
   - **Control-Effektivität**: `Control.getEffectivenessScore()` misst Wirksamkeit durch Incident-Reduktion
   - **Schutzstatus**: `Asset.getProtectionStatus()` zeigt ob Assets adequately_protected, under_protected oder unprotected sind
   - **Automatische Reviews**: `Control.needsReview()` triggert bei Incidents auf geschützten Assets

5. **Training ↔ Control (Abgedeckte Controls)**
   - Verknüpfung von Trainings mit ISO 27001 Controls (`Training.coveredControls`, `Control.trainings`)
   - **Training-Effektivität**: `Training.getTrainingEffectiveness()` korreliert mit Control-Implementierungsstatus
   - **Gap-Analyse**: `Control.getTrainingStatus()` identifiziert fehlende oder veraltete Schulungen
   - **Priorisierung**: `Training.addressesCriticalControls()` zeigt Training-Bedarf für kritische Controls

6. **BusinessProcess ↔ Risk (Prozessrisiken)**
   - Verknüpfung von Geschäftsprozessen mit identifizierten Risiken (`BusinessProcess.identifiedRisks`)
   - **BIA-Risiko-Alignment**: `BusinessProcess.isCriticalityAligned()` validiert Konsistenz zwischen BIA und Risikobewertung
   - **RTO-Empfehlungen**: `BusinessProcess.getSuggestedRTO()` leitet aus Risiken optimale Recovery-Zeiten ab
   - **Alerts**: `BusinessProcess.hasUnmitigatedHighRisks()` warnt bei kritischen ungeklärten Risiken

7. **ISO 27001 → Multi-Framework Compliance**
   - ISO 27001 Controls mappen auf TISAX- und DORA-Anforderungen
   - Cross-Framework-Mappings zeigen Überschneidungen
   - Transitive Compliance-Berechnung

8. **Audit Findings → Risk Management**
   - Audit-Ergebnisse fließen in Risikobewertung ein
   - Non-Conformities triggern Risiko-Reviews

### Vorteile der Data Reuse Architecture

- **Zeitersparnis**: ~10,5 Stunden (95%) pro Audit-Zyklus durch automatisierte Datenaggregation
- **Konsistenz**: Einheitliche Datenbasis für alle Compliance-Anforderungen
- **Nachvollziehbarkeit**: Transparente Datenflüsse für Audits
- **Proaktive Insights**: Automatische Empfehlungen basierend auf vorhandenen Daten
- **Validierung**: Risikobewertungen werden durch reale Incidents validiert
- **Automatisierung**: Manuelle Analysen werden durch berechnete Metriken ersetzt

### Neue automatische KPIs

Die vollständige Entity-Vernetzung ermöglicht **automatische Berechnungen**, die vorher manuell durchgeführt werden mussten:

- **Asset Risk Score**: `Asset.getRiskScore()` - Kombiniert CIA-Werte, Incident-Historie, aktive Risiken und Control-Coverage
- **Risk Assessment Accuracy**: `Risk.wasAssessmentAccurate()` - Validiert Risikobewertungen mit tatsächlichen Incidents
- **Control Effectiveness**: `Control.getEffectivenessScore()` - Misst Wirksamkeit durch Incident-Reduktion nach Implementation
- **Training Effectiveness**: `Training.getTrainingEffectiveness()` - Korreliert Training-Teilnahme mit Control-Implementierung
- **BIA-Risk Alignment**: `BusinessProcess.isCriticalityAligned()` - Prüft Konsistenz zwischen Business-Impact und Risikobewertung
- **Asset Protection Status**: `Asset.getProtectionStatus()` - Identifiziert ungeschützte oder untergeschützte Assets
- **Training Coverage**: `Control.getTrainingStatus()` - Zeigt Training-Lücken (no_training, training_outdated, training_current)

### Services für Data Reuse

- `ProtectionRequirementService`: Intelligente CIA-Berechnung aus BCM/Incidents
- `RiskIntelligenceService`: Risiko-Empfehlungen aus Incident-History
- `ComplianceMappingService`: Cross-Framework Daten-Mapping
- `ComplianceAssessmentService`: Automatische Fulfillment-Berechnung

## Technologie-Stack

- **Framework**: Symfony 7.3 (neueste Version)
- **PHP**: 8.4 (empfohlen) oder 8.2+
- **Datenbank**: PostgreSQL/MySQL (über Doctrine ORM)
- **Frontend**: Twig Templates, Symfony UX (Stimulus, Turbo)
- **UI/UX**: Progressive Disclosure Pattern, CSS3 Animations
- **Testing**: PHPUnit

## Voraussetzungen

- PHP 8.4 (empfohlen) oder mindestens PHP 8.2
- Composer
- Eine Datenbank (PostgreSQL, MySQL oder SQLite)
- Symfony CLI (optional, für lokale Entwicklung)

## Installation

### 1. Repository klonen

```bash
git clone <repository-url>
cd Little-ISMS-Helper
```

### 2. Abhängigkeiten installieren

```bash
composer install
```

### 3. Umgebungskonfiguration

Kopieren Sie die `.env` Datei und passen Sie die Datenbankverbindung an:

```bash
cp .env .env.local
```

Bearbeiten Sie `.env.local` und konfigurieren Sie die Datenbankverbindung:

```
DATABASE_URL="postgresql://user:password@localhost:5432/isms_helper?serverVersion=16&charset=utf8"
```

### 4. Datenbank erstellen

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

### 5. Compliance-Frameworks und Controls laden

Laden Sie alle 93 Controls aus ISO 27001:2022 Annex A in die Datenbank:

```bash
php bin/console isms:load-annex-a-controls
```

Dies ist die Grundlage für Ihr Statement of Applicability.

**Optional**: Laden Sie zusätzliche Compliance-Frameworks:

```bash
# TISAX (VDA ISA) für die Automobilindustrie
php bin/console app:load-tisax-requirements

# EU-DORA für Finanzdienstleister
php bin/console app:load-dora-requirements
```

Diese Frameworks nutzen automatisch Ihre bestehenden ISO 27001-Daten durch intelligente Mappings.

### 6. Assets installieren

```bash
php bin/console importmap:install
```

### 7. Entwicklungsserver starten

Mit Symfony CLI:

```bash
symfony server:start
```

Oder mit PHP Built-in Server:

```bash
php -S localhost:8000 -t public/
```

Die Anwendung ist dann unter `http://localhost:8000` erreichbar.

## Entwicklung

### Code-Generierung

Das Projekt verwendet Symfony MakerBundle für die Code-Generierung:

```bash
# Entity erstellen
php bin/console make:entity

# Controller erstellen
php bin/console make:controller

# Form erstellen
php bin/console make:form

# CRUD erstellen
php bin/console make:crud
```

### Tests ausführen

```bash
php bin/phpunit
```

### Cache leeren

```bash
php bin/console cache:clear
```

## Projektstruktur

```
├── config/             # Konfigurationsdateien
├── public/             # Öffentlich zugängliche Dateien
│   └── index.php      # Entry Point
├── src/
│   ├── Controller/    # Controller
│   ├── Entity/        # Doctrine Entities
│   ├── Form/          # Formulare
│   ├── Repository/    # Doctrine Repositories
│   └── Service/       # Business Logic Services
├── templates/         # Twig Templates
├── tests/            # Tests
└── var/              # Cache, Logs, etc.
```

## ISO 27001 Konformität

Dieses Tool orientiert sich an den Anforderungen der ISO/IEC 27001:2022 und unterstützt insbesondere:

- **Clause 4**: Kontext der Organisation
- **Clause 5**: Führung
- **Clause 6**: Planung
- **Clause 7**: Unterstützung
- **Clause 8**: Betrieb
- **Clause 9**: Bewertung der Leistung
- **Clause 10**: Verbesserung

## Lizenz

Proprietary - Alle Rechte vorbehalten

## Beitragen

Dieses Projekt befindet sich in der Entwicklung. Contribution Guidelines werden zu einem späteren Zeitpunkt hinzugefügt.

## Support

Bei Fragen oder Problemen erstellen Sie bitte ein Issue im Repository.

## Roadmap

### Phase 1: Core ISMS (✅ Abgeschlossen)
- [x] Basis-Setup und Projektstruktur
- [x] Alle ISMS Kernentities (Asset, Risk, Control, Incident, etc.)
- [x] Statement of Applicability mit allen 93 Annex A Controls
- [x] Grundlegende Controller und Views für alle Module
- [x] KPI Dashboard mit Echtzeit-Daten
- [x] Datenbank-Migrationen

### Phase 2: Data Reuse & Multi-Framework (✅ Abgeschlossen)
- [x] Business Continuity Management (BCM) Modul
- [x] Multi-Framework Compliance (TISAX, DORA)
- [x] Hierarchische Compliance Requirements
- [x] Cross-Framework Mappings & Transitive Compliance
- [x] Flexible Audit-Scopes & Audit-Checklisten
- [x] Vollständige Entity-Beziehungen (Incident↔Asset, Incident↔Risk, Control↔Asset, Training↔Control, BusinessProcess↔Risk)
- [x] Automatische KPIs (Asset Risk Score, Control Effectiveness, Training Effectiveness, etc.)
- [x] Progressive Disclosure UI Pattern
- [x] Circular Progress Charts & Tab-Navigation
- [x] Symfony UX Integration (Stimulus, Turbo)

### Phase 3: User Management & Security (✅ Abgeschlossen)
- [x] User Authentication & Authorization (Symfony Security)
- [x] Role-Based Access Control (RBAC) with User/Role/Permission entities
- [x] Audit Logging für alle Änderungen (Doctrine Event Listener)
- [x] Multi-Language Support (DE, EN)

### Phase 4: CRUD & Workflows (🚧 In Planung)
- [ ] Vollständige CRUD-Operationen für alle Module
- [ ] Formulare mit Validierung
- [ ] Risk Assessment Matrix Visualisierung
- [ ] Workflow-Engine für Genehmigungsprozesse

### Phase 5: Reporting & Integration (📋 Backlog)
- [ ] Erweiterte Reporting & Export Funktionen (PDF, Excel)
- [ ] Datei-Uploads für Nachweise und Dokumentation
- [ ] E-Mail-Benachrichtigungen für Vorfälle und Fälligkeiten
- [ ] API für Integration mit anderen Systemen
- [ ] Webhook-Support

### Phase 6: Enterprise Features (📋 Backlog)
- [ ] Multi-Tenancy Support (für MSPs)
- [ ] Advanced Analytics & Dashboards
- [ ] Mobile App (Progressive Web App)
- [ ] Automatisierte Tests (Unit, Integration, E2E)
- [ ] CI/CD Pipeline
- [ ] Docker & Kubernetes Deployment

## Autoren

Entwickelt für kleine und mittelständische Unternehmen, die ein pragmatisches und effizientes Tool für ihr ISMS benötigen.
