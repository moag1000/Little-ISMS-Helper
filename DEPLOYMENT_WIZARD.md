# Deployment Wizard - Little ISMS Helper

## Übersicht

Der Deployment Wizard führt Sie Schritt für Schritt durch die Einrichtung Ihres Little ISMS Helper Systems. Er prüft System-Anforderungen, lässt Sie Module auswählen, initialisiert die Datenbank und importiert Basis- sowie optionale Beispieldaten.

## Features

### 🔍 System-Anforderungen prüfen
- PHP-Version und Extensions
- Datenbank-Konnektivität
- Verzeichnis-Berechtigungen
- Memory Limit und Execution Time
- Symfony-Version

### 🧩 Modulare Architektur
- **Core ISMS** (erforderlich): Basis-Funktionalität
- **Authentication** (erforderlich): User & Role Management mit Azure AD
- **Asset Management**: Verwaltung von Informationswerten
- **Risk Management**: Risikobewertung und -behandlung
- **Control Management (SoA)**: ISO 27001 Annex A Controls
- **Incident Management**: Sicherheitsvorfälle verwalten
- **Audit Management**: Interne Audits und Prüfungen
- **BCM**: Business Continuity Management
- **Compliance**: Multi-Framework (ISO 27001, TISAX, DORA)
- **Training**: Schulungen und Awareness
- **Reviews**: Management-Bewertungen
- **Audit Logging**: Umfassendes Aktivitätsprotokoll

### 📦 Automatischer Datenimport
- **Basis-Daten** (automatisch):
  - ISO 27001:2022 Annex A Controls (93 Controls)
  - System Permissions (Rollen und Berechtigungen)
  - TISAX Requirements (optional, bei aktiviertem Compliance-Modul)
  - DORA Requirements (optional, bei aktiviertem Compliance-Modul)

- **Beispiel-Daten** (optional):
  - Vordefinierte Assets (Server, Anwendungen, etc.)
  - Typische Risiko-Szenarien
  - Geschäftsprozesse mit BIA-Daten
  - Beispiel-Incidents

### 🔄 Nachträgliche Modulverwaltung
- Module aktivieren/deaktivieren
- Automatische Abhängigkeitsauflösung
- Daten exportieren/importieren
- Dependency-Graph visualisieren

## Schnellstart

### 1. Deployment Wizard starten

Navigieren Sie in Ihrem Browser zu:

```
http://localhost:8000/setup
```

### 2. Wizard-Schritte durchlaufen

#### Schritt 1: System-Anforderungen
Der Wizard prüft automatisch:
- PHP 8.2+ mit erforderlichen Extensions
- Datenbank-Konfiguration
- Schreibrechte für Verzeichnisse
- Symfony 7.3+

**Beheben Sie alle kritischen Fehler, bevor Sie fortfahren.**

#### Schritt 2: Module auswählen
Wählen Sie die Module aus, die Sie nutzen möchten:
- Erforderliche Module werden automatisch aktiviert
- Abhängigkeiten werden automatisch aufgelöst
- Empfehlung: Starten Sie mit wenigen Modulen und erweitern Sie später

#### Schritt 3: Datenbank initialisieren
- Migrations werden automatisch ausgeführt
- Datenbank-Tabellen werden erstellt
- Bestehende Daten bleiben erhalten

#### Schritt 4: Basis-Daten importieren
Erforderliche Daten werden automatisch geladen:
- ISO 27001 Annex A Controls
- Framework-spezifische Requirements (TISAX, DORA)

#### Schritt 5: Beispiel-Daten (optional)
Wählen Sie optionale Beispiel-Daten:
- Assets, Risks, Business Processes
- Incidents
- Nur zu Demonstrations- und Testzwecken

#### Schritt 6: Abschluss
Setup ist abgeschlossen! Sie können:
- Zum Dashboard navigieren
- Module nachträglich verwalten
- Mit der Nutzung beginnen

