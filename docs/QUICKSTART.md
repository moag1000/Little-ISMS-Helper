# Quickstart — Vom Klon zum laufenden ISMS in 30 Minuten

Diese Anleitung führt durch die Erstinstallation und den Setup-Wizard. Am Ende läuft der **Little ISMS Helper** mit ausgewählten Frameworks, einem Admin-User und (optional) Demo-Daten.

> Für die Sicht durch verschiedene Rollen siehe [Sichtwechsel](sichtwechsel/README.md). Für tiefe Architektur-Details siehe [docs/architecture/](architecture/).

---

## Was Sie brauchen

| Anforderung | Version / Wert |
|---|---|
| **PHP** | 8.4+ (8.5 getestet) |
| **MariaDB / MySQL** | 10.6+ / 8.0+ |
| **Composer** | 2.x |
| **Symfony CLI** | empfohlen für `symfony serve` |
| **Browser** | aktueller Chrome/Firefox/Safari |
| **Disk** | ~500 MB (Code + Vendor + DB) |
| **RAM** | 512 MB minimum, 1 GB empfohlen |

Erweiterte Setup-Optionen (Docker, Plesk, Production-Hardening): [docs/setup/](setup/) und [docs/deployment/](deployment/).

---

## 1. Code holen + Abhängigkeiten

```bash
git clone https://github.com/<owner>/Little-ISMS-Helper.git
cd Little-ISMS-Helper

composer install
php bin/console importmap:install
```

---

## 2. Basis-Konfiguration

`.env.local` anlegen, minimum:

```env
APP_ENV=dev
APP_SECRET=<32-byte-zufalls-string>
DATABASE_URL="mysql://user:pass@127.0.0.1:3306/isms_helper?serverVersion=10.6&charset=utf8mb4"
```

`APP_SECRET` generieren: `php -r 'echo bin2hex(random_bytes(32));'`. Wird für Backup-Verschlüsselung verwendet — bei Verlust sind alte Backups nicht mehr lesbar.

---

