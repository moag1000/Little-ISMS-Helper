# Docker Security Best Practices - Little ISMS Helper

## 🔒 Security Überblick

Dieses Dokument beschreibt Security Best Practices für Docker-Deployments von Little ISMS Helper.

## ✅ Datenpersistenz nach Reboot

### Was wird persistiert?

**✅ Vollständig persistent:**
- **Datenbank-Daten** (`db_data` Volume) - PostgreSQL-Datenbank
- **.env.local** (Bind-Mount `./:/var/www/html`) - Wizard-Konfiguration
- **config/setup_complete.lock** (Bind-Mount) - Setup-Status
- **var/log/** (Bind-Mount) - Application Logs
- **pgAdmin-Einstellungen** (`pgadmin_data` Volume)

**🔄 Nach Reboot verfügbar:**
Nach `docker-compose down` und System-Reboot:
```bash
# System neu starten
sudo reboot

# Nach Reboot - alle Daten sind noch da!
docker-compose up -d
```

Ihre Konfiguration (`.env.local`), Datenbank und Setup-Status bleiben erhalten!

### Volume-Persistenz testen

```bash
# 1. Setup durchführen
docker-compose up -d
# Browser: http://localhost:8000/setup -> Wizard durchlaufen

# 2. Container stoppen
docker-compose down

# 3. Prüfen ob .env.local existiert
cat .env.local
# ✅ Sollte APP_SECRET und DATABASE_URL enthalten

# 4. Container neu starten
docker-compose up -d

# 5. Prüfen
# Browser: http://localhost:8000 -> Sollte direkt zur Login-Seite gehen (Setup abgeschlossen)
```

## 🛡️ Security-Probleme & Lösungen

### 1. Hardcoded Credentials (KRITISCH)

**❌ Problem:** Passwörter sind in docker-compose.yml hardcoded
```yaml
POSTGRES_PASSWORD: isms_password  # ❌ Unsicher!
```

**✅ Lösung:** Verwenden Sie `.env.docker.local`:

```bash
# 1. Erstellen Sie .env.docker.local
cp .env.docker .env.docker.local

# 2. Ändern Sie die Passwörter
nano .env.docker.local
```

```env
# .env.docker.local (NICHT in Git committen!)
POSTGRES_PASSWORD=$(openssl rand -base64 32)
PGADMIN_DEFAULT_PASSWORD=$(openssl rand -base64 20)
```

```bash
# 3. Starten Sie mit .env.docker.local
docker-compose --env-file .env.docker.local up -d
```

### 2. Offene Ports (MITTEL)

**❌ Problem:** Alle Ports sind öffentlich erreichbar

**Development (OK):**
- 8000 (App)
- 5432 (PostgreSQL) - ⚠️ Sollte nur lokal sein
- 8025 (MailHog)
- 5050 (pgAdmin) - ⚠️ Sollte nur lokal sein

**✅ Lösung für Produktion:**

```yaml
# docker-compose.prod.yml
db:
  expose:
    - "5432"  # Nur im Docker-Netzwerk, NICHT öffentlich!
  # KEIN ports: Mapping!
```

Oder für Development nur localhost:
```bash
# .env.docker.local
DB_PORT=127.0.0.1:5432:5432    # Nur von localhost erreichbar
PGADMIN_PORT=127.0.0.1:5050:80  # Nur von localhost erreichbar
```

### 3. Debug Mode (KRITISCH für Produktion)

**❌ Problem:** `APP_DEBUG=1` zeigt Stack Traces

**✅ Lösung:**

```bash
# .env.docker.local (Produktion)
APP_ENV=prod
APP_DEBUG=0
```

### 4. Resource Limits (MITTEL)

**❌ Problem:** Container können unlimitiert Resources nutzen

**✅ Lösung:** In docker-compose.yml bereits implementiert:

```yaml
deploy:
  resources:
    limits:
      cpus: '2'
      memory: 2G
    reservations:
      cpus: '0.5'
      memory: 512M
```

### 5. Root User in Containern (MITTEL)

**❌ Problem:** Container laufen als root

**✅ Lösung:** Im Dockerfile bereits implementiert:
```dockerfile
# Dockerfile
USER www-data
```

## 🔐 Produkt​ions-Deployment Checklist

### Vor dem Go-Live:

- [ ] **Passwörter ändern** in `.env.docker.local`
  ```bash
  POSTGRES_PASSWORD=$(openssl rand -base64 32)
  ```

- [ ] **HTTPS aktivieren** (Reverse Proxy)
  ```bash
  # nginx.conf
  server {
    listen 443 ssl;
    ssl_certificate /etc/ssl/cert.pem;
    ssl_certificate_key /etc/ssl/key.pem;

    location / {
      proxy_pass http://localhost:8000;
    }
  }
  ```

- [ ] **Debug Mode deaktivieren**
  ```env
  APP_ENV=prod
  APP_DEBUG=0
  ```

- [ ] **pgAdmin entfernen**
  ```bash
  docker-compose -f docker-compose.prod.yml up -d
  # pgAdmin ist nicht in prod enthalten
  ```

- [ ] **MailHog entfernen** und echten SMTP konfigurieren
  ```env
  MAILER_DSN=smtp://smtp.example.com:587?encryption=tls&auth_mode=login&username=...&password=...
  ```

- [ ] **Ports einschränken**
  - Nur Port 80/443 öffentlich (via Reverse Proxy)
  - Datenbank-Port NICHT öffentlich

- [ ] **Firewall konfigurieren**
  ```bash
  # UFW (Ubuntu)
  sudo ufw allow 80/tcp
  sudo ufw allow 443/tcp
  sudo ufw deny 5432/tcp  # PostgreSQL
  sudo ufw enable
  ```

- [ ] **Backups einrichten**
  ```bash
  # Siehe DOCKER_SETUP.md -> Backup Strategy
  docker-compose exec -T db pg_dump -U isms_user little_isms > backup.sql
  ```

- [ ] **Docker Security Scanning**
  ```bash
  docker scan isms-app:latest
  ```

- [ ] **Log Rotation** konfigurieren
  ```yaml
  # docker-compose.prod.yml
  logging:
    driver: "json-file"
    options:
      max-size: "10m"
      max-file: "3"
  ```

- [ ] **Health Monitoring** einrichten
  - Verwenden Sie die eingebauten Health Checks
  - Monitoring-Tool (z.B. Prometheus, Grafana)

## 🔍 Security Audit

### Container-Sicherheit prüfen

```bash
# 1. Schwachstellen scannen
docker scan isms-app:latest
docker scan postgres:16-alpine

# 2. Container-Konfiguration prüfen
docker inspect isms-app | jq '.[0].HostConfig.SecurityOpt'

# 3. Offene Ports prüfen
docker ps --format "table {{.Names}}\t{{.Ports}}"

# 4. Resource-Nutzung überwachen
docker stats

# 5. Logs auf Fehler prüfen
docker-compose logs --tail=100 app | grep -i error
```

### Netzwerk-Sicherheit prüfen

```bash
# Welche Ports sind öffentlich?
sudo netstat -tulpn | grep docker

# Nur localhost-Ports sind sicher:
# 127.0.0.1:5432  ✅ Nur lokal erreichbar
# 0.0.0.0:5432    ❌ Öffentlich erreichbar!
```

## 🚨 Security Incidents

### Was tun bei Sicherheitsvorfall?

1. **Sofort:** Container stoppen
   ```bash
   docker-compose down
   ```

2. **Logs sichern**
   ```bash
   docker-compose logs > incident-logs.txt
   ```

3. **Passwörter ändern**
   ```bash
   # Neue Passwörter in .env.docker.local
   # Datenbank-User-Passwort ändern
   docker-compose exec db psql -U postgres -c "ALTER USER isms_user WITH PASSWORD 'new_password';"
   ```

4. **Volumes prüfen**
   ```bash
   docker volume inspect isms_db_data
   ```

5. **Neu deployen** mit neuer Konfiguration

## 📚 Weitere Ressourcen

- [Docker Security Best Practices](https://docs.docker.com/engine/security/)
- [CIS Docker Benchmark](https://www.cisecurity.org/benchmark/docker)
- [OWASP Docker Security](https://cheatsheetseries.owasp.org/cheatsheets/Docker_Security_Cheat_Sheet.html)

## 🆘 Support

Bei Sicherheitsfragen:
1. Prüfen Sie [DOCKER_SETUP.md](DOCKER_SETUP.md)
2. Konsultieren Sie die Docker-Logs
3. Erstellen Sie ein Issue (OHNE sensitive Daten!)
