# Worker auf Shared-Hosting (ohne systemd / Docker)

Stand: 2026-05-19 · Zielgruppe: Admins, die Little-ISMS-Helper auf einem
klassischen Webhosting-Paket (cPanel, Plesk, ISPConfig, generischer PHP-FPM-
Host) betreiben — also ohne Root-Zugriff, ohne systemd, ohne Docker.

## Warum braucht der Helper einen "Worker"?

Viele Admin-Operationen laufen mittlerweile **asynchron** (Async-Admin-Jobs
Phase 1–3): Backup erzeugen, Schema-Reconcile, Daten-Reparatur, CSV-Exports
großer Mandanten, DORA-RoI-Export usw. Der Grund:

- PHP-FPM bricht Requests nach **30 Sekunden** ab.
- Ein Backup, eine schema:reconcile-Sitzung, ein vollständiger DSGVO-Export
  kann je nach Daten-Volumen Minuten dauern.
- Synchrone Ausführung würde mitten in der Arbeit abreißen, halbe Backups
  hinterlassen oder Daten korrumpieren.

Die Lösung: Der Controller legt einen **Job-Datensatz** an, schickt eine
Symfony-Messenger-Message in die `async`-Warteschlange (Doctrine-Tabelle
`messenger_messages`) und antwortet sofort mit einer Progress-Seite.
Die eigentliche Arbeit erledigt ein **Worker-Prozess**, der die Warteschlange
abarbeitet.

Auf "großen" Servern läuft dieser Worker als Daemon (`messenger:consume`
mit `systemd`-Unit oder `supervisord`). Auf Shared-Hosting ist das nicht
möglich — dort ersetzen wir den Daemon durch einen **Cron-Job, der den
Worker im Minutentakt für 55 s startet**.

## Der Cron-Eintrag (Standard-Fall)

Genau eine Zeile in den Cron-Einträgen des Hosting-Panels:

```cron
* * * * * cd /var/www/isms-helper && /usr/bin/php bin/console messenger:consume async --time-limit=55 --limit=20 --quiet
```

Was die Flags bedeuten:

| Flag | Wert | Bedeutung |
|---|---|---|
| `--time-limit` | `55` | Worker beendet sich nach 55 s — sicher unter dem 60-s-Cron-Slot |
| `--limit` | `20` | Höchstens 20 Messages pro Lauf — verhindert „endloses Schlucken" |
| `--quiet` | — | Keine STDOUT-Ausgaben, damit der Cron-Daemon keine Mail-Flut erzeugt |
| `--memory-limit` | `256M` | (optional) Worker stoppt, wenn PHP-Memory das Limit reißt |

Komplett-Beispiel mit Memory-Limit und Log-File:

```cron
* * * * * cd /var/www/isms-helper && /usr/bin/php bin/console messenger:consume async --time-limit=55 --limit=20 --memory-limit=256M --quiet >> var/log/worker.log 2>&1
```

Wichtig:

- Pfad zu `php` an Host anpassen (`which php` oder `/usr/local/bin/php8.4`)
- Arbeitsverzeichnis (`cd …`) muss die Helper-Installation sein (Pfad zur
  `bin/console`)
- Der Hosting-Account, unter dem der Cron läuft, muss Schreibrechte auf
  `var/jobs/` und `var/log/` besitzen — gleicher User wie PHP-FPM

## Panel-spezifische Anleitung

### cPanel

1. Im cPanel → "Cron Jobs"
2. Common Settings: "Once Per Minute (\* \* \* \* \*)"
3. Command-Feld:
   ```
   cd /home/USERNAME/public_html && /usr/local/bin/php bin/console messenger:consume async --time-limit=55 --limit=20 --quiet
   ```
4. "Add New Cron Job"
5. Optional: E-Mail-Adresse für Fehler-Benachrichtigungen leer lassen,
   da `--quiet` aktiv ist

### Plesk (Obsidian / 18.x)

