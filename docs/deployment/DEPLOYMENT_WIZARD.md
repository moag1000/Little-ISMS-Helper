# Deployment Wizard - Little ISMS Helper

## Übersicht

Der **10-Schritte Deployment Wizard** führt Sie Schritt für Schritt durch die komplette Einrichtung Ihres Little ISMS Helper Systems - **keine manuelle Konfiguration nötig!** Er übernimmt die Datenbank-Konfiguration, Admin-User-Erstellung, Email-Setup, Organisations-Informationen, Module-Auswahl, intelligente Compliance-Framework-Empfehlungen und den Import von Basis- und Beispieldaten.

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

#### Schritt 1: Datenbank-Konfiguration
Konfigurieren Sie Ihre Datenbank direkt im Web-Formular:
- **PostgreSQL** (empfohlen für Produktion)
- **MySQL** (alternative Option)
- **SQLite** (ideal für Tests und Entwicklung)
- Automatische APP_SECRET-Generierung
- Validierung der Datenbankverbindung
- Automatische Tabellenerstellung

**Keine manuelle .env-Bearbeitung erforderlich!**

#### Schritt 2: Admin-User erstellen
Erstellen Sie Ihren ersten Admin-User:
- Email-Adresse
- Sicheres Passwort (min. 8 Zeichen)
- Automatische ROLE_SUPER_ADMIN Zuweisung
- Passwort-Validierung in Echtzeit

#### Schritt 3: Email-Konfiguration (optional)
Richten Sie Email-Benachrichtigungen ein:
- **SMTP** - Eigener Mail-Server
- **Gmail** - Google Mail
- **Outlook** - Microsoft 365
- **Sendgrid** - Transactional Email Service
- **Überspringen** - Email-Setup für später

Vorteile: Automatische Benachrichtigungen für Audits, Risiken, Incidents, etc.

#### Schritt 4: Organisations-Informationen
Erfassen Sie grundlegende Informationen:
- **Name** der Organisation
- **Branche** (13 Optionen: Automotive, Finanzdienstleistungen, Energie, etc.)
- **Mitarbeiterzahl** (1-10, 11-50, 51-250, 251-1000, 1001+)
- **Land** (Deutschland, Österreich, Schweiz, etc.)

Diese Daten werden für intelligente Compliance-Framework-Empfehlungen genutzt.

#### Schritt 5: System-Anforderungen prüfen
Der Wizard prüft automatisch:
- ✅ PHP 8.2+ mit erforderlichen Extensions (pdo, mbstring, intl, xml, etc.)
- ✅ Datenbank-Konnektivität
- ✅ Schreibrechte für Verzeichnisse (var/, config/)
- ✅ Memory Limit (empfohlen: 256MB+)
- ✅ Symfony 7.3+

**Beheben Sie alle kritischen Fehler, bevor Sie fortfahren.**

#### Schritt 6: Module auswählen
Wählen Sie die Module aus, die Sie nutzen möchten:
- **Core ISMS** (erforderlich) - Basis-Funktionalität
- **Authentication** (erforderlich) - User & Role Management
- **Asset Management** - Informationswerte verwalten
- **Risk Management** - Risikobewertung und -behandlung
- **Control Management (SoA)** - ISO 27001 Annex A Controls
- **Incident Management** - Sicherheitsvorfälle
- **Audit Management** - Interne Audits
- **BCM** - Business Continuity Management
- **Compliance** - Multi-Framework Support
- **Training** - Schulungen und Awareness

Erforderliche Module werden automatisch aktiviert, Abhängigkeiten automatisch aufgelöst.

#### Schritt 7: Compliance Frameworks - **Intelligente Empfehlungen** ✨
Wählen Sie relevante Compliance-Frameworks:

**Automatische Empfehlungen basierend auf:**
- ✅ **Unternehmensgröße** - NIS2 nur für 51+ Mitarbeiter
- ✅ **Branche** - TISAX für Automotive, DORA für Finanzdienstleistungen
- ✅ **Land** - ISO 27701 für DACH-Region (statt GDPR)
- ✅ **Kritische Infrastruktur** - NIS2 für Energie/Telekom unabhängig von Größe

**Verfügbare Frameworks:**
- **ISO 27001** (immer vorausgewählt) - International Standard für ISMS
- **ISO 27701** - Privacy Information Management (DACH-Region)
- **GDPR** - Datenschutz-Grundverordnung (EU)
- **TISAX** - Automotive Information Security Standard
- **DORA** - Digital Operational Resilience Act (Finanzsektor)
- **NIS2** - Network and Information Security Directive (EU)
- **BSI IT-Grundschutz** - Deutscher Sicherheitsstandard

Empfohlene Frameworks sind automatisch vorausgewählt - Sie können die Auswahl anpassen.

#### Schritt 8: Datenbank initialisieren & Basis-Daten importieren
Automatischer Import erforderlicher Daten:
- ✅ Datenbank-Migrationen ausführen
- ✅ ISO 27001:2022 Annex A Controls (93 Controls)
- ✅ System Permissions (Rollen und Berechtigungen)
- ✅ Framework-spezifische Requirements (TISAX, DORA, NIS2, BSI)

Der Import erfolgt automatisch basierend auf ausgewählten Modulen und Frameworks.

#### Schritt 9: Beispiel-Daten (optional)
Wählen Sie optionale Beispiel-Daten zum Kennenlernen des Systems:
- 🔧 Assets (Server, Anwendungen, etc.)
- ⚠️ Risiko-Szenarien
- 🏢 Geschäftsprozesse mit BIA-Daten
- 🚨 Beispiel-Incidents
- 📋 Training-Programme

**Hinweis:** Nur zu Demonstrations- und Testzwecken - vor Produktivbetrieb entfernen!

#### Schritt 10: Setup abschließen
Setup ist erfolgreich abgeschlossen! 🎉

Sie können jetzt:
- ✅ Zum Dashboard navigieren
- ✅ Module nachträglich verwalten
- ✅ Mit der Nutzung beginnen
- ✅ Ihre Organisation konfigurieren

**Login:** Ihre im Schritt 2 erstellten Admin-Zugangsdaten

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

1. Prüfen Sie die [README.md](../README.md)
2. Konsultieren Sie die Logs in `var/log/`
3. Überprüfen Sie die Symfony-Profiler-Toolbar (Development)

## Technische Details

### Architektur

**Services**:
- `SystemRequirementsChecker`: Prüft System-Anforderungen
- `ModuleConfigurationService`: Verwaltet Module und Abhängigkeiten
- `DataImportService`: Importiert Basis- und Beispieldaten

**Controller**:
- `DeploymentWizardController`: 10-Schritt Wizard mit intelligenter Framework-Auswahl
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

Siehe [README.md](../README.md) für Lizenzinformationen.
