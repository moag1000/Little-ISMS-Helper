# Worker und geplante Aufgaben

Zielgruppe: Admins, die Little-ISMS-Helper selbst betreiben — auf Shared-Hosting
(cPanel, Plesk, ISPConfig, generischer PHP-FPM-Host) oder auf einem eigenen Server
ohne Docker. Im Docker-Image startet `supervisord` den Scheduler-Worker selbst; zur
Mail-Queue dort siehe [DOCKER_PRODUCTION.md](../deployment/DOCKER_PRODUCTION.md#4-setup-wizard-aufrufen).

## Was der Worker erledigt

Ein Worker ist ein `messenger:consume`-Lauf. Er arbeitet drei Transporte ab:

| Transport | Inhalt | Ohne Worker |
|---|---|---|
| `scheduler_default` | Geplante Aufgaben aus `src/Schedule.php` (siehe [SYMFONY_SCHEDULER.md](../SYMFONY_SCHEDULER.md)): Fristen-Workflows, Erinnerungen (`app:send-reminders`), Digest-Mails, Snapshots, Backup + Aufräumen, `app:uploads:protect` | Nichts davon läuft |
| `scheduler` | Geplante Nachrichten, die die Anwendung selbst einreiht (Compliance-Berichte, geplante Aufgaben) | Diese Berichte entstehen nicht |
| `async` | Ausgehende E-Mails (Mailer-Nachrichten), bei `APP_ASYNC_JOB_RUNNER=messenger` auch die Admin-Jobs | Mails bleiben in der Tabelle `messenger_messages` liegen |

Warten E-Mails länger als 15 Minuten in der Warteschlange, zeigt die Anwendung Admins einen
roten Hinweis (Queue-Status, „Queue jetzt abarbeiten“).

Admin-Jobs (Backup, Export, Schema-Reconcile, Daten-Reparatur) laufen standardmäßig
**ohne** Worker: der Runner `in_request` gibt die Antwort an den Browser zurück und
arbeitet danach im selben PHP-FPM-Prozess weiter (`fastcgi_finish_request()`).
Nur bei `APP_ASYNC_JOB_RUNNER=messenger` übernimmt der Worker sie.

## Der Cron-Eintrag

Genau eine Zeile genügt. Sie startet jede Minute einen Worker für 55 s:

```cron
* * * * * cd /var/www/isms-helper && /usr/bin/php bin/console messenger:consume scheduler_default scheduler async --time-limit=55 --limit=20 --quiet
```

| Flag | Wert | Bedeutung |
|---|---|---|
| `--time-limit` | `55` | Worker beendet sich nach 55 s, vor dem nächsten Cron-Slot |
| `--limit` | `20` | Höchstens 20 Nachrichten pro Lauf |
| `--quiet` | — | Keine Ausgabe, damit der Cron-Daemon keine Mails verschickt |
| `--memory-limit` | `256M` | (optional) Worker stoppt bei Überschreitung |

Mit Log-Datei:

```cron
* * * * * cd /var/www/isms-helper && /usr/bin/php bin/console messenger:consume scheduler_default scheduler async --time-limit=55 --limit=20 --memory-limit=256M --quiet >> var/log/worker.log 2>&1
```

Verpasste Läufe holt der Scheduler einmal nach.

Wichtig:

- Pfad zu `php` an den Host anpassen (`which php`, z. B. `/usr/local/bin/php8.4`).
- Das Arbeitsverzeichnis ist die Installation (dort liegt `bin/console`).
- Der Cron läuft unter demselben User wie PHP-FPM und braucht Schreibrechte auf `var/`.
- `DEFAULT_URI` in `.env.local` muss die öffentliche URL sein
  (`DEFAULT_URI=https://isms.example.com`). Der Worker hat keinen Request; Links in
  Erinnerungs- und Digest-Mails werden mit diesem Wert gebaut.
- Alte Einzel-Cron-Zeilen (`app:process-timed-workflows`, `app:audit-log:cleanup`,
  `app:send-notifications`, `app:review:send-reminders` u. a.) entfernen. Die Commands
  laufen über den Scheduler oder existieren nicht mehr; eigene Zeilen dafür laufen
  doppelt oder schlagen fehl.

## Panel-spezifische Anleitung

### cPanel

1. cPanel → "Cron Jobs"
2. Common Settings: "Once Per Minute (\* \* \* \* \*)"
3. Command:
   ```
   cd /home/USERNAME/public_html && /usr/local/bin/php bin/console messenger:consume scheduler_default scheduler async --time-limit=55 --limit=20 --quiet
   ```
4. "Add New Cron Job"

### Plesk (Obsidian / 18.x)

1. Domain → "Scheduled Tasks" → "Add Task"
2. Task type: "Run a command"
3. Command:
   ```
   /usr/bin/php /var/www/vhosts/example.com/httpdocs/bin/console messenger:consume scheduler_default scheduler async --time-limit=55 --limit=20 --quiet
   ```
4. Run: "Cron style" → `* * * * *`
5. Notify: "Errors only"

### ISPConfig 3

1. Sites → Cron → Add new cron
2. Command:
   ```
   /usr/bin/php /var/www/clients/client1/web1/web/bin/console messenger:consume scheduler_default scheduler async --time-limit=55 --limit=20 --quiet
   ```
3. Schedule: Min `*`, Hour `*`, Day `*`, Month `*`, Weekday `*`
4. Type: "Chrooted" oder "Full" nach Site-Konfiguration

### DirectAdmin / generisch (crontab -e)

```cron
* * * * * cd /home/user/domains/example.com/public_html && /usr/bin/php bin/console messenger:consume scheduler_default scheduler async --time-limit=55 --limit=20 --quiet
```

### Eigener Server mit systemd

Statt Cron ein Dauer-Worker:

```ini
# /etc/systemd/system/isms-worker.service
[Unit]
Description=Little ISMS Helper Worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/isms-helper
ExecStart=/usr/bin/php bin/console messenger:consume scheduler_default scheduler async --time-limit=3600 --memory-limit=256M
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now isms-worker
```

## Prüfen, ob er läuft

### Worker-Status-Seite

Admin → "Queue-Status" (`/admin/queue-status`, Route `admin_queue_status`):

- Heartbeat-Ampel: grün (< 60 s), gelb (60–300 s), rot (> 300 s)
- Warteschlangen-Tiefe, letzte 20 Jobs
- Button "Queue jetzt verarbeiten" (nur Super-Admin: die Warteschlange enthält Jobs und Mails aller Organisationen)

Jeder Worker-Lauf setzt den Heartbeat, auch bei leerer Warteschlange. Nach jeder Aufgabe setzt der
Worker den Organisationskontext zurück; keine Aufgabe erbt die Organisation der vorigen.

### Log-Datei (bei `>> var/log/worker.log 2>&1`)

```bash
tail -f var/log/worker.log
```

### Datenbank

```sql
SELECT queue_name, COUNT(*) FROM messenger_messages GROUP BY queue_name;
```

Der Transport `async` schreibt mit dem Standard-DSN (`doctrine://default?auto_setup=0`)
in die Queue `default`. Steigt die Zahl dauerhaft, läuft kein Worker.

## Ohne Cron

- **"Queue jetzt verarbeiten"** auf `/admin/queue-status` startet einen kurzen Lauf
  (`--time-limit=25 --limit=5`) im Request. Er arbeitet nur `async` ab — geplante
  Aufgaben laufen damit nicht. Nur für den Super-Admin.
- Ohne Cron und ohne Dauer-Worker laufen keine Erinnerungen, keine Fristen-Workflows
  und keine nächtlichen Backups. Einen Hoster mit Cron-Zugang wählen.

## Tuning

| Symptom | Anpassung |
|---|---|
| "Allowed memory size exhausted" | `--memory-limit=512M`; PHP `memory_limit` anheben |
| Warteschlange läuft voll | `--limit=50` oder eine zweite, gleiche Cron-Zeile |
| Worker belastet die CPU | `--sleep=2` |

Mehrere Worker parallel sind möglich: der Doctrine-Transport sperrt Zeilen, eine
Nachricht wird nicht doppelt verarbeitet. Nicht mehr Worker als der Host an
PHP-CLI-Prozessen erlaubt (meist 5–10).

## Sicherheit

- Der Cron läuft als Hosting-User, nicht als root.
- Bei Logging die Rechte auf `var/log/worker.log` beschränken (`chmod 640`).
- Job-Datensätze (`var/jobs/<uuid>.json`) enthalten Payloads; `var/` liegt außerhalb
  des Web-Roots.
- **Mandanten-Scope im Job (fail closed):** Jede Job-Nachricht trägt die Organisation
  des Auftraggebers. Ohne Organisation läuft ein Job nur dann
  organisationsübergreifend, wenn die Nachricht das ausdrücklich erlaubt
  (Instanz-Operator `ROLE_SUPER_ADMIN`). Sonst sieht der Job nur Datensätze ohne
  Organisation.

## Troubleshooting

| Problem | Diagnose | Behebung |
|---|---|---|
| Ampel rot, Jobs bleiben "pending" | Cron läuft nicht oder PHP-Pfad falsch | Cron-Log prüfen, `which php`, absolute Pfade |
| Worker bricht sofort ab | DB-Verbindung, `.env.local` fehlt | `php bin/console dbal:run-sql 'SELECT 1'` |
| Mails kommen nicht an | `async` wird nicht abgearbeitet oder `MAILER_DSN` falsch | Cron-Zeile mit `async` prüfen, `MAILER_DSN` in `.env.local` |
| Links in Mails zeigen auf `localhost` | `DEFAULT_URI` nicht gesetzt | `DEFAULT_URI=https://…` in `.env.local`, `cache:clear` |
| Tabelle `messenger_messages` fehlt | Migrationen nicht angewendet | `php bin/console doctrine:migrations:migrate` oder `/quick-fix` |

## Für die Anfrage beim Hoster

- Cron-Job mit Frequenz `* * * * *`
- PHP-CLI-Zugriff
- Schreibrechte auf `var/`
- Verbindung von PHP-CLI zur Datenbank (gleiche Zugangsdaten wie Web)

## Querverweise

- [SYMFONY_SCHEDULER.md](../SYMFONY_SCHEDULER.md) — Liste der geplanten Aufgaben
- [QUICK_FIX.md](QUICK_FIX.md) — Migrationen und Reparaturen ohne CLI
- [CLAUDE.md](../../CLAUDE.md) §"Async Admin Jobs" — Entwickler-Perspektive