1. Domain → "Scheduled Tasks"
2. "Add Task"
3. Task type: "Run a command"
4. Command:
   ```
   /usr/bin/php /var/www/vhosts/example.com/httpdocs/bin/console messenger:consume async --time-limit=55 --limit=20 --quiet
   ```
5. Run: "Cron style" → `* * * * *`
6. Notify: "Errors only"

### ISPConfig 3

1. Sites → Cron
2. Add new cron
3. Cron command:
   ```
   /usr/bin/php /var/www/clients/client1/web1/web/bin/console messenger:consume async --time-limit=55 --limit=20 --quiet
   ```
4. Schedule: Min `*`, Hour `*`, Day `*`, Month `*`, Weekday `*`
5. Type: "Chrooted" oder "Full" nach Site-Konfiguration

### DirectAdmin / Generisch (crontab -e)

```cron
* * * * * cd /home/user/domains/example.com/public_html && /usr/bin/php bin/console messenger:consume async --time-limit=55 --limit=20 --quiet
```

## Verifizieren, dass es läuft

Drei Wege:

### 1. Worker-Health-UI (empfohlen)

Login als Admin → Admin-Panel → "Queue-Status" (oder direkt
`/admin/queue-status`). Diese Seite zeigt:

- **Heartbeat-Ampel**: GRÜN (<60 s), GELB (60–300 s), ROT (>300 s)
- Aktuelle Queue-Tiefe
- Letzte 20 verarbeiteten Jobs
- Manueller "Jetzt verarbeiten"-Button (Fallback ohne Cron)

Wenn die Ampel **GRÜN** ist, läuft der Cron korrekt.

### 2. Log-File (bei `>> var/log/worker.log 2>&1` im Cron)

```
tail -f var/log/worker.log
```

Erwartung: alle Minute eine kurze Notiz; im Job-Fall mehr Output.

### 3. Datenbank direkt

```sql
SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async';
```

Wenn der Wert dauerhaft steigt und nie zurück auf 0 geht, läuft der Worker
NICHT. Wenn er minütlich auf 0 fällt: alles okay.

## Was tun, wenn Cron nicht zur Verfügung steht?

Manche Hoster bieten keine 1-Minuten-Frequenz oder gar keinen Cron-Zugang.
Drei Auswege, in absteigender Empfehlung:

### a) "Jetzt verarbeiten"-Button

Im Worker-Health-UI gibt es einen Button **„Queue jetzt verarbeiten"**.
Dieser startet einen begrenzten `messenger:consume`-Lauf
(`--time-limit=25 --limit=5`) direkt im PHP-FPM-Request — bleibt sicher
unter dem 30-s-Limit und drainiert 5 Messages pro Klick. Für manuelle
Bedienung kleinerer Queues ausreichend.

### b) Web-Cron / Ping-Service

Externe Services (eigene Wahl) können regelmäßig eine URL aufrufen, die
einen Worker-Lauf triggert. Dazu eine eigene Route mit Token-Auth
einrichten — siehe `docs/operations/EXTERNAL_PING.md` (falls vorhanden)
oder via `scheduled-tasks`-Admin-UI konfigurieren.

### c) Symfony Scheduler (für reichhaltigere Planung)

Wer Komfort über reine Minuten-Cron hinaus möchte (Backoff, Conditional
Schedules, gemischte Tasks), kann den Symfony-Scheduler-Component
(`symfony/scheduler`) als zweite Schicht über Messenger nutzen. Dafür
muss ein dauerhafter Worker laufen, was auf Shared-Hosting wieder
voraussetzungsvoll ist — meist nicht praktikabel. Doku:
<https://symfony.com/doc/current/scheduler.html>.

## Speicher- und Zeit-Tuning

Defaults sind für die meisten Shared-Hoster sicher gewählt. Anpassen,
wenn:

