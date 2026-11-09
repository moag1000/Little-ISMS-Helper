# Symfony Scheduler

Alle zeitgesteuerten Aufgaben laufen über den Symfony Scheduler. Es gibt keine
einzelnen Crontab-Zeilen pro Aufgabe. Einrichtung des Workers (Cron-Zeile, systemd,
Docker): [user-guide/HOSTING_WORKER.md](user-guide/HOSTING_WORKER.md).

## Funktionsweise

- **Schedule Provider:** `src/Schedule.php` (`#[AsSchedule]`, Name `default`).
  Stateful über den Symfony-Cache; ein verpasster Lauf wird einmal nachgeholt
  (`processOnlyLastMissedRun`).
- **Transport:** `scheduler_default`. Der Scheduler tickt nur, wenn ein Worker ihn
  abarbeitet:
  ```bash
  php bin/console messenger:consume scheduler_default async --time-limit=55
  ```
- **Docker:** `supervisord` startet den Prozess `messenger-scheduler`
  (`messenger:consume scheduler_default scheduler --time-limit=3600 --memory-limit=128M`,
  Log `/var/log/supervisor/messenger-scheduler.log`).
- Uhrzeiten gelten in der PHP-Zeitzone des Servers (`date.timezone`).

Liste der geplanten Aufgaben mit nächstem Lauf:

```bash
php bin/console debug:scheduler
```

## Feste Aufgaben

Commands stehen in `Schedule::COMMAND_TASKS`, Messages in `addBuiltInTasks()`.

| Zeit (Cron) | Aufgabe | Zweck |
|---|---|---|
| `*/15 * * * *` | `app:process-timed-workflows` | Zeitbasierte Workflow-Weiterschaltung |
| `*/15 * * * *` | `app:send-reminders` | Erinnerungen: Stundenfristen sofort, Sammelmail zur Organisations-Uhrzeit (Standard 07:00) |
| `0 * * * *` | `app:process-scheduled-reports` | Fällige geplante Berichte versenden |
| `45 0 * * *` | `app:compliance:refresh-fulfillments` | Erfüllungsgrade neu ableiten |
| `0 1 * * *` | `app:kpi-snapshot` | KPI-Snapshot je Organisation |
| `15 1 * * *` | `app:portfolio:capture-snapshot` | Portfolio-Snapshot |
| `30 1 * * *` | `app:reuse:capture-snapshot` | Reuse-Trend-Snapshot |
| `0 2 * * *` | `app:backup:create` | Instanzweites Backup nach `var/backups/` |
| `30 2 * * *` | `app:backup:prune` | Backups älter als 30 Tage löschen, die letzten 10 bleiben |
| `45 2 * * *` | `app:audit-log:cleanup --force` | Audit-Log nach Aufbewahrungsfrist bereinigen |
| `0 3 * * *` | `app:four-eyes:cleanup-expired` | Abgelaufene Vier-Augen-Anfragen markieren |
| `0 3 * * *` | `CleanupExpiredSessionsMessage` | Abgelaufene Sessions löschen |
| `15 3 * * *` | `app:policy-wizard:purge-sandboxes` | Sandbox-Läufe des Policy-Wizards löschen (> 7 Tage) |
| `35 3 * * *` | `app:import:purge-abandoned` | Upload-Dateien nie festgeschriebener Bulk-Importe löschen (> 30 Tage); festgeschriebene bleiben als Nachweis |
| `10 4 * * *` | `app:uploads:protect` | Nachweisdateien aus `public/uploads/` nach `var/uploads/` verschieben |
| `0 4 * * 0` | `EnforceRetentionMessage` | Aufbewahrungsrichtlinien durchsetzen (DSGVO Art. 5(1)(e)) |
| `0 2 * * 0` | `app:tisax:purge-old-ip-addresses` | IP-Adressen alter TISAX-Bestätigungen entfernen |
| `0 5 * * *` | `app:evidence:expire` | Abgelaufene Nachweise und Zertifikate markieren |
| `0 5 * * *` | `SyncEuvdFeedMessage` | ENISA-EUVD-Feed abgleichen |
| `30 5 * * *` | `app:suggestions:generate` | Vorschlags-Warteschlange füllen |
| `30 5 * * *` | `app:risk:schedule-reviews` | Review-Termine für Risiken ohne Termin setzen |
| `40 5 * * *` | `app:policy-wizard:send-ack-requests` | Offene Richtlinien-Bestätigungen an Zielgruppe und Version angleichen |
| `0 7 * * *` | `app:notification:send-digests` | Digest-Mails der Benachrichtigungskanäle |

Jeder Command wird mit `--no-interaction` aufgerufen und begrenzt die
Organisationen selbst. Für Tests von Hand:

```bash
php bin/console app:send-reminders --dry-run      # wer bekäme was
php bin/console app:send-reminders --digest-now   # Sammelmail sofort
```

`app:send-reminders` ist der einzige Erinnerungsweg (Fristenquellen →
`ResponsibilityResolver` → Mail + Glocke, Tabelle `obligation_notice` gegen
Doppelversand). Details: [DEADLINES_AND_RESPONSIBILITY.md](DEADLINES_AND_RESPONSIBILITY.md).

## Aufgaben aus der Datenbank

Zeilen der Tabelle `scheduled_task` mit `enabled = 1` werden beim Aufbau des
Schedules geladen und über `ExecuteScheduledTaskMessage` ausgeführt
(`ExecuteScheduledTaskHandler`, Timeout 1 Stunde, Ergebnis in `lastStatus` /
`lastOutput`). Angelegt werden sie über `App\Service\ScheduledTaskService`
(`createTask()`, `updateTask()`, `toggleTask()`, `deleteTask()`); eine Admin-Oberfläche
gibt es nicht.

**Erlaubte Commands:** nur die Whitelist `ExecuteScheduledTaskHandler::ALLOWED_COMMANDS`.
Aus den Argumenten werden der Umgebungsschalter in jeder Schreibweise (`-e`, `-eprod`,
`-e prod`, `--env`, `--env=prod`, `--env prod` samt Wert) und `--kernel-class…`
entfernt; bei `doctrine:migrations:migrate` zusätzlich jedes Positionsargument
(`first`/`prev`/eine Version würde zurückmigrieren). Entfernte Argumente landen als
Warnung im Log (`ExecuteScheduledTaskHandler::filterArguments()`).

## Neue feste Aufgabe

- Ein Console-Command, der periodisch laufen soll: eine Zeile in
  `Schedule::COMMAND_TASKS` (`['<cron>', '<command>']`). Der Command scoped die
  Organisationen selbst.
- Eine Erinnerung an eine Frist ist **keine** neue Aufgabe, sondern eine
  `DeadlineSource` (siehe CLAUDE.md).
- Eigene Message + Handler nur, wenn kein Command passt; dann in `addBuiltInTasks()`
  per `RecurringMessage::cron()` eintragen.

## Troubleshooting

| Problem | Prüfen |
|---|---|
| Keine Aufgabe läuft | Läuft ein Worker auf `scheduler_default`? Ampel unter `/admin/queue-status` |
| Docker: Aufgaben laufen nicht | `docker compose exec app supervisorctl status messenger-scheduler` |
| Datenbank-Task fehlt | `debug:scheduler`; `enabled`-Flag; Command auf der Whitelist? |
| Aufgabe läuft doppelt | Alte Einzel-Cron-Zeile für denselben Command noch im Crontab |

Logs: `var/log/prod.log`, in Docker zusätzlich
`/var/log/supervisor/messenger-scheduler*.log`.
