# Little ISMS Helper - Production Docker Deployment

**Self-Contained Single-Container Setup** - All-in-One mit embedded MariaDB

## 🎯 Vorteile

- ✅ **Wirklich Standalone** - Nur 1 Container (App + embedded MariaDB)
- ✅ **Minimal** - 1 Volume für `var/` (DB, Nachweisdateien, Backups), dazu `public/uploads` für Logos
- ✅ **Einfach** - Keine externe Datenbank nötig
- ✅ **Performant** - Optimiert für Production
- ✅ **Sicher** - Production-Mode mit konfigurierbaren Credentials

## 🚀 Quick Start

### 1. Environment-Datei erstellen

```bash
cat > .env.docker.local << EOF
# Database Credentials (leer lassen für auto-generiert)
MYSQL_DATABASE=isms
MYSQL_USER=isms
MYSQL_PASSWORD=

# App Ports (optional - Standard: 80/443)
APP_PORT=80
APP_PORT_HTTPS=443

# Email Configuration (optional)
MAILER_DSN=smtp://user:pass@smtp.example.com:587
EOF
```

`docker compose` liest diese Datei nur mit `--env-file`. `docker-compose.prod.yml` reicht
`MYSQL_*` und `MAILER_DSN` an den Container weiter. `DEFAULT_URI` (öffentliche URL, Pflicht
für Links in Erinnerungsmails) und ggf. `SYMFONY_TRUSTED_PROXIES` in einer
`docker-compose.override.yml` ergänzen:

```yaml
services:
  app:
    environment:
      DEFAULT_URI: https://isms.example.com
    volumes:
      - isms_uploads:/var/www/html/public/uploads   # Logos, Profilbilder
volumes:
  isms_uploads:
```

### 2. Container starten

```bash
docker compose --env-file .env.docker.local -f docker-compose.prod.yml -f docker-compose.override.yml up -d --build
```

### 3. Logs prüfen

```bash
docker compose -f docker-compose.prod.yml logs -f
```

### 4. Setup Wizard aufrufen

Öffne: **http://localhost/setup** (oder dein konfigurierter Port)

