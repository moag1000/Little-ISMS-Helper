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

- **KPI Dashboard**: Echtzeit-Kennzahlen
  - Asset-Anzahl
  - Risiko-Übersicht
  - Offene Vorfälle
  - Compliance-Status (implementierte Controls)

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

### 5. ISO 27001 Annex A Controls laden

Laden Sie alle 93 Controls aus ISO 27001:2022 Annex A in die Datenbank:

```bash
php bin/console isms:load-annex-a-controls
```

Dies ist die Grundlage für Ihr Statement of Applicability.

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

- [x] Basis-Setup und Projektstruktur
- [x] Alle ISMS Kernentities (Asset, Risk, Control, Incident, etc.)
- [x] Statement of Applicability mit allen 93 Annex A Controls
- [x] Grundlegende Controller und Views für alle Module
- [x] KPI Dashboard mit Echtzeit-Daten
- [x] Datenbank-Migration
- [ ] User Authentication & Authorization (Symfony Security)
- [ ] Vollständige CRUD-Operationen für alle Module
- [ ] Formulare mit Validierung
- [ ] Risk Assessment Matrix Visualisierung
- [ ] Erweiterte Reporting & Export Funktionen (PDF, Excel)
- [ ] Datei-Uploads für Nachweise und Dokumentation
- [ ] E-Mail-Benachrichtigungen für Vorfälle und Fälligkeiten
- [ ] API für Integration mit anderen Systemen
- [ ] Multi-Tenancy Support (für MSPs)
- [ ] Responsive Design Optimierung
- [ ] Automatisierte Tests (Unit, Integration)

## Autoren

Entwickelt für kleine und mittelständische Unternehmen, die ein pragmatisches und effizientes Tool für ihr ISMS benötigen.
