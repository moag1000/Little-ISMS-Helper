# Symfony Scheduler Integration

## Übersicht

Little ISMS Helper verwendet den Symfony Scheduler für automatisierte, zeitgesteuerte Aufgaben. Dies ersetzt traditionelle Cron-Jobs und bietet bessere Integration, Logging und Verwaltbarkeit.

## Architektur

### Komponenten

1. **Schedule Provider** (`src/Schedule.php`)
   - Zentrale Konfiguration aller geplanten Aufgaben
   - Kombiniert Built-in und Datenbank-Tasks
   - Nutzt Symfony Cache für Stateful Scheduling

2. **Built-in Tasks** (fest codiert)
   - Session Cleanup (täglich 3:00 Uhr)
   - Risk Review Checks (täglich 8:00 Uhr)
   - Compliance Reports (montags 6:00 Uhr)

3. **Database Tasks** (dynamisch über UI verwaltbar)
   - Gespeichert in `scheduled_task` Tabelle
   - Über `ScheduledTaskService` verwaltbar
   - Mandantenisoliert

4. **Message Handlers**
   - `CleanupExpiredSessionsHandler` - Löscht abgelaufene Sessions
   - `CheckRiskReviewsHandler` - Prüft fällige Risk Reviews (ISO 27001:2022 Clause 6.1.3.d)
   - `GenerateComplianceReportHandler` - Erstellt wöchentliche Compliance Reports
   - `ExecuteScheduledTaskHandler` - Führt Datenbank-definierte Tasks aus

### Supervisor Integration

In Docker werden zwei Supervisor-Prozesse gestartet:

1. **messenger-scheduler** - Verarbeitet Scheduler-Messages
   ```ini
   command=php /var/www/html/bin/console messenger:consume scheduler -vv
   ```

2. **scheduler-runner** - Führt den Scheduler aus
   ```ini
   command=php /var/www/html/bin/console scheduler:run --verbose
   ```

## Built-in Scheduled Tasks

### 1. Session Cleanup (`CleanupExpiredSessionsMessage`)

**Schedule:** Täglich um 3:00 Uhr
**Cron:** `0 3 * * *`
**Handler:** `CleanupExpiredSessionsHandler`

**Funktion:**
- Löscht abgelaufene Session-Records aus der Datenbank
- Reduziert Datenbankgröße
- Verbessert Performance

**Verwendung:**
```php
// Wird automatisch ausgeführt, keine manuelle Interaktion nötig
```

### 2. Risk Review Check (`CheckRiskReviewsMessage`)

**Schedule:** Täglich um 8:00 Uhr
**Cron:** `0 8 * * *`
**Handler:** `CheckRiskReviewsHandler`
**Compliance:** ISO 27001:2022 Clause 6.1.3.d

**Funktion:**
- Identifiziert Risiken mit fälligem Review-Datum
- Sendet Erinnerungen an Risk Owner
- Erfüllt ISO 27001 Review-Anforderungen

**Email-Benachrichtigung:**
- Template: `emails/risk_review_notification.html.twig`
- Empfänger: Risk Owner
- Inhalt: Risk-Details, Fälligkeitsdatum

### 3. Compliance Report (`GenerateComplianceReportMessage`)

**Schedule:** Montags um 6:00 Uhr
**Cron:** `0 6 * * 1`
**Handler:** `GenerateComplianceReportHandler`

**Funktion:**
- Berechnet Compliance-Statistiken
- Zählt erfüllte vs. nicht-erfüllte Requirements
- Loggt Report-Daten (zukünftig: Email-Versand)

## Dynamische Tasks (UI-verwaltbar)

### Entity: `ScheduledTask`

**Felder:**
- `name` - Task-Name
- `description` - Beschreibung
- `cronExpression` - Cron-Format (`0 3 * * *`)
- `command` - Symfony Console Command (`app:cleanup-temp-files`)
- `arguments` - Command-Argumente (JSON Array)
- `enabled` - Aktiviert/Deaktiviert
- `lastRunAt` - Letzte Ausführung
- `nextRunAt` - Nächste Ausführung
- `lastOutput` - Ausgabe der letzten Ausführung
- `lastStatus` - Status: `success`, `failed`, `running`
- `tenantId` - Mandanten-ID

### Service: `ScheduledTaskService`

**API:**