## Modulverwaltung

Nach dem Setup können Sie Module jederzeit über die Modulverwaltung anpassen:

```
http://localhost:8000/modules
```

### Module aktivieren

1. Navigieren Sie zu "Modulverwaltung"
2. Wählen Sie ein inaktives Modul
3. Klicken Sie auf "Aktivieren"
4. Abhängige Module werden automatisch mit aktiviert

### Module deaktivieren

1. Navigieren Sie zu "Modulverwaltung"
2. Wählen Sie ein aktives Modul
3. Klicken Sie auf "Deaktivieren"
4. **Hinweis**: Module können nur deaktiviert werden, wenn keine anderen Module davon abhängen

### Beispiel-Daten nachträglich importieren

1. Öffnen Sie die Modul-Details
2. Wählen Sie verfügbare Beispiel-Daten
3. Klicken Sie auf "Importieren"

### Modul-Daten exportieren

1. Öffnen Sie die Modul-Details
2. Klicken Sie auf "Daten exportieren"
3. YAML-Datei wird heruntergeladen (für Backup oder Migration)

## Modul-Abhängigkeiten

### Dependency-Graph anzeigen

```
http://localhost:8000/modules/dependency-graph
```

Visualisiert alle Abhängigkeiten zwischen Modulen:
- **Benötigt**: Module, die vorher aktiviert sein müssen
- **Benötigt von**: Module, die dieses Modul benötigen

### Beispiel-Abhängigkeiten

```
Core ISMS (erforderlich)
├── benötigt: -
└── benötigt von: alle anderen Module (indirekt)

Authentication (erforderlich)
├── benötigt: -
└── benötigt von: Audit Logging

Asset Management
├── benötigt: -
└── benötigt von: Risk Management, BCM

Risk Management
├── benötigt: Asset Management
└── benötigt von: -

Control Management (SoA)
├── benötigt: -
└── benötigt von: Incident Management, Compliance, Audits

Incident Management
├── benötigt: Control Management
└── benötigt von: -

Audit Management
├── benötigt: Control Management
└── benötigt von: Management Review

Compliance Management
├── benötigt: Control Management
└── benötigt von: Audit Management

Audit Logging
├── benötigt: Authentication
└── benötigt von: -
```

## Konfiguration

### Module-Konfiguration

Datei: `config/modules.yaml`

Hier können Sie:
- Neue Module definieren
- Abhängigkeiten anpassen
- Icons und Beschreibungen ändern
- Basis- und Beispieldaten konfigurieren

### Aktive Module

Datei: `config/active_modules.yaml` (automatisch generiert)

Enthält die Liste der aktuell aktivierten Module.

## CLI-Kommandos

### Basis-Daten manuell importieren

```bash
# ISO 27001 Annex A Controls
php bin/console isms:load-annex-a-controls

# TISAX Requirements
php bin/console app:load-tisax-requirements

# DORA Requirements
php bin/console app:load-dora-requirements
```

### Datenbank-Migrationen

```bash
# Migrationen ausführen
php bin/console doctrine:migrations:migrate

# Migrations-Status prüfen
php bin/console doctrine:migrations:status
```

### Setup zurücksetzen (nur Development)

Im Browser:
```
http://localhost:8000/setup/reset
```

Oder manuell:
```bash
rm config/setup_complete.lock
rm config/active_modules.yaml
```

## Fehlerbehebung

### "System erfüllt kritische Anforderungen nicht"

**Problem**: PHP-Extensions fehlen

**Lösung**:
```bash
# Ubuntu/Debian
sudo apt-get install php8.2-{pdo,pdo-mysql,mbstring,intl,xml,json,zip,opcache}

# macOS (Homebrew)
brew install php@8.2
```

### "Datenbank nicht konfiguriert"

**Problem**: DATABASE_URL fehlt