| Symptom | Anpassung |
|---|---|
| Worker bricht mit „Allowed memory size exhausted" ab | `--memory-limit=512M` oder höher; PHP `memory_limit` in `php.ini` anheben |
| Große Backups timed out trotz Async | `--time-limit=300` (5 min) — braucht **separaten** Cron-Eintrag, der NICHT minütlich läuft, sonst stapeln sich Worker |
| Queue läuft voll, weil Last hoch | `--limit=50`, oder zweiten Cron-Slot (z. B. alle 30 s mit 2 separaten Crons à `*/1` versetzt 30 s) |
| Worker frisst CPU | `--sleep=2` (2 s zwischen Messages) — auf Shared-Hosting selten nötig |

## Mehrere Worker parallel (Advanced)

Wer mehrere Jobs gleichzeitig abarbeiten will (z. B. ein Backup blockiert
nicht den DSGVO-Export), kann mehrere Cron-Einträge parallel laufen
lassen — Doctrine-Transport unterstützt **Row-Locking**, sodass dieselbe
Message nicht doppelt verarbeitet wird:

```cron
* * * * * cd /var/www/isms-helper && /usr/bin/php bin/console messenger:consume async --time-limit=55 --limit=20 --quiet
* * * * * cd /var/www/isms-helper && /usr/bin/php bin/console messenger:consume async --time-limit=55 --limit=20 --quiet
```

Faustregel: nicht mehr Worker als der Host an PHP-CLI-Prozessen erlaubt
(meist 5–10 auf Shared-Hosting).

## Sicherheit

- Der Cron-Job läuft als der Hosting-User, NICHT als root — keine
  zusätzlichen Rechte nötig
- Worker-Output bei `--quiet` kann nichts leaken; nur bei aktivem
  Logging Datei-Permissions auf `var/log/worker.log` beschränken
  (`chmod 640`)
- Im Job-Datensatz (`var/jobs/<uuid>.json`) stehen Payloads — die
  Datei-Permissions auf `var/jobs/` sind bereits restriktiv
  (`0775` für Schreiben durch FPM + Cron)

## Troubleshooting

| Problem | Diagnose | Behebung |
|---|---|---|
| Queue-UI zeigt ROT, Jobs bleiben „pending" | Cron läuft nicht oder PHP-Pfad falsch | Cron-Log prüfen (`/var/log/cron`), `which php` testen, Pfade absolut setzen |
| Worker startet, bricht aber sofort ab | Database-Connect-Fehler, `.env.local` fehlt | `php bin/console doctrine:query:sql 'SELECT 1'` lokal testen |
| Memory-Exhausted | Großer Daten-Repair-Job | `--memory-limit=512M` oder Job in kleineren Batches re-dispatchen |
| Doppel-Worker frisst dieselben Messages | Doctrine-Transport-Lock greift, kein Problem | Nur Log-Rauschen — kein Daten-Risiko |
| `messenger_messages`-Tabelle existiert nicht | Doctrine-Migration noch nicht angewendet | `php bin/console messenger:setup-transports` oder `doctrine:migrations:migrate` |

## Stichworte für die Hosting-Support-Anfrage

Falls der Hoster fragt, was du brauchst:

- "Cron-Job mit Frequenz `* * * * *` (1× pro Minute)"
- "PHP-CLI-Zugriff (Console-Aufruf von `php`)"
- "Schreibrechte auf das Verzeichnis `var/` im Web-Root"
- "Verbindung von CLI-PHP zur Datenbank (gleiche Credentials wie Web)"

Keine speziellen Module nötig — die Lösung nutzt **nur Symfony-Bordmittel**
(Messenger + Console + Doctrine-Transport).

## Querverweise

- [`docs/user-guide/QUICK_FIX.md`](QUICK_FIX.md) — UI für destruktive
  Operationen ohne CLI
- [`CLAUDE.md`](../../CLAUDE.md) §"Async Admin Jobs" — Entwickler-
  Perspektive
- Worker-Health-UI: `/admin/queue-status` (Route-Name
  `admin_queue_status`)