Der Wizard führt durch Datenbank (embedded MariaDB, automatisch), optionalen
Backup-Restore, Admin-User, Organisation, Module und Frameworks
([QUICKSTART.md](../QUICKSTART.md#5-setup-wizard-durchlaufen)).

Geplante Aufgaben (Erinnerungen, Backups) laufen über den Scheduler-Worker, den
`supervisord` im Container startet (`messenger-scheduler`). Er arbeitet derzeit nur die
Transporte `scheduler_default` und `scheduler` ab, nicht `async` — dort liegen ausgehende Mails.
Bis das Image das mitbringt, die `async`-Queue zusätzlich abarbeiten, z. B. per Host-Cron:

```cron
* * * * * docker compose -f /pfad/docker-compose.prod.yml exec -T -u www-data app php bin/console messenger:consume async --time-limit=55 --limit=20 --quiet
```

## 🔧 Konfiguration

### Umgebungsvariablen

| Variable | Standard | Beschreibung |
|----------|----------|--------------|
| `APP_PORT` | `80` | HTTP Port |
| `APP_PORT_HTTPS` | `443` | HTTPS Port |
| `MYSQL_DATABASE` | `isms` | Datenbankname |
| `MYSQL_USER` | `isms` | DB-Benutzer |
| `MYSQL_PASSWORD` | (auto) | DB-Passwort (leer = auto-generiert) |
| `MAILER_DSN` | (leer) | SMTP-Konfiguration |
| `DEFAULT_URI` | `http://localhost` | Öffentliche Basis-URL, z. B. `https://isms.example.com` — alle Links in E-Mails, Lieferantenfragebögen, Trust Center und QR-Labels werden damit gebaut, nie aus dem Host-Header. Ohne Wert zeigt Überwachung > Health eine Warnung mit Knopf „Adresse übernehmen“ |
| `SYMFONY_TRUSTED_HOSTS` | (leer) | Erlaubte Hostnamen als verankerte Muster, z. B. `'^isms\.example\.com$,^localhost$'` (localhost für den Container-Healthcheck); jeder andere Host-Header bekommt 400 |
| `APP_SECRET` | generiert | Wird beim ersten Start erzeugt und in `var/app_secret` gespeichert; ein gesetzter Wert hat Vorrang |
| `SYMFONY_TRUSTED_PROXIES` | private Netze | Siehe [Vertrauenswürdige Proxys](#vertrauenswürdige-proxys-client-ip) |
| `EMBEDDED_DB` | `mariadb` | `none` = keine eingebettete MariaDB, externe DB über `DATABASE_URL` |

### Automatisches DB-Passwort

Wenn `MYSQL_PASSWORD` leer gelassen wird:
- Container generiert automatisch ein sicheres Passwort
- Passwort wird in `/var/www/html/var/mysql_password.txt` gespeichert
- Keine manuelle Konfiguration nötig

### Eigenes DB-Passwort

```bash
# In .env.docker.local:
MYSQL_PASSWORD=MeinSicheresPasswort123!
```

## 💾 Daten & Backup

### Volume-Struktur

Das `isms_data` Volume enthält:
```
/var/www/html/var/
├── mysql/           # Embedded MariaDB Daten
├── cache/           # Symfony Cache
├── log/             # Application Logs
├── sessions/        # User Sessions
├── uploads/         # Nachweisdateien (Dokumente, Versionen, Schulungsmaterial)
├── backups/         # Backup-Dateien (nächtlich 02:00, 30 Tage)
├── app_secret       # Persistiertes APP_SECRET
└── mysql_password.txt  # Auto-generiertes DB-Passwort (falls nicht gesetzt)
```

Logos und Profilbilder liegen in `public/uploads/` — ohne eigenes Volume gehen sie beim
Neuerstellen des Containers verloren (siehe Override oben).

### Backup erstellen

```bash
# Automatisch: jede Nacht 02:00 nach var/backups/ (Scheduler) — regelmäßig vom Host wegkopieren

# Methode 1: Über Web-UI
# Admin → Datenverwaltung → Backup (/admin/data/backup)

# Methode 2: Via Console
docker compose -f docker-compose.prod.yml exec app php bin/console app:backup:create

# Methode 3: Volume-Backup (Container vorher stoppen, sonst ist die MariaDB-Kopie inkonsistent)
docker compose -f docker-compose.prod.yml stop
docker run --rm -v isms_data:/data -v $(pwd):/backup alpine tar czf /backup/isms_backup_$(date +%Y%m%d).tar.gz /data
docker compose -f docker-compose.prod.yml start
```

### Backup wiederherstellen

```bash
# Methode 1: Über Web-UI
# Admin → Datenverwaltung → Backup → Wiederherstellen

# Methode 2: Via Console
docker compose -f docker-compose.prod.yml exec app php bin/console app:backup:restore /var/www/html/var/backups/backup_YYYY-MM-DD_HH-ii-ss.zip --dry-run

# Methode 3: Volume-Restore
docker run --rm -v isms_data:/data -v $(pwd):/backup alpine tar xzf /backup/isms_backup_YYYYMMDD.tar.gz -C /
```

## 🌐 Reverse Proxy Setup

### nginx

Läuft nginx auf demselben Host, den Container auf einen anderen Port legen
(`APP_PORT=8080`, `APP_PORT_HTTPS` freigeben oder entfernen).

```nginx
server {
    listen 80;
    server_name isms.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name isms.example.com;

    ssl_certificate /etc/letsencrypt/live/isms.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/isms.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # WebSocket Support
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

### Traefik (docker-compose labels)

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.isms.rule=Host(`isms.example.com`)"
  - "traefik.http.routers.isms.entrypoints=websecure"
  - "traefik.http.routers.isms.tls.certresolver=letsencrypt"
  - "traefik.http.services.isms.loadbalancer.server.port=80"
```

### Vertrauenswürdige Proxys (Client-IP)

Die App liest `X-Forwarded-For` / `X-Forwarded-Proto` / `X-Forwarded-Host` nur von
Proxys aus Loopback- und privaten Netzen (`127.0.0.1`, `::1`, `10.0.0.0/8`,
`172.16.0.0/12`, `192.168.0.0/16`, `fc00::/7`). Das deckt nginx/Traefik auf dem
gleichen Host oder im Docker-Netz und Plesk ab. Steht ein öffentlicher Proxy
davor (CDN, Load Balancer mit öffentlicher IP), seine Netze setzen:

```bash
SYMFONY_TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8,203.0.113.0/24
```

## 🔄 Updates

`docker-compose.prod.yml` baut das Image aus dem Quellcode:

```bash
git pull
docker compose --env-file .env.docker.local -f docker-compose.prod.yml -f docker-compose.override.yml up -d --build
```

Mit dem fertigen Image (`moag2000/little-isms-helper:<version>`, siehe
[DOCKER_HUB.md](../setup/DOCKER_HUB.md)) stattdessen den Tag erhöhen und
`docker compose pull && docker compose up -d`. Daten bleiben im Volume. Danach
ausstehende Migrationen unter `/quick-fix` anwenden
([QUICK_FIX.md](../user-guide/QUICK_FIX.md)).

## 🛠️ Verwaltung

### Container-Status

```bash
docker compose -f docker-compose.prod.yml ps
```

### Logs anzeigen

```bash
# Alle Logs
docker compose -f docker-compose.prod.yml logs -f

# Letzte 100 Zeilen
docker compose -f docker-compose.prod.yml logs --tail=100
```

### Container neu starten

```bash
docker compose -f docker-compose.prod.yml restart
```

### Container stoppen

```bash
docker compose -f docker-compose.prod.yml stop
```

### Container entfernen (Daten bleiben!)

```bash
docker compose -f docker-compose.prod.yml down
```

### Alles entfernen (inkl. Daten!)

```bash
docker compose -f docker-compose.prod.yml down -v
```

### Shell im Container

```bash
docker compose -f docker-compose.prod.yml exec app bash
```

### Cache leeren

```bash
docker compose -f docker-compose.prod.yml exec app php bin/console cache:clear
```

## 📊 Ressourcen

### Standard-Limits (docker-compose.prod.yml)

- **CPU:** 1-4 Cores
- **Memory:** 1-4 GB
- **Disk:** Abhängig von Datenmenge (empfohlen: min. 10GB)

### Anpassen

Bearbeite `docker-compose.prod.yml`:

```yaml
deploy:
  resources:
    limits:
      cpus: '2'      # Max CPU
      memory: 2G     # Max Memory
    reservations:
      cpus: '0.5'    # Garantierte CPU
      memory: 512M   # Garantiertes Memory
```

## 🔒 Sicherheit

### Checkliste

- [ ] `MYSQL_PASSWORD` gesetzt oder auto-generiert geprüft
- [ ] HTTPS mit Reverse Proxy konfiguriert (Let's Encrypt)
- [ ] Firewall: Nur Port 80/443 exponiert
- [ ] `DEFAULT_URI` auf die öffentliche URL gesetzt
- [ ] Backups aus `var/backups/` regelmäßig vom Host wegkopiert
- [ ] Updates regelmäßig eingespielt
- [ ] Logs überwachen
- [ ] Health Checks aktiv

### Passwörter auslesen

```bash
# Auto-generiertes MySQL-Passwort
docker compose -f docker-compose.prod.yml exec app cat /var/www/html/var/mysql_password.txt

# Admin-User im Setup Wizard erstellt
# Login über Web-UI
```

## 🐛 Troubleshooting

### Container startet nicht

```bash
# Logs prüfen
docker compose -f docker-compose.prod.yml logs

# Port-Konflikte prüfen
netstat -tulpn | grep -E '80|443'
```

### Datenbank-Fehler

```bash
# Container neu starten
docker compose -f docker-compose.prod.yml restart

# MariaDB-Status prüfen
docker compose -f docker-compose.prod.yml exec app supervisorctl status mariadb
```

### Langsame Performance

```bash
# Resource-Nutzung prüfen
docker stats isms-app-prod

# Cache leeren
docker compose -f docker-compose.prod.yml exec app php bin/console cache:clear --env=prod
```

### Permission-Fehler

```bash
# Ownership reparieren
docker compose -f docker-compose.prod.yml exec app chown -R www-data:www-data /var/www/html/var
```

## 📞 Support

- **Dokumentation:** [README.md](../../README.md)
- **Issues:** https://github.com/moag1000/Little-ISMS-Helper/issues
- **Discussions:** https://github.com/moag1000/Little-ISMS-Helper/discussions

## 📜 Lizenz

AGPL-3.0 - See [LICENSE](../../LICENSE)
