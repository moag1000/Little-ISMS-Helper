# Docker Security - Little ISMS Helper

Gilt für das Production-Image (`docker-compose.prod.yml`, eingebettete MariaDB). Betrieb,
Volumes und Reverse Proxy: [DOCKER_PRODUCTION.md](../deployment/DOCKER_PRODUCTION.md).

## Was persistent ist

| Pfad im Container | Inhalt | Volume |
|---|---|---|
| `/var/www/html/var` | MariaDB (`var/mysql`), Nachweisdateien (`var/uploads`), Backups (`var/backups`), `var/app_secret`, `var/mysql_password.txt`, `var/setup_complete.lock`, Sessions, Logs | `isms_data` (Pflicht) |
| `/var/www/html/public/uploads` | Logos, Profilbilder | eigenes Volume nötig |

`.env.local` liegt im Image-Layer und wird bei jedem Start neu geschrieben (`APP_SECRET` aus
`var/app_secret`, `DATABASE_URL` der eingebetteten MariaDB). Weitere Einstellungen gehören in
`environment:` der Compose-Datei, nicht in `.env.local` im Container.

Persistenz prüfen:

```bash
docker compose -f docker-compose.prod.yml down
docker compose -f docker-compose.prod.yml up -d
# Login-Seite erscheint, kein Setup-Wizard
```

## Checkliste vor dem Go-Live

- [ ] `APP_ENV=prod`, `APP_DEBUG=0` (Standard in `docker-compose.prod.yml`)
- [ ] `MYSQL_PASSWORD` gesetzt oder das generierte Passwort aus `var/mysql_password.txt` sicher abgelegt
- [ ] `APP_SECRET` stabil: entweder generiert in `var/app_secret` (Volume) oder fest gesetzt — ein
      Wechsel macht verschlüsselte Backup-Felder und MFA-Geheimnisse unlesbar
- [ ] Optional `APP_AUDIT_HMAC_KEY` (≥ 32 Byte) für signierte Audit-Log-Zeilen und
      `MFA_ENCRYPTION_KEY` für einen eigenen MFA-Schlüssel
- [ ] `DEFAULT_URI` = öffentliche URL
- [ ] HTTPS über einen Reverse Proxy; `SYMFONY_TRUSTED_PROXIES` nur ergänzen, wenn der Proxy eine
      öffentliche IP hat
- [ ] Echter SMTP-Server in `MAILER_DSN`
- [ ] Nur Port 80/443 (bzw. der Proxy-Port) erreichbar; die eingebettete MariaDB hat keinen Port nach außen
- [ ] `/quick-fix` per IP-Allowlist einschränken (Admin → Quick-Fix-Einstellungen,
      [QUICK_FIX.md](../user-guide/QUICK_FIX.md))
- [ ] `var/backups/` regelmäßig vom Host wegkopieren
- [ ] Log-Rotation: in `docker-compose.prod.yml` gesetzt (`json-file`, 10 MB × 5)

Firewall (Beispiel UFW):

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

## Prozesse und Rechte

`supervisord` läuft als root und startet nginx, PHP-FPM (Worker als `www-data`), MariaDB
(`mysql`) und den Scheduler-Worker (`www-data`). Das Image setzt keine `USER`-Direktive.

## Prüfen

```bash
# Schwachstellen (Trivy läuft auch in der CI nach jedem Release-Build)
trivy image moag2000/little-isms-helper:3.16.1

# Offene Ports
docker ps --format "table {{.Names}}\t{{.Ports}}"

# Fehler im Log
docker compose -f docker-compose.prod.yml logs --tail=100 app | grep -i error
```

## Sicherheitsvorfall

1. Container stoppen: `docker compose -f docker-compose.prod.yml stop`
2. Logs sichern: `docker compose -f docker-compose.prod.yml logs > incident-logs.txt`; dazu
   `var/log/` aus dem Volume
3. Passwörter und Schlüssel wechseln (DB-Passwort, SMTP, API-Tokens unter Profil → API-Tokens, `/profile/api-tokens`)
4. Neu starten und Audit-Log prüfen (Admin → Audit-Log → Integrität prüfen)

## Development-Compose

`docker-compose.yml` (MailHog, pgAdmin, externe DB) ist nur für die Entwicklung. Die
Nebendienste binden ihre Ports an `127.0.0.1`. Die Standardpasswörter dort über
`--env-file .env.docker.local` überschreiben. Siehe [DOCKER_SETUP.md](DOCKER_SETUP.md).

## Weitere Ressourcen

- [Docker Security](https://docs.docker.com/engine/security/)
- [CIS Docker Benchmark](https://www.cisecurity.org/benchmark/docker)
- [OWASP Docker Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Docker_Security_Cheat_Sheet.html)
