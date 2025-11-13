# Docker Hub Integration

## Übersicht

Little ISMS Helper wird automatisch zu Docker Hub gepusht über den CI/CD Workflow. Die Images sind öffentlich verfügbar und können direkt verwendet werden.

## Verfügbare Images

### Production Image (empfohlen)

Das Production-Image ist optimiert für den Produktionseinsatz:

```bash
# Neueste stabile Version (main branch)
docker pull <username>/little-isms-helper:latest

# Spezifischer Branch
docker pull <username>/little-isms-helper:main

# Spezifischer Commit
docker pull <username>/little-isms-helper:main-abc1234
```

**Features:**
- ✅ PHP 8.4 FPM + Nginx
- ✅ OPcache aktiviert und optimiert
- ✅ Nur Production Dependencies
- ✅ Minimale Image-Größe
- ✅ Security Hardening

### Development Image

Das Development-Image enthält zusätzliche Tools für die Entwicklung:

```bash
# Development Version
docker pull <username>/little-isms-helper:dev-develop
docker pull <username>/little-isms-helper:dev-claude-xyz

# Hinweis: Branch-Namen mit "/" werden zu "-" konvertiert
# claude/feature-branch → dev-claude-feature-branch
```

**Zusätzliche Features:**
- ✅ Xdebug für Debugging
- ✅ Alle Dev-Dependencies
- ✅ Hot-Reload Support

## Verwendung

### Mit Docker Run

```bash
# Starten mit externer PostgreSQL-Datenbank
docker run -d \
  --name little-isms-helper \
  -p 8000:80 \
  -e DATABASE_URL="postgresql://user:pass@host:5432/dbname" \
  -e APP_SECRET="your-secret-key" \
  <username>/little-isms-helper:latest
```

### Mit Docker Compose (empfohlen)

Verwenden Sie die mitgelieferte `docker-compose.yml`:

```bash
# Clone Repository (für docker-compose.yml)
git clone https://github.com/moag1000/Little-ISMS-Helper.git
cd Little-ISMS-Helper

# Services starten
docker-compose up -d
```

Oder erstellen Sie eine eigene `docker-compose.yml`:

```yaml
version: '3.8'

services:
  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: little_isms
      POSTGRES_USER: isms_user
      POSTGRES_PASSWORD: isms_password
    volumes:
      - db_data:/var/lib/postgresql/data

  app:
    image: <username>/little-isms-helper:latest
    ports:
      - "8000:80"
    depends_on:
      - db
    environment:
      DATABASE_URL: postgresql://isms_user:isms_password@db:5432/little_isms

volumes:
  db_data:
```

## Image Tags

Der CI/CD Workflow erstellt automatisch folgende Tags:

| Tag | Beschreibung | Trigger |
|-----|--------------|---------|
| `latest` | Neueste stabile Version | Push zu `main` branch |
| `main` | Main branch | Push zu `main` |
| `develop` | Development branch | Push zu `develop` |
| `main-<sha>` | Spezifischer Commit | Jeder Push zu `main` |
| `develop-<sha>` | Dev-Commit | Jeder Push zu `develop` |
| `dev-<branch>` | Development Image | Push zu `develop` oder `claude/*` (Note: `/` wird zu `-`) |

## CI/CD Workflow

Der Workflow führt folgende Schritte aus:

1. **Tests** - PHPUnit Tests mit PostgreSQL
2. **Code Quality** - PHP CS Fixer & PHPStan
3. **Security** - Symfony Security Check
4. **Docker Build & Push** - Multi-Stage Build & Push zu Docker Hub

### Build-Ablauf

```
Tests passed → Build production image → Push to Docker Hub → Tag with metadata
```

## Automatisches Logo-Upload

Das Repository-Logo wird **automatisch zu Docker Hub hochgeladen** beim Push zum `main` Branch!

### Wie funktioniert's?