```php
// Task erstellen
$task = $scheduledTaskService->createTask(
    name: 'Backup Database',
    command: 'app:backup-database',
    cronExpression: '0 2 * * *', // Täglich 2:00 Uhr
    description: 'Daily database backup',
    arguments: ['--format=sql']
);

// Task aktualisieren
$scheduledTaskService->updateTask(
    $task,
    cronExpression: '0 1 * * *' // Neue Zeit: 1:00 Uhr
);

// Task aktivieren/deaktivieren
$scheduledTaskService->toggleTask($task, enabled: false);

// Task löschen
$scheduledTaskService->deleteTask($task);

// Cron-Expression validieren
$isValid = $scheduledTaskService->validateCronExpression('0 3 * * *');

// Statistiken abrufen
$stats = $scheduledTaskService->getStatistics();
// [
//     'total' => 5,
//     'enabled' => 3,
//     'disabled' => 2,
//     'last_success' => 3,
//     'last_failed' => 0,
//     'running' => 0,
// ]
```

### Task-Ausführung

Datenbank-Tasks werden via `ExecuteScheduledTaskMessage` ausgeführt:

1. Scheduler erkennt fällige Tasks
2. Sendet `ExecuteScheduledTaskMessage` mit Task-ID
3. `ExecuteScheduledTaskHandler` führt Command aus:
   ```bash
   php bin/console {command} {arguments}
   ```
4. Speichert Ausgabe und Status in DB

**Timeout:** 1 Stunde (3600 Sekunden)

## Messenger Configuration

**Transport:** `scheduler` (Doctrine-basiert)

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            scheduler:
                dsn: 'doctrine://default?queue_name=scheduler'
                options:
                    auto_setup: true
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2

        routing:
            App\Message\Schedule\CleanupExpiredSessionsMessage: scheduler
            App\Message\Schedule\CheckRiskReviewsMessage: scheduler
            App\Message\Schedule\GenerateComplianceReportMessage: scheduler
            App\Message\Schedule\ExecuteScheduledTaskMessage: scheduler
```

## Cron-Expression Syntax

**Format:** `minute hour day month weekday`

**Beispiele:**

| Expression | Bedeutung |
|------------|-----------|
| `0 3 * * *` | Täglich um 3:00 Uhr |
| `0 8 * * *` | Täglich um 8:00 Uhr |
| `0 6 * * 1` | Montags um 6:00 Uhr |
| `*/15 * * * *` | Alle 15 Minuten |
| `0 2 * * 0` | Sonntags um 2:00 Uhr |
| `0 1 1 * *` | 1. Tag jedes Monats um 1:00 Uhr |
| `0 0 * * 1-5` | Montag-Freitag um Mitternacht |

**Online-Tool:** https://crontab.guru/

## Console Commands

### Scheduler ausführen

```bash
# Scheduler einmalig ausführen
php bin/console scheduler:run

# Verbose-Ausgabe
php bin/console scheduler:run --verbose

# In Docker (via Supervisor automatisch)
supervisorctl restart scheduler-runner
```

### Messenger Worker starten

```bash
# Scheduler-Messages verarbeiten
php bin/console messenger:consume scheduler -vv

# Mit Limits
php bin/console messenger:consume scheduler --time-limit=3600 --memory-limit=128M

# In Docker (via Supervisor automatisch)
supervisorctl restart messenger-scheduler
```

### Migrations

```bash
# Migration anwenden (für scheduled_task Tabelle)
php bin/console doctrine:migrations:migrate --no-interaction
```

## Docker Integration

### Supervisor Configuration

**File:** `docker/supervisor/supervisord.conf`

```ini
[program:messenger-scheduler]
command=/bin/sh -c "sleep 10 && php /var/www/html/bin/console messenger:consume scheduler -vv --time-limit=3600 --memory-limit=128M"
autostart=true
autorestart=true
priority=15
user=www-data

[program:scheduler-runner]
command=/bin/sh -c "sleep 15 && php /var/www/html/bin/console scheduler:run --verbose"
autostart=true
autorestart=true
priority=20
user=www-data
```

**Logs:**
- Messenger: `/var/log/supervisor/messenger-scheduler.log`
- Scheduler: `/var/log/supervisor/scheduler-runner.log`

### Supervisor Management

```bash
# Status prüfen
docker exec isms-app supervisorctl status

# Prozess neu starten
docker exec isms-app supervisorctl restart messenger-scheduler
docker exec isms-app supervisorctl restart scheduler-runner

# Logs anzeigen
docker exec isms-app tail -f /var/log/supervisor/scheduler-runner.log
```

## Monitoring & Troubleshooting

### Logs überprüfen

```bash
# Application Logs
tail -f var/log/dev.log | grep -i schedule