## 3. Datenbank vorbereiten

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
```

Migrations dauern beim ersten Lauf 30–60 Sekunden (78 Entities, 200+ Migrations).

---

## 4. Symfony-Server starten

```bash
symfony server:start --daemon --port=8000
# alternativ: php -S 127.0.0.1:8000 -t public/
```

Browser: <http://127.0.0.1:8000> — der **Setup-Wizard** öffnet sich automatisch.

---

## 5. Setup-Wizard durchlaufen

Der Wizard führt durch 11 Schritte. Jeder Schritt prüft eine Voraussetzung oder erfasst Konfiguration. Speicherung erfolgt schritt-weise — Sie können jederzeit pausieren und später fortsetzen.

### Schritt 0 — Willkommen

![Setup-Welcome](sichtwechsel/img/setup/setup-step0-welcome.png)

Übersicht der kommenden Schritte. Geschätzte Dauer: ~30 Minuten. Kein Eingabefeld — nur "Weiter".

### Schritt 1 — System-Anforderungen

![Setup Step 1 — Anforderungen](sichtwechsel/img/setup/setup-step1-requirements.png)

Automatischer Check: PHP-Version, PHP-Extensions, Datenbank-Verbindung, Verzeichnis-Berechtigungen, Memory-Limit, Max-Execution-Time, Symfony-Version. **Grüner Haken** = OK; **rotes X** = Sie müssen die Anforderung beheben, bevor Sie weiter können. Häufige Probleme:

- *PHP-Extension `intl` fehlt* → `apt install php-intl` / `brew install php@8.4`
- *DB-Verbindung fehlgeschlagen* → `DATABASE_URL` in `.env.local` prüfen
- *`var/cache` nicht beschreibbar* → `chmod -R 775 var/`

### Schritt 2 — Datenbank-Konfiguration

DB-Verbindungstest. Falls in Schritt 1 alles grün war, wird hier nur bestätigt — manuelle Konfiguration nur wenn `DATABASE_URL` nicht passt. Bei Docker-Installation wird das Default-Passwort gesetzt.

### Schritt 3 — Backup-Restore (optional)

Wenn Sie aus einem bestehenden ISMS migrieren: ZIP-Backup hochladen, Schema reparieren, verwaiste Entitäten zuordnen. Andernfalls überspringen mit "Frische Installation".

Details: [Backup Architecture](operations/BACKUP_ARCHITECTURE.md), [Disaster Recovery](operations/DISASTER_RECOVERY.md).

### Schritt 4 — Administrator anlegen

Erster User. **ROLE_SUPER_ADMIN** — kann alles, inklusive andere User anlegen, Tenants verwalten, Module aktivieren.

| Feld | Hinweis |
|---|---|
| E-Mail | später für SSO-Mapping verwendet (Azure OAuth/SAML) |
| Passwort | min. 12 Zeichen, mindestens 1 Großbuchstabe, 1 Zahl, 1 Sonderzeichen. PHP-Hash via Symfony PasswordHasher (Auto). |
| Vor-/Nachname | Pflicht — wird in Audit-Log und Reports angezeigt |

### Schritt 5 — E-Mail-Konfiguration (optional)

SMTP-Daten falls das Tool Notifications versenden soll (Workflow-Approvals, Scheduled-Reports). Kann später unter Admin → Tenant-Email-Branding nachgepflegt werden.

### Schritt 6 — Organisation

Compliance-relevante Stammdaten der Organisation:

- Firmenname, Branche (NACE-Code), Mitarbeiterzahl, Standorte
- NIS2-Klassifikation (wesentlich / wichtig / nicht-NIS2-pflichtig)
- BSI-Phase (Basis / Standard / Kern-Absicherung)
- Holding-Struktur (Mutter / Tochter / Standalone)

Diese Angaben steuern, welche Frameworks in Schritt 8 als **Pflicht** vs. **Empfohlen** vorgeschlagen werden.

### Schritt 7 — Module auswählen

![Setup Step 7 — Module](sichtwechsel/img/setup/setup-step7-modules.png)

Funktionsmodule aktivieren — jedes Modul ist eine Domäne (z.B. `BCM`, `Datenschutz`, `Lieferanten`, `Vorfälle`). Empfehlungen basieren auf Schritt 6 (z.B. NIS2-Klassifikation aktiviert automatisch `Vorfallmeldung`-Modul).

Module können später jederzeit unter **Admin → Module-Verwaltung** aktiviert/deaktiviert werden.

### Schritt 8 — Compliance-Frameworks

![Setup Step 8 — Frameworks](sichtwechsel/img/setup/setup-step8-compliance-frameworks.png)

Frameworks laden — **Pflicht** (basierend auf Branche), **Empfohlen** (Best-Practice für die Größe), **Optional** (auf Anforderung). Beispiele:

- Mittelständler in DE → ISO 27001:2022 (Empfohlen) + DSGVO (Pflicht für jeden, der personenbezogene Daten verarbeitet)
- KRITIS Energie → ISO 27001 + NIS2 + BSI IT-Grundschutz + KRITIS-Sektor-Anforderungen
- Finanzdienstleister BaFin → ISO 27001 + DORA (löst VAIT/BAIT/KAIT/ZAIT seit 2025-01 ab) + DSGVO

Geladene Frameworks erscheinen sofort unter **Compliance → Frameworks** und sind für Cross-Mapping verfügbar (siehe [Compliance Frameworks Guide](COMPLIANCE_FRAMEWORKS_GUIDE.md)).

### Schritt 9 — Basis-Daten

Branchen-spezifische Vorbefüllung: typische Asset-Kategorien, Risiko-Templates, Bedrohungs-Kataloge, Maßnahmen-Vorlagen. Spart 5–15 Personentage Erstbefüllung.

### Schritt 10 — Beispiel-Daten (optional)

![Setup Step 10 — Beispiel-Daten](sichtwechsel/img/setup/setup-step10-sample-data.png)

Demo-Datensätze laden — 5 Beispiel-Assets, 8 Risiken, 3 Lieferanten, 2 Vorfälle, etc. **Nur in Test-/Lernumgebungen**, nicht in Produktion.

Demo-Daten können jederzeit unter **Admin → Beispiel-Daten** gelöscht werden.

### Schritt 11 — Fertigstellung

![Setup Step 11 — Abschluss](sichtwechsel/img/setup/setup-step11-complete.png)

Bestätigung. Das ISMS ist eingerichtet. Erster Login folgt — danach landet man auf der **Welcome-Seite** mit Dringend-Aufgaben und Modul-Kacheln.

---

## 6. Erster Login

Nach Setup-Abschluss → **Anmeldung** mit dem in Schritt 4 angelegten Admin.

![Welcome-Seite nach erstem Login](sichtwechsel/img/implementer-junior/welcome.png)

Die **Welcome-Seite** zeigt:

- Dringend-Aufgaben-Karte (anfangs leer)
- Aktive Compliance-Wizards (basierend auf Schritt 8)
- Modul-Kacheln (basierend auf Schritt 7)
- Onboarding-Hinweise

Ab hier ist das ISMS einsatzbereit. Empfohlene Reihenfolge der weiteren Schritte:

1. **Kontext / Scope** anlegen (`/de/context/`) — interessierte Parteien, ISMS-Geltungsbereich
2. **Assets** erfassen (`/de/asset/`) — Schutzobjekte mit CIA-Werten
3. **Risiken** erfassen (`/de/risk/`) — Bedrohungs-Schwachstelle-Kombinationen pro Asset
4. **SoA** befüllen (`/de/soa/`) — Annex-A-Controls Anwendbar/Nicht + Begründung
5. **Compliance-Wizard** durchlaufen (`/de/compliance-wizard`) für jedes Framework

> Detaillierter Junior-Walkthrough: [JUNIOR_IMPLEMENTER_WALKTHROUGH.md](JUNIOR_IMPLEMENTER_WALKTHROUGH.md)

---

## 7. Häufige Probleme nach Setup

### "Welcome-Seite zeigt 23 kritische Lücken"
Diese Karte aggregiert offene SoA-Begründungen, fehlende Risk-Owner, ungeprüfte Controls. Direkt nach Setup ist das normal — wird durch das Befüllen der Module abgebaut.

### "Mein Tenant erscheint nicht in der Mandanten-Auswahl"
Setup legt automatisch den ersten Tenant an. Falls Sie als ROLE_SUPER_ADMIN eingeloggt sind und keinen Tenant-Switcher sehen: **Admin → Tenant-Verwaltung** prüfen.

### "Compliance-Wizard sagt 'Keine Frameworks verfügbar'"
Schritt 8 wurde übersprungen oder Datenbank-Migration unvollständig. Manuelles Laden:
```bash
php bin/console app:load-iso-27001
php bin/console app:load-nis2-framework
# alle verfügbaren Loader: php bin/console list app:load
```

### "Migration `SAVEPOINT DOCTRINE_X does not exist`"
Bekannter Fall bei DDL-Migrations ohne `isTransactional()=false`. Recovery:
```bash
php bin/console app:schema:reconcile --dry-run
php bin/console app:schema:reconcile
```
Details siehe [`CLAUDE.md` Common Pitfalls #6](../CLAUDE.md).

---

## 8. Nächste Schritte

| Was | Wo |
|---|---|
| **Persona-Walkthroughs** mit Screenshots | [Sichtwechsel](sichtwechsel/README.md) |
| **Modul-Übersicht** und -Aktivierung | [Admin Guide](ADMIN_GUIDE.md) |
| **Cross-Framework-Mappings** | [Compliance Frameworks Guide](COMPLIANCE_FRAMEWORKS_GUIDE.md) |
| **Production-Deployment** (Docker, Plesk) | [Docker Production](deployment/DOCKER_PRODUCTION.md) · [Plesk Deployment](deployment/DEPLOYMENT_PLESK.md) |
| **Authentication-Setup** (SSO, Azure, SAML) | [Authentication Setup](setup/AUTHENTICATION_SETUP.md) |
| **Backup & Restore** | [Disaster Recovery](operations/DISASTER_RECOVERY.md) |
| **Workflow-Auto-Progression** | [Workflow Auto-Progression](WORKFLOW_AUTO_PROGRESSION.md) |
| **Architektur-Übersicht** | [Solution Description](architecture/SOLUTION_DESCRIPTION.md) |

---

## Support

- Bug-Reports: [GitHub Issues](https://github.com/anthropics/claude-code/issues) — bitte mit Reproduktionsschritten
- Discussions: GitHub Discussions Tab
- Dev-Doku: [`CLAUDE.md`](../CLAUDE.md) (für Beiträger)

---

[← Zurück zum Haupt-README](../README.md)