**Lösung**: Bearbeiten Sie `.env` oder `.env.local`:
```
DATABASE_URL="mysql://user:password@127.0.0.1:3306/little_isms?serverVersion=8.0"
```

### "Verzeichnisse nicht beschreibbar"

**Problem**: Schreibrechte fehlen

**Lösung**:
```bash
chmod -R 755 var/cache var/log var/sessions
chown -R www-data:www-data var/
```

### "Modul kann nicht deaktiviert werden"

**Problem**: Andere Module hängen davon ab

**Lösung**: Deaktivieren Sie zuerst die abhängigen Module, dann das gewünschte Modul.

## Best Practices

### 1. Starten Sie minimal

Aktivieren Sie zunächst nur die Module, die Sie wirklich benötigen:
- Core ISMS (Pflicht)
- Asset Management
- Risk Management
- Control Management

Erweitern Sie später nach Bedarf.

### 2. Nutzen Sie Beispieldaten zum Testen

Beispieldaten helfen Ihnen:
- Das System kennenzulernen
- Funktionen zu testen
- Berichte und Dashboards zu evaluieren

**Löschen Sie Beispieldaten vor Produktivbetrieb!**

### 3. Exportieren Sie regelmäßig

Nutzen Sie die Export-Funktion für:
- Backups
- Migration zwischen Umgebungen
- Versionierung von Konfigurationen

### 4. Dependency-Graph verstehen

Prüfen Sie vor dem Deaktivieren von Modulen:
- Welche Module hängen davon ab?
- Welche Funktionen gehen verloren?

## Sicherheitshinweise

### Setup-Lock nach Produktivnahme

Der Wizard erstellt `config/setup_complete.lock` nach erfolgreichem Setup.

**Wichtig**: In Production-Umgebungen sollten Sie:
1. `/setup/*` Routen über Web-Server blockieren
2. Den Setup-Controller entfernen oder deaktivieren

### Beispieldaten entfernen

Vor Produktivbetrieb:
1. Löschen Sie alle Beispieldaten manuell
2. Oder: Datenbank neu aufsetzen mit nur Basis-Daten

### Berechtigungen prüfen

Stellen Sie sicher, dass:
- Web-Server nur minimale Schreibrechte hat
- Datenbank-User nur benötigte Rechte besitzt
- `.env` Dateien nicht öffentlich zugänglich sind

## Support

Bei Fragen oder Problemen:

1. Prüfen Sie die [README.md](README.md)
2. Konsultieren Sie die Logs in `var/log/`
3. Überprüfen Sie die Symfony-Profiler-Toolbar (Development)

## Technische Details

### Architektur

**Services**:
- `SystemRequirementsChecker`: Prüft System-Anforderungen
- `ModuleConfigurationService`: Verwaltet Module und Abhängigkeiten
- `DataImportService`: Importiert Basis- und Beispieldaten

**Controller**:
- `DeploymentWizardController`: 6-Schritt Wizard
- `ModuleManagementController`: Nachträgliche Modulverwaltung

**Templates**:
- `templates/setup/*`: Wizard-Schritte
- `templates/module_management/*`: Modulverwaltung

### Datenbank

Migrations werden in der Reihenfolge ausgeführt:
1. `Version20251105000000`: Core ISMS Tables
2. `Version20251105000001`: BCM Module
3. `Version20251105000002`: Compliance Framework
4. `Version20251105000003`: Audit Enhancements

## Changelog

### Version 1.0.0 (2024-02-20)
- ✅ Initiale Version des Deployment Wizards
- ✅ System-Anforderungen-Prüfung
- ✅ Modulare Architektur mit Abhängigkeiten
- ✅ Automatischer Datenimport
- ✅ Nachträgliche Modulverwaltung
- ✅ Beispieldaten für alle Module
- ✅ Dependency-Graph Visualisierung

## Lizenz

Siehe [README.md](README.md) für Lizenzinformationen.
