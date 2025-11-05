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

### Geplante Module

- **Asset Management**: Verwaltung von IT-Assets und Informationswerten
- **Risk Assessment**: Risikobewertung und -behandlung
- **Compliance Tracking**: Überwachung der Einhaltung von ISO 27001 Anforderungen
- **Incident Management**: Dokumentation von Sicherheitsvorfällen
- **KPI Dashboard**: Visualisierung von Sicherheitskennzahlen
- **Audit Management**: Planung und Durchführung von internen Audits
- **Policy Management**: Verwaltung von Sicherheitsrichtlinien
- **Training Records**: Dokumentation von Awareness-Schulungen

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

### 5. Assets installieren

```bash
php bin/console importmap:install
```

### 6. Entwicklungsserver starten

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

- [ ] Basis-Setup und Projektstruktur
- [ ] User Authentication & Authorization
- [ ] Asset Management Modul
- [ ] Risk Assessment Modul
- [ ] KPI Dashboard
- [ ] Compliance Tracking
- [ ] Incident Management
- [ ] Reporting & Export Funktionen
- [ ] Multi-Tenancy Support (für MSPs)

## Autoren

Entwickelt für kleine und mittelständische Unternehmen, die ein pragmatisches und effizientes Tool für ihr ISMS benötigen.
