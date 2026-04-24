# Little ISMS Helper - Production Docker Deployment

**Self-Contained Single-Container Setup** - All-in-One mit embedded MariaDB

## 🎯 Vorteile

- ✅ **Wirklich Standalone** - Nur 1 Container (App + embedded MariaDB)
- ✅ **Minimal** - Nur 1 Volume für alle Daten
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

### 2. Container starten

```bash
docker-compose -f docker-compose.prod.yml up -d
```

### 3. Logs prüfen

```bash
docker-compose -f docker-compose.prod.yml logs -f
```

### 4. Setup Wizard aufrufen

Öffne: **http://localhost/setup** (oder dein konfigurierter Port)

Der Wizard führt dich durch:
1. Datenbank wird automatisch konfiguriert (embedded MariaDB)
2. Admin-User erstellen
3. ISO 27001 Controls laden
4. System-Verifizierung

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
├── backups/         # Backup-Dateien
└── mysql_password.txt  # Auto-generiertes DB-Passwort (falls nicht gesetzt)
```

### Backup erstellen

```bash
# Methode 1: Über Web-UI
# Admin Portal → Database → Create Backup

# Methode 2: Via Console
docker-compose -f docker-compose.prod.yml exec app php bin/console app:backup:create

# Methode 3: Volume-Backup
docker run --rm -v isms_data:/data -v $(pwd):/backup alpine tar czf /backup/isms_backup_$(date +%Y%m%d).tar.gz /data
```

### Backup wiederherstellen

```bash
# Methode 1: Über Web-UI
# Admin Portal → Database → Restore Backup

# Methode 2: Via Console
docker-compose -f docker-compose.prod.yml exec app php bin/console app:backup:restore /var/www/html/var/backups/backup_YYYY-MM-DD.json.gz

# Methode 3: Volume-Restore
docker run --rm -v isms_data:/data -v $(pwd):/backup alpine tar xzf /backup/isms_backup_YYYYMMDD.tar.gz -C /
```

## 🌐 Reverse Proxy Setup

### nginx

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
        proxy_pass http://localhost:80;
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

## 🔄 Updates

```bash
# 1. Neues Image pullen
docker-compose -f docker-compose.prod.yml pull

# 2. Container neu starten
docker-compose -f docker-compose.prod.yml up -d

# Daten bleiben erhalten (isms_data Volume)
```

## 🛠️ Verwaltung

### Container-Status

```bash
docker-compose -f docker-compose.prod.yml ps
```

### Logs anzeigen

```bash
# Alle Logs
docker-compose -f docker-compose.prod.yml logs -f

# Letzte 100 Zeilen
docker-compose -f docker-compose.prod.yml logs --tail=100
```

### Container neu starten

```bash
docker-compose -f docker-compose.prod.yml restart
```

### Container stoppen

```bash
docker-compose -f docker-compose.prod.yml stop
```

### Container entfernen (Daten bleiben!)

```bash
docker-compose -f docker-compose.prod.yml down
```

### Alles entfernen (inkl. Daten!)

```bash
docker-compose -f docker-compose.prod.yml down -v
```

### Shell im Container

```bash
docker-compose -f docker-compose.prod.yml exec app bash
```

### Cache leeren

```bash
docker-compose -f docker-compose.prod.yml exec app php bin/console cache:clear
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
- [ ] Automatische Backups eingerichtet
- [ ] Updates aktiviert (`docker-compose pull` regelmäßig)
- [ ] Logs überwachen
- [ ] Health Checks aktiv

### Passwörter auslesen

```bash
# Auto-generiertes MySQL-Passwort
docker-compose -f docker-compose.prod.yml exec app cat /var/www/html/var/mysql_password.txt

# Admin-User im Setup Wizard erstellt
# Login über Web-UI
```

## 🐛 Troubleshooting

### Container startet nicht

```bash
# Logs prüfen
docker-compose -f docker-compose.prod.yml logs

# Port-Konflikte prüfen
netstat -tulpn | grep -E '80|443'
```

### Datenbank-Fehler

```bash
# Container neu starten
docker-compose -f docker-compose.prod.yml restart

# MariaDB-Status prüfen
docker-compose -f docker-compose.prod.yml exec app supervisorctl status mariadb
```

### Langsame Performance

```bash
# Resource-Nutzung prüfen
docker stats isms-app-prod

# Cache leeren
docker-compose -f docker-compose.prod.yml exec app php bin/console cache:clear --env=prod
```

### Permission-Fehler

```bash
# Ownership reparieren
docker-compose -f docker-compose.prod.yml exec app chown -R www-data:www-data /var/www/html/var
```

## 📞 Support

- **Dokumentation:** [README.md](../../README.md)
- **Issues:** https://github.com/moag1000/Little-ISMS-Helper/issues
- **Discussions:** https://github.com/moag1000/Little-ISMS-Helper/discussions

## 📜 Lizenz

AGPL-3.0 - See [LICENSE](../../LICENSE)
