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
  - **TISAX (VDA ISA)**: Informationssicherheitsbewertung für die Automobilindustrie
  - **EU-DORA**: Digital Operational Resilience Act für Finanzdienstleister
  - **Cross-Framework-Mappings**: Zeigt, wie Anforderungen verschiedener Normen sich gegenseitig erfüllen
  - **Transitive Compliance**: Berechnet automatisch, wie die Erfüllung einer Norm andere Normen unterstützt
  - **Mapping-Typen**: Vollständig, Teilweise, Übererfüllt mit Prozentangaben
  - **Automatische Fulfillment-Berechnung**: Nutzt bestehende ISO 27001-Daten für andere Frameworks
  - **Gap-Analyse**: Identifiziert Lücken und priorisiert Maßnahmen

- **User & Role Management**: Umfassendes Benutzer- und Berechtigungssystem
  - Benutzerverwaltung mit Profilen
  - Rollenbasierte Zugriffskontrolle (RBAC)
  - Granulare Berechtigungen (View, Create, Edit, Delete)
  - **Azure Active Directory Integration**: OAuth 2.0 und SAML 2.0 Support
  - Single Sign-On (SSO) Unterstützung
  - Mehrsprachige Benutzeroberfläche (Deutsch/Englisch)
  - Sicherheitsrichtlinien und Passwort-Management

- **Audit Logging**: Vollständiges Aktivitätsprotokoll
  - Automatisches Logging aller CRUD-Operationen
  - Detaillierte Änderungshistorie (Before/After Values)
  - Benutzer-Aktivitätsverfolgung
  - Entity-History für alle Module
  - Compliance-relevante Audit Trails
  - Filterfunktionen nach Benutzer, Entity, Aktion, Zeitraum
  - Statistiken und Analysen
  - Export-Funktionen für Audits

- **Deployment Wizard**: Geführte System-Einrichtung
  - 6-Schritt Setup-Assistent
  - Automatische System-Anforderungsprüfung
  - Intelligente Modul-Auswahl mit Dependency-Resolution
  - Automatische Datenbank-Initialisierung
  - Basis-Daten Import (ISO 27001 Controls, Permissions)
  - Optionale Beispiel-Daten für Schnellstart
  - Nachträgliche Modul-Verwaltung (Aktivieren/Deaktivieren)
  - Dependency-Graph Visualisierung

- **KPI Dashboard**: Echtzeit-Kennzahlen
  - Asset-Anzahl
  - Risiko-Übersicht
  - Offene Vorfälle
  - Compliance-Status (implementierte Controls)
  - **Data Reuse Value**: Zeigt eingesparte Arbeitsstunden durch Datenwiederverwendung

## Intelligente Datenwiederverwendung (Data Reuse Architecture)

Ein Kernprinzip des Small ISMS Helper ist die **maximale Wertschöpfung aus einmal erfassten Daten**. Daten werden nicht isoliert in Silos gespeichert, sondern intelligent über Module hinweg wiederverwendet:

### Implementierte Data Reuse-Muster

1. **BCM → Asset Protection Requirements**
   - RTO/RPO/MTPD-Daten aus der Business Impact Analysis
   - Automatische Ableitung von Verfügbarkeitsanforderungen für IT-Assets
   - Beispiel: Prozess mit RTO ≤ 1h → Asset-Verfügbarkeit "Very High" (5)

2. **Incidents → Risk Assessment**
   - Historische Vorfälle als Threat Intelligence
   - Automatische Risikovorschläge basierend auf Incident-Mustern
   - Control-Empfehlungen aus erfolgreichen Incident-Responses

3. **Controls → Residual Risk Calculation**
   - Implementierungsstatus und -prozentsatz von Controls
   - Automatische Berechnung der Risikoreduktion
   - Residual Risk = Inherent Risk × (1 - Total Reduction)

4. **ISO 27001 → Multi-Framework Compliance**
   - ISO 27001 Controls mappen auf TISAX- und DORA-Anforderungen
   - Cross-Framework-Mappings zeigen Überschneidungen
   - Transitive Compliance-Berechnung

5. **Audit Findings → Risk Management**
   - Audit-Ergebnisse fließen in Risikobewertung ein
   - Non-Conformities triggern Risiko-Reviews

### Vorteile der Data Reuse Architecture

- **Zeitersparnis**: Hunderte Stunden durch Vermeidung von Doppelerfassung
- **Konsistenz**: Einheitliche Datenbasis für alle Compliance-Anforderungen
- **Nachvollziehbarkeit**: Transparente Datenflüsse für Audits
- **Proaktive Insights**: Automatische Empfehlungen basierend auf vorhandenen Daten

### Services für Data Reuse

- `ProtectionRequirementService`: Intelligente CIA-Berechnung aus BCM/Incidents
- `RiskIntelligenceService`: Risiko-Empfehlungen aus Incident-History
- `ComplianceMappingService`: Cross-Framework Daten-Mapping
- `ComplianceAssessmentService`: Automatische Fulfillment-Berechnung

## Technologie-Stack

- **Framework**: Symfony 7.3 (neueste Version)
- **PHP**: 8.2 oder höher
- **Datenbank**: PostgreSQL/MySQL (über Doctrine ORM)
- **Frontend**: Twig Templates, Symfony UX (Stimulus, Turbo)
- **Testing**: PHPUnit

## Voraussetzungen

