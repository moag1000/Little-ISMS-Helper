# 🛡️ Little ISMS Helper

**Moderne, webbasierte ISMS-Lösung für KMUs – ISO 27001:2022 konform**

[![Version](https://img.shields.io/badge/Version-2.2.1-success)](https://github.com/moag1000/Little-ISMS-Helper/releases)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-7.4-000000)](https://symfony.com/)
[![License](https://img.shields.io/badge/License-AGPL%20v3-blue)](https://github.com/moag1000/Little-ISMS-Helper/blob/main/LICENSE)

## 🚀 Quick Start

```bash
docker run -d \
  --name little-isms-helper \
  -p 8080:80 \
  -v isms_data:/var/lib/mysql \
  -v isms_uploads:/var/www/html/public/uploads \
  moag2000/little-isms-helper:latest
```

**Das war's!** Öffne `http://localhost:8080/setup` im Browser.

## 📦 Docker Compose (Empfohlen)

```yaml
version: '3.8'
services:
  isms:
    image: moag2000/little-isms-helper:latest
    ports:
      - "8080:80"
    volumes:
      - isms_data:/var/lib/mysql
      - isms_uploads:/var/www/html/public/uploads
    environment:
      - APP_ENV=prod
      - APP_SECRET=your-secret-key-here
    restart: unless-stopped

volumes:
  isms_data:
  isms_uploads:
```

## ✨ Features

| Feature | Beschreibung |
|---------|--------------|
| 📋 **ISO 27001:2022** | Alle 93 Annex A Controls integriert |
| 📊 **Multi-Framework** | ISO 27001, ISO 22301, TISAX, DORA, NIS2, BSI |
| 🔐 **GDPR/DSGVO** | VVT, DSFA, Datenpannen, Einwilligungen |
| 📈 **Risk Management** | ISO 27005 konformes Risikomanagement |
| 🏢 **BCM** | Business Continuity nach ISO 22301 |
| 📝 **Audit Management** | Interne Audits nach ISO 19011 |
| 🔄 **Workflows** | Automatische Genehmigungsprozesse |
| 📊 **Dashboards** | Echtzeit-KPIs und Metriken |
| 🌍 **Mehrsprachig** | Deutsch & Englisch |

## 🏗️ Architektur

- **All-in-One Container**: App + MariaDB embedded
- **Persistente Daten**: Nur 2 Volumes nötig
- **Health Checks**: Automatische Überwachung
- **Resource Limits**: Production-ready

## 📖 Dokumentation

- [GitHub Repository](https://github.com/moag1000/Little-ISMS-Helper)
- [Quick Start Guide](https://github.com/moag1000/Little-ISMS-Helper#-quick-start-mit-docker)
- [Production Deployment](https://github.com/moag1000/Little-ISMS-Helper/blob/main/docs/deployment/DOCKER_PRODUCTION.md)
- [Changelog](https://github.com/moag1000/Little-ISMS-Helper/blob/main/CHANGELOG.md)

## 🏷️ Tags

| Tag | Beschreibung |
|-----|--------------|
| `latest` | Aktuelle stabile Version |
| `2.2.1` | Version 2.2.1 (Proactive Compliance Monitoring) |
| `2.2.0` | Version 2.2.0 |
| `2.1.x` | Legacy Versionen |

## 💡 Umgebungsvariablen

| Variable | Default | Beschreibung |
|----------|---------|--------------|
| `APP_ENV` | `prod` | Umgebung (prod/dev) |
| `APP_SECRET` | (generiert) | Symfony Secret Key |
| `DATABASE_URL` | (embedded) | DB-Verbindung |
| `MAILER_DSN` | `null://null` | E-Mail-Konfiguration |

## ☕ Support

Wenn Little ISMS Helper nützlich für Sie ist:

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-Support-yellow)](https://www.buymeacoffee.com/moag1000)

## 📜 Lizenz

AGPL-3.0 - Siehe [LICENSE](https://github.com/moag1000/Little-ISMS-Helper/blob/main/LICENSE)
