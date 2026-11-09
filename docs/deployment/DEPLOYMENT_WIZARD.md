# Deployment Wizard - Little ISMS Helper

## Übersicht

Der Setup-Wizard unter `/setup` richtet eine neue Installation ein: Systemprüfung, Datenbank,
optionaler Backup-Restore, Admin-User, E-Mail, Organisation, Module, Compliance-Frameworks,
Basis- und Beispieldaten. Er hat zwölf Schritte (0–11). Ablauf mit Screenshots:
[QUICKSTART.md §5](../QUICKSTART.md#5-setup-wizard-durchlaufen).

| Schritt | Inhalt |
|---|---|
| 0 | Willkommen |
| 1 | System-Anforderungen (PHP-Version/Extensions, DB, Schreibrechte, Memory, Laufzeit) |
| 2 | Datenbank-Konfiguration (MySQL 8 / MariaDB 10.6+; in Docker automatisch) |
| 3 | Backup wiederherstellen (optional) oder Schema anlegen |
| 4 | Administrator (`ROLE_SUPER_ADMIN`) |
| 5 | E-Mail (optional) |
| 6 | Organisation |
| 7 | Module |
| 8 | Compliance-Frameworks (Empfehlung nach Branche und Größe) |
| 9 | Basis-Daten (u. a. 93 Annex-A-Controls) |
| 10 | Beispiel-Daten (optional) |
| 11 | Abschluss |

Nach dem Abschluss schreibt der Wizard `var/setup_complete.lock` (ältere Installationen:
`config/setup_complete.lock`, wird weiter gelesen). Danach ist `/setup` nur noch für
`ROLE_SUPER_ADMIN` erreichbar.

Nach dem Setup außerdem einrichten:

- `DEFAULT_URI` = öffentliche URL in `.env.local` (Links in Mails aus dem Scheduler)
- Worker für geplante Aufgaben: [HOSTING_WORKER.md](../user-guide/HOSTING_WORKER.md)
  (Docker: läuft bereits)

## Modulverwaltung

Nach dem Setup unter **Admin → Module** (`/admin/modules`):

- Modul aktivieren — abhängige Module werden mit aktiviert
- Modul deaktivieren — nur, wenn kein anderes aktives Modul davon abhängt
- Modul-Details: Beispieldaten nachträglich importieren, Daten als YAML exportieren
- Abhängigkeiten: `/admin/modules/dependency-graph`

Die Auswahl gilt pro Organisation (gespeichert am `Tenant`). Ohne Organisationskontext (CLI,
Setup) schreibt die Anwendung `config/active_modules.yaml`. Moduldefinitionen und
Abhängigkeiten: `config/modules.yaml`. Leitfaden: [MODULE_AKTIVIERUNG.md](../user-guide/MODULE_AKTIVIERUNG.md).

## CLI-Kommandos

```bash
# Basis-Daten manuell importieren
php bin/console isms:load-annex-a-controls
php bin/console app:load-tisax-requirements
php bin/console app:load-dora-requirements
php bin/console list app:load          # alle Framework-Loader

# Migrationen
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:migrations:status
```

### Setup zurücksetzen (nur Development)

`/setup/reset` (nur bei `APP_ENV=dev`) oder:

```bash
rm var/setup_complete.lock config/setup_complete.lock
```

## Fehlerbehebung

| Meldung | Lösung |
|---|---|
| "System erfüllt kritische Anforderungen nicht" | Extensions nachinstallieren: `sudo apt-get install php8.4-{mysql,mbstring,intl,xml,zip,opcache}` |
| "Datenbank nicht konfiguriert" | `DATABASE_URL="mysql://user:password@127.0.0.1:3306/little_isms?serverVersion=8.0&charset=utf8mb4"` in `.env.local` |
| "Verzeichnisse nicht beschreibbar" | `chown -R www-data:www-data var/ public/uploads/` und `chmod -R 775 var/` |
| "Modul kann nicht deaktiviert werden" | Zuerst die abhängigen Module deaktivieren |
| Schema-Fehler nach einem Update | `/quick-fix` ([QUICK_FIX.md](../user-guide/QUICK_FIX.md)) |

## Hinweise

- Beispieldaten nur in Test- und Lernumgebungen laden; entfernen unter Admin → Beispiel-Daten.
- `.env*`-Dateien liegen außerhalb des Document Roots (`public/`).

## Technische Details

- `DeploymentWizardController` — Wizard-Schritte (`templates/setup/`)
- `SystemRequirementsChecker` — Schritt 1
- `Setup\DatabaseProvisioner`, `Setup\SetupTenantBootstrapper` — Schema, erste Organisation, Admin
- `ModuleConfigurationService`, `DataImportService` — Module, Basis- und Beispieldaten
- `AdminModuleController` — Modulverwaltung nach dem Setup
- `SetupAccessChecker` — Lock-Datei und Zugriff nach Abschluss