- PHP 8.2 oder höher
- Composer
- Eine Datenbank (PostgreSQL, MySQL oder SQLite)
- Symfony CLI (optional, für lokale Entwicklung)

## Installation

### 🚀 Schnellstart mit Deployment Wizard (Empfohlen)

Der einfachste Weg zur Installation ist der integrierte **Deployment Wizard**:

#### 1. Repository klonen und Dependencies installieren

```bash
git clone <repository-url>
cd Little-ISMS-Helper
composer install
```

#### 2. Umgebungskonfiguration

```bash
cp .env .env.local
```

Bearbeiten Sie `.env.local` und konfigurieren Sie mindestens die Datenbankverbindung:

```env
DATABASE_URL="mysql://user:password@localhost:3306/isms_helper"
# oder PostgreSQL:
# DATABASE_URL="postgresql://user:password@localhost:5432/isms_helper?serverVersion=16&charset=utf8"
```

#### 3. Deployment Wizard starten

```bash
php -S localhost:8000 -t public/
```

Öffnen Sie dann im Browser:

```
http://localhost:8000/setup
```

Der Wizard führt Sie durch:
- ✅ **Schritt 1**: System-Anforderungen automatisch prüfen
- ✅ **Schritt 2**: Module auswählen (Core ISMS, BCM, Compliance, etc.)
- ✅ **Schritt 3**: Datenbank automatisch initialisieren
- ✅ **Schritt 4**: Basis-Daten importieren (ISO 27001 Controls, Permissions)
- ✅ **Schritt 5**: Optional Beispiel-Daten laden
- ✅ **Schritt 6**: Setup abschließen

**Zeit**: ~5-10 Minuten für komplette Einrichtung

Weitere Details: [DEPLOYMENT_WIZARD.md](DEPLOYMENT_WIZARD.md)

---

### 🔧 Manuelle Installation (Alternative)

Falls Sie den Wizard nicht nutzen möchten:

#### 1-3. Wie oben (Repository klonen, Dependencies, .env)

#### 4. Datenbank manuell erstellen

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

#### 5. Basis-Daten laden

```bash
# ISO 27001 Annex A Controls (93 Controls)
php bin/console isms:load-annex-a-controls

# System-Berechtigungen
php bin/console app:setup-permissions
```

#### 6. Optional: Compliance-Frameworks laden

```bash
# TISAX (VDA ISA) für die Automobilindustrie
php bin/console app:load-tisax-requirements

# EU-DORA für Finanzdienstleister
php bin/console app:load-dora-requirements
```

#### 7. Optional: Beispiel-Daten laden

Beispiel-Daten befinden sich in `fixtures/*.yaml` und können manuell importiert werden.

#### 8. Assets installieren

```bash
php bin/console importmap:install
```

#### 9. Server starten

```bash
# Mit Symfony CLI:
symfony server:start

# Oder mit PHP Built-in Server:
php -S localhost:8000 -t public/
```

Die Anwendung ist dann unter `http://localhost:8000` erreichbar.

---

### 🔐 Authentication konfigurieren (Optional)

Für Azure AD Integration siehe: [docs/AUTHENTICATION_SETUP.md](docs/AUTHENTICATION_SETUP.md)

Unterstützte Methoden:
- Local Authentication (username/password)
- Azure Active Directory OAuth 2.0
- Azure Active Directory SAML 2.0

Die Basis-Authentication ist bereits konfiguriert. Für Azure AD müssen Sie:
1. Eine Azure AD App Registration erstellen
2. Client ID/Secret in `.env.local` konfigurieren
3. Callback URLs registrieren

---

### 📝 Audit Logging aktivieren (Optional)

Audit Logging ist standardmäßig aktiv. Details siehe: [docs/AUDIT_LOGGING.md](docs/AUDIT_LOGGING.md)

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

### ✅ Implementiert

- [x] Basis-Setup und Projektstruktur
- [x] Alle ISMS Kernentities (Asset, Risk, Control, Incident, etc.)
- [x] Statement of Applicability mit allen 93 Annex A Controls
- [x] Grundlegende Controller und Views für alle Module
- [x] KPI Dashboard mit Echtzeit-Daten
- [x] Datenbank-Migration
- [x] **User Authentication & Authorization** (Symfony Security + Azure AD)
- [x] **Deployment Wizard** mit geführter Einrichtung
- [x] **Audit Logging System** für Compliance
- [x] **Mehrsprachigkeit** (Deutsch/Englisch)
- [x] Business Continuity Management (BCM)
- [x] Multi-Framework Compliance (ISO 27001, TISAX, DORA)
- [x] Rollenbasierte Zugriffskontrolle (RBAC)

### 🚧 In Planung

- [ ] Vollständige CRUD-Operationen für alle Module
- [ ] Formulare mit Validierung
- [ ] Risk Assessment Matrix Visualisierung
- [ ] Erweiterte Reporting & Export Funktionen (PDF, Excel)
- [ ] Datei-Uploads für Nachweise und Dokumentation
- [ ] E-Mail-Benachrichtigungen für Vorfälle und Fälligkeiten
- [ ] REST API für Integration mit anderen Systemen
- [ ] Multi-Tenancy Support (für MSPs)
- [ ] Responsive Design Optimierung
- [ ] Automatisierte Tests (Unit, Integration)

## Autoren

Entwickelt für kleine und mittelständische Unternehmen, die ein pragmatisches und effizientes Tool für ihr ISMS benötigen.