# Supervisor Logs (in Docker)
docker exec isms-app tail -f /var/log/supervisor/messenger-scheduler.log
docker exec isms-app tail -f /var/log/supervisor/scheduler-runner.log
```

### Häufige Probleme

**Problem:** Tasks werden nicht ausgeführt

**Lösung:**
1. Prüfe Supervisor-Status: `supervisorctl status`
2. Prüfe Messenger Worker: `messenger-scheduler` läuft?
3. Prüfe Scheduler Runner: `scheduler-runner` läuft?
4. Prüfe Logs auf Fehler

**Problem:** "No messages to consume"

**Lösung:**
- Normal! Scheduler sendet Messages nur zu geplanten Zeiten
- Prüfe mit `scheduler:run --verbose` ob Tasks erkannt werden

**Problem:** Database-Tasks werden nicht geladen

**Lösung:**
1. Prüfe Migration: `php bin/console doctrine:migrations:status`
2. Prüfe Tabelle: `SELECT * FROM scheduled_task;`
3. Prüfe `enabled` Flag

## Best Practices

### 1. Task-Design

- **Idempotent:** Tasks sollten mehrfach ausführbar sein ohne Probleme
- **Timeout:** Lange laufende Tasks in Chunks aufteilen
- **Fehlerbehandlung:** Exceptions loggen, aber nicht komplett abbrechen

### 2. Cron-Expressions

- **Zeitzone beachten:** Server-Zeitzone verwenden
- **Überlappungen vermeiden:** Tasks nicht zu eng takten
- **Wartungsfenster nutzen:** Intensive Tasks nachts ausführen

### 3. Monitoring

- **Logs prüfen:** Regelmäßig Supervisor-Logs checken
- **Status tracken:** `lastStatus` und `lastRunAt` überwachen
- **Alerts einrichten:** Bei wiederholten Fehlern benachrichtigen

### 4. Performance

- **Memory Limits:** Worker mit `--memory-limit` begrenzen
- **Time Limits:** Worker mit `--time-limit` neu starten
- **Queue überwachen:** Messenger-Queue nicht überlaufen lassen

## Erweiterungen

### Neuen Built-in Task hinzufügen

1. **Message erstellen:**
   ```php
   // src/Message/Schedule/MyTaskMessage.php
   namespace App\Message\Schedule;

   class MyTaskMessage
   {
       public function __construct(
           private readonly \DateTimeImmutable $scheduledAt = new \DateTimeImmutable()
       ) {}
   }
   ```

2. **Handler erstellen:**
   ```php
   // src/MessageHandler/Schedule/MyTaskHandler.php
   namespace App\MessageHandler\Schedule;

   use Symfony\Component\Messenger\Attribute\AsMessageHandler;

   #[AsMessageHandler]
   class MyTaskHandler
   {
       public function __invoke(MyTaskMessage $message): void
       {
           // Task-Logik hier
       }
   }
   ```

3. **Zu Schedule hinzufügen:**
   ```php
   // src/Schedule.php
   private function addBuiltInTasks(SymfonySchedule $schedule): void
   {
       // ... existing tasks ...

       $schedule->add(
           RecurringMessage::cron('0 9 * * *', new MyTaskMessage())
       );
   }
   ```

4. **Routing konfigurieren:**
   ```yaml
   # config/packages/messenger.yaml
   routing:
       App\Message\Schedule\MyTaskMessage: scheduler
   ```

### UI für Task-Management (zukünftig)

**Controller:** `ScheduledTaskController`
**Routes:**
- `GET /admin/scheduled-tasks` - Liste aller Tasks
- `POST /admin/scheduled-tasks` - Task erstellen
- `PUT /admin/scheduled-tasks/{id}` - Task bearbeiten
- `DELETE /admin/scheduled-tasks/{id}` - Task löschen
- `POST /admin/scheduled-tasks/{id}/toggle` - Task aktivieren/deaktivieren

**Permissions:**
- `scheduled_task.view`
- `scheduled_task.create`
- `scheduled_task.edit`
- `scheduled_task.delete`

## Compliance

### ISO 27001:2022 Bezüge

- **Clause 6.1.3.d:** Periodic Risk Review - `CheckRiskReviewsHandler`
- **Clause 9.3:** Management Review - `GenerateComplianceReportHandler`
- **Clause 7.5.3:** Control of documented information - Automatische Reports

### Audit Trail

Alle Task-Ausführungen werden geloggt:
- Zeitstempel (`lastRunAt`)
- Status (`lastStatus`)
- Ausgabe (`lastOutput`)
- Via AuditLogger (bei kritischen Tasks)

## Referenzen

- [Symfony Scheduler Documentation](https://symfony.com/doc/current/scheduler.html)
- [Symfony Messenger Documentation](https://symfony.com/doc/current/messenger.html)
- [Crontab Guru](https://crontab.guru/) - Cron Expression Generator
- [ISO 27001:2022 Standard](https://www.iso.org/standard/27001)