- ✅ Script: `.github/scripts/upload-dockerhub-logo.sh`
- ✅ Logo: `public/logo-512.png` (512x512 PNG)
- ✅ Trigger: Push zu `main` Branch
- ✅ Fehler-tolerant: Build schlägt nicht fehl, wenn Upload nicht funktioniert

### Manueller Upload (falls nötig)

Falls der automatische Upload nicht funktioniert:

1. Gehe zu deinem Repository: `https://hub.docker.com/r/<username>/little-isms-helper`
2. Klicke auf den **Repository-Namen** (oben links, neben dem Icon)
3. Bewege Maus über das Icon-Placeholder und klicke **"Edit"**
4. Lade `public/logo-512.png` hoch
5. Klicke **"Save"**

## Setup für Contributors

Wenn Sie zum Projekt beitragen und Docker Images bauen möchten:

### GitHub Secrets einrichten

1. Gehe zu **Settings** → **Secrets and variables** → **Actions**
2. Erstelle folgende Secrets:
   - `DOCKERHUB_USERNAME`: Dein Docker Hub Username
   - `DOCKERHUB_TOKEN`: Docker Hub Access Token (wird auch für Logo-Upload verwendet)

### Docker Hub Token erstellen

1. Login auf https://hub.docker.com
2. Gehe zu **Account Settings** → **Security** → **New Access Token**
3. Name: `github-actions-little-isms-helper`
4. Permissions: **Read & Write**
5. Kopiere den Token und speichere ihn als GitHub Secret

## Multi-Stage Build

Das Dockerfile verwendet Multi-Stage Builds für optimale Größe:

```dockerfile
# Stage 1: Production - klein, optimiert, sicher
FROM php:8.4-fpm-alpine AS production
# ... nur production dependencies

# Stage 2: Development - extends production
FROM production AS development
# ... + dev dependencies + xdebug
```

### Image-Größen

| Image | Größe (ungefähr) |
|-------|------------------|
| Production | ~300-400 MB |
| Development | ~400-500 MB |

## Automatische Updates

Die Images werden automatisch gebaut bei:

- ✅ Push zu `main` branch → `latest` + `main` tags
- ✅ Push zu `develop` branch → `develop` tag
- ✅ Push zu `claude/*` branches → `dev-<branch>` tag
- ✅ Pull Requests → Build-Test (kein Push)

## Security

### Image Scanning

Die Images werden automatisch gescannt auf:
- Known vulnerabilities (via Trivy in future)
- Outdated dependencies
- Misconfigurations

### Best Practices

- ✅ Non-root user (www-data)
- ✅ Minimal base image (Alpine Linux)
- ✅ No secrets in image
- ✅ Read-only filesystem (wo möglich)
- ✅ Resource limits in docker-compose.yml

## Troubleshooting

### Image Pull schlägt fehl

```bash
# Login zu Docker Hub
docker login

# Dann erneut versuchen
docker pull <username>/little-isms-helper:latest
```

### Container startet nicht

```bash
# Logs anzeigen
docker logs <container-id>

# Interaktiv troubleshooten
docker run -it --entrypoint /bin/sh <username>/little-isms-helper:latest
```

### Datenbank-Verbindung schlägt fehl

Prüfen Sie:
1. ✅ DATABASE_URL ist korrekt gesetzt
2. ✅ Datenbank-Container läuft: `docker ps`
3. ✅ Netzwerk-Konfiguration: `docker network ls`

## Weiterführende Dokumentation

- [Docker Setup Guide](DOCKER_SETUP.md) - Vollständige Docker-Anleitung
- [Deployment Wizard](../deployment/DEPLOYMENT_WIZARD.md) - Produktions-Deployment
- [CI/CD Pipeline](.github/workflows/ci.yml) - Workflow-Konfiguration

## Docker Hub Repository

**Repository:** https://hub.docker.com/r/<username>/little-isms-helper

Dort finden Sie:
- Alle verfügbaren Tags
- Image-Historie
- Pull-Statistiken
- Weitere Informationen
