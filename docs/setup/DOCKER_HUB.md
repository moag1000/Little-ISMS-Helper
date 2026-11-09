# Docker Hub Integration

## Übersicht

Der CI-Job "Docker Build & Push" (`.github/workflows/ci.yml`) baut das Production-Image
bei jedem Release-Tag (`v*`) für `linux/amd64` und `linux/arm64` und pusht es nach:

- Docker Hub (kanonisch): `moag2000/little-isms-helper`
- GitHub Container Registry (Mirror): `ghcr.io/moag1000/little-isms-helper`

Branch-Pushes und Pull Requests bauen kein Image.

## Image

```bash
docker pull moag2000/little-isms-helper:3.16.1   # genaue Version (für Produktion)
docker pull moag2000/little-isms-helper:3.16     # neueste Patch-Version der Linie
docker pull moag2000/little-isms-helper:latest   # neueste stabile Version
```

Inhalt: PHP 8.5 FPM (Debian trixie), nginx, eingebettete MariaDB, supervisord mit
Scheduler-Worker, OPcache, nur Production-Dependencies. Start, Volumes und
Umgebungsvariablen: [DOCKER_PRODUCTION.md](../deployment/DOCKER_PRODUCTION.md).

## Image-Tags

| Tag | Quelle |
|-----|--------|
| `X.Y.Z`, `X.Y`, `latest` | Stable-Release (release-please, siehe `CONTRIBUTING.md`) |
| `X.Y.Z-rc.N`, `rc` | Tag `vX.Y.Z-rc.N` |
| `X.Y.Z-dev.N`, `dev` | Workflow "Dev Release (manual)" |
| `sha-<commit>` | jeder gebaute Tag |

Tag-Regeln: Block `steps.meta.tags` in [`ci.yml`](../../.github/workflows/ci.yml).

## Docker-Hub-Overview und Logo

Beim Release-Tag lädt `.github/scripts/upload-dockerhub-logo.sh` das Logo
(`public/logo-512.png`) und `README.md` als Overview hoch. Fehler brechen den Build
nicht ab.

Manuell: `https://hub.docker.com/r/moag2000/little-isms-helper` → Repository-Name →
Icon "Edit" → `public/logo-512.png` hochladen.

## Setup für Maintainer

GitHub → **Settings** → **Secrets and variables** → **Actions**:

- `DOCKERHUB_USERNAME`
- `DOCKERHUB_TOKEN` (Docker Hub → Account Settings → Security → New Access Token,
  Permissions **Read & Write**)

## Build-Stufen

Das `Dockerfile` hat zwei Stufen:

- `production` — das veröffentlichte Image
- `development` — erweitert `production` um Xdebug und `php.ini-development`
  (lokal über `docker-compose.dev.yml`, wird nicht veröffentlicht)

## Security

- SBOM (SPDX) und SLSA-Provenance werden ins OCI-Manifest eingebettet.
- Trivy scannt das Image nach dem Push (Ergebnis im CI-Lauf, nicht blockierend).
- PHP-FPM und Worker laufen als `www-data`; keine Secrets im Image (`APP_SECRET` wird
  beim ersten Start erzeugt und in `var/app_secret` gespeichert).

## Troubleshooting

```bash
# Logs
docker logs <container>

# Prozesse im Container
docker exec <container> supervisorctl status

# Shell
docker exec -it <container> bash
```

## Weiterführende Dokumentation

- [DOCKER_PRODUCTION.md](../deployment/DOCKER_PRODUCTION.md) — Produktionsbetrieb
- [DOCKER_SETUP.md](DOCKER_SETUP.md) — Entwicklung mit Docker Compose
- [CI-Workflow](../../.github/workflows/ci.yml)
