# Disaster Recovery Runbook — Little ISMS Helper

**Version:** 1.0  
**Sprache:** Deutsch (Ops-Team)  
**Stand:** 2026-04-24  
**Referenz (Entwickler):** [BACKUP_ARCHITECTURE.md](BACKUP_ARCHITECTURE.md)

---

## Inhaltsverzeichnis

1. [Backup-Scope — was ist enthalten?](#1-backup-scope)
2. [Backup erstellen](#2-backup-erstellen)
3. [Wiederherstellungsszenarien](#3-wiederherstellungsszenarien)
   - [Szenario A: Gleicher Host, gleiche DB (Rollback)](#szenario-a-gleicher-host-gleiche-db--normaler-rollback)
   - [Szenario B: Gleicher Host, frische DB](#szenario-b-gleicher-host-frische-db--db-korrupt-oder-gelöscht)
   - [Szenario C: Anderer Host (Serverumzug)](#szenario-c-anderer-host--serverumzug)
   - [Szenario D: Tenant-selektive Wiederherstellung](#szenario-d-tenant-selektive-wiederherstellung)
   - [Szenario E: Dry-Run (Validierung ohne Persistenz)](#szenario-e-dry-run--validierung-ohne-persistenz)
4. [APP_SECRET bei Wiederherstellung](#4-app_secret-bei-wiederherstellung)
5. [Integritätsprüfung (SHA-256)](#5-integritätsprüfung-sha-256)
6. [Schema-Versionen und Kompatibilität](#6-schema-versionen-und-kompatibilität)
7. [Häufige Fehler und Behebung](#7-häufige-fehler-und-behebung)
8. [Backup-Testplan (monatliches Drill)](#8-backup-testplan-monatliches-drill)

---

## 1. Backup-Scope

### Was ist im Backup enthalten?

| Kategorie | Enthalten | Hinweis |
|-----------|-----------|---------|
| Alle produktiven DB-Zeilen (54 Entities) | Ja | Vollständige Wiederherstellung aller ISMS-Daten |
| Audit-Log (`AuditLog`) | Ja, wenn `--include-audit-log` | Standard: aktiviert in Admin-UI |
| User-Sessions (`UserSession`) | Nur wenn explizit gewählt | Standard: deaktiviert |
| Hochgeladene Dateien (Dokumente, Tenant-Logos) | Ja, wenn Backup als ZIP erstellt | `files_included: true` in Metadaten |
| ManyToMany-Pivot-Tabellen | Ja | Zweiter Pass im Restore |

### Was ist NICHT im Backup enthalten?

| Kategorie | Nicht enthalten | Grund |
|-----------|-----------------|-------|
| `APP_SECRET` | Nein | Sicherheitskritisch, gehört in `.env.local` |
| `.env.local` | Nein | Umgebungsspezifisch |
| `var/cache/` | Nein | Regenerierbar via `php bin/console cache:clear` |
| `var/log/` | Nein | Operational Logs, kein Restore-Bedarf |
| Benutzerpasswörter | Nein | Sicherheitsfeature — Felder `password`, `salt`, `mfaSecret` werden explizit ausgeschlossen |
| `resetToken`, `resetTokenExpiresAt` | Nein | Nicht persistent relevant |

> **Hinweis zu Passwörtern:** Nach einem Restore müssen alle Benutzer ihr Passwort neu setzen oder es muss über die CLI ein Admin-Passwort gesetzt werden (siehe [Szenario B](#szenario-b-gleicher-host-frische-db--db-korrupt-oder-gelöscht)).

---

## 2. Backup erstellen

### 2.1 Über die Admin-UI

1. In der Anwendung anmelden als `ROLE_SUPER_ADMIN` (globales Backup) oder `ROLE_ADMIN` (Tenant-Backup)
2. Navigieren zu **Admin > Datenverwaltung > Backup**
3. Optionen wählen:
   - **Audit-Log einschließen** (empfohlen: aktiviert)
   - **User-Sessions einschließen** (Standard: deaktiviert)
   - **Tenant-Scope** (leer = global; nur SUPER_ADMIN kann global)
4. Auf **Backup erstellen** klicken
5. Download des erzeugten `.zip` oder `.json.gz` über den Download-Button

**URL:** `/admin/data/backup` (lokale Instanz: `https://isms.example.com/admin/data/backup`)

### 2.2 CLI-Backup via `app:backup:create` und `app:backup:restore`

Die Backup-Funktionalität ist über die Admin-UI (`AdminBackupController`) sowie über dedizierte Symfony-Console-Commands erreichbar:

```bash
# Backup über CLI erstellen
php bin/console app:backup:create

# Backup über CLI wiederherstellen
php bin/console app:backup:restore var/backups/backup_YYYY-MM-DD_HH-MM-SS.json.gz
```

Für automatisierte (cron-basierte) Backups kann die API direkt aufgerufen werden:

```bash
# Backup über HTTP-API (erfordert gültige Session / CSRF-Token)
# Empfohlen: Backup über Admin-UI + anschließend Download per curl
curl -u admin:password \
  -X POST "https://isms.example.com/admin/data/backup/create" \
  -d "_token=CSRF_TOKEN&include_audit_log=1&include_user_sessions=0" \
  -o backup_$(date +%Y-%m-%d).zip
```

**Alternativ: Datenbank-Dump als Ergänzung**

```bash
# MySQL-Dump als einfaches Backup
mysqldump -h localhost -u DB_USER -pDB_PASS DB_NAME \
  | gzip > /backup/isms_db_$(date +%Y-%m-%d).sql.gz
```

### 2.3 Backup-Dateinamen und Speicherort

Backups werden in `var/backups/` gespeichert:

| Format | Dateinamen-Muster | Beschreibung |
|--------|-------------------|--------------|
| ZIP mit Dateien | `backup_YYYY-MM-DD_HH-ii-ss.zip` | Format 2.0, enthält DB + Uploads |
| Komprimiertes JSON | `backup_YYYY-MM-DD_HH-ii-ss.json.gz` | Format 2.0/1.0, nur DB |
| JSON (unkomprimiert) | `backup_YYYY-MM-DD_HH-ii-ss.json` | Fallback ohne ext-zlib |
| Hochgeladenes Backup | `uploaded_YYYY-MM-DD_HH-ii-ss.*` | Extern hochgeladene Backups |

---

## 3. Wiederherstellungsszenarien

> **Vor jedem Restore:** Dry-Run gemäß [Szenario E](#szenario-e-dry-run--validierung-ohne-persistenz) durchführen, um Fehler zu erkennen, bevor Daten überschrieben werden.

---

### Szenario A: Gleicher Host, gleiche DB — normaler Rollback

**Anwendungsfall:** Schlechtes Deployment rückgängig machen; Datenkorruption durch Bugfix-Release.

**Voraussetzung:** DB-Schema ist noch kompatibel mit dem Backup.

```bash
# 1. Application in Maintenance-Modus versetzen (Apache/Nginx down oder Wartungsseite aktivieren)

# 2. Schema auf Backup-Stand bringen (falls Migrations gerollt wurden)
#    Backup enthält schema_version in metadata — prüfen:
#    cat var/backups/backup_*.json | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['metadata']['schema_version'])"
php bin/console doctrine:migrations:migrate --no-interaction

# 3. Restore über Admin-UI:
#    Admin > Datenverwaltung > Backup > [Backup auswählen] > Wiederherstellen
#    Optionen:
#      - "Bestehende Daten löschen vor Restore": JA (für sauberes Rollback)
#      - "Fehlende Felder": Standard verwenden
#      - Dry-Run: vorher testen (Szenario E)

# 4. Cache leeren
php bin/console cache:clear

# 5. Maintenance-Modus deaktivieren
```

**Wann "Bestehende Daten löschen" aktivieren?**
- Immer beim vollständigen Rollback
- Wenn Constraint-Verletzungen im Dry-Run gemeldet werden
- Wenn der EntityManager wegen Datenbankfehlern geschlossen wird

---

### Szenario B: Gleicher Host, frische DB — DB korrupt oder gelöscht

**Anwendungsfall:** Datenbank-Crash, versehentliches `DROP DATABASE`, Ransomware.

```bash
# 1. Neue leere Datenbank anlegen
mysql -u root -p -e "CREATE DATABASE isms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON isms_db.* TO 'isms_user'@'localhost'; FLUSH PRIVILEGES;"

# 2. .env.local prüfen — DATABASE_URL muss auf neue DB zeigen
grep DATABASE_URL .env.local

# 3. Schema erzeugen (alle Migrations anwenden)
php bin/console doctrine:migrations:migrate --no-interaction

# 4. Backup-Datei in var/backups/ ablegen (falls nicht vorhanden)
cp /mnt/backup/backup_2026-04-20_03-00-00.zip var/backups/

# 5. Restore über Admin-UI:
#    - Backup hochladen oder aus Liste wählen
#    - "Bestehende Daten löschen vor Restore": JA
#    - "Admin-Passwort nach Restore setzen": neues temporäres Passwort eintragen
#    - Dry-Run: erst testen, dann echten Restore starten

# 6. Admin-Passwort alternativ über CLI setzen:
php bin/console app:setup-permissions \
  --admin-email=admin@example.com \
  --admin-password=TemporarySecurePassword123!

# 7. Cache leeren und Anwendung prüfen
php bin/console cache:clear
```

> **Wichtig:** Benutzerpasswörter werden aus Sicherheitsgründen nicht im Backup gespeichert. Alle Benutzer müssen ihre Passwörter nach dem Restore zurücksetzen.

---

### Szenario C: Anderer Host — Serverumzug

**Anwendungsfall:** Migration auf neue Infrastruktur; Disaster-Recovery auf Standby-Server.

```bash
# === AUF QUELL-HOST (alter Server) ===

# 1. APP_SECRET sichern — wird für Entschlüsselung von SystemSettings benötigt
grep APP_SECRET .env.local
# Beispiel-Output: APP_SECRET=a1b2c3d4e5f6...

# 2. Aktuelles Backup erstellen (Admin-UI oder Datenbank-Dump)
#    Backup-Datei herunterladen / auf Ziel-Host übertragen

scp var/backups/backup_2026-04-24_*.zip user@new-server:/tmp/


# === AUF ZIEL-HOST (neuer Server) ===

# 3. Anwendung deployen (ohne DB-Daten)
git clone https://github.com/moag1000/Little-ISMS-Helper.git /var/www/isms
cd /var/www/isms
composer install --no-dev --optimize-autoloader
php bin/console importmap:install

# 4. .env.local anlegen — APP_SECRET vom Quell-Host übertragen!
cat > .env.local << 'EOF'
APP_SECRET=a1b2c3d4e5f6...       # MUSS identisch mit Quell-Host sein!
DATABASE_URL=mysql://user:pass@localhost:3306/isms_new
EOF

# 5. Neue DB + Schema
mysql -u root -p -e "CREATE DATABASE isms_new CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php bin/console doctrine:migrations:migrate --no-interaction

# 6. Backup-Datei bereitstellen
mkdir -p var/backups
cp /tmp/backup_2026-04-24_*.zip var/backups/

# 7. Restore über Admin-UI oder nach erstem Setup:
#    Zuerst Setup-Wizard durchlaufen (erstellt initialen Admin-User)
#    Dann: Admin > Datenverwaltung > Backup > Backup hochladen > Wiederherstellen
#    "Bestehende Daten löschen": JA
#    "Admin-Passwort": temporäres Passwort setzen

# 8. Datei-Uploads synchronisieren (falls Backup kein ZIP mit Dateien)
rsync -av user@old-server:/var/www/isms/public/uploads/ /var/www/isms/public/uploads/

# 9. Cache leeren
php bin/console cache:clear

# 10. Webserver-Konfiguration anpassen (document root: public/)
```

---

### Szenario D: Tenant-selektive Wiederherstellung

**Anwendungsfall:** Einen einzelnen Mandanten aus einem Backup wiederherstellen, ohne andere Mandanten zu beeinflussen.

**Einschränkungen:**
- Nur Entitäten mit Tenant-Zuordnung werden wiederhergestellt
- Globale Entitäten (Role, Permission, SystemSettings) werden im tenant-scoped Backup übersprungen
- `ROLE_ADMIN` kann nur den eigenen Mandanten wiederherstellen; `ROLE_SUPER_ADMIN` kann jeden Mandanten wählen

```bash
# Tenant-selektive Wiederherstellung erfolgt ausschließlich über Admin-UI:
# Admin > Datenverwaltung > Backup > [Backup wählen] > Wiederherstellen
#   - Tenant-Scope: gewünschten Mandanten auswählen
#   - "Bestehende Daten löschen": löscht NUR Daten des gewählten Mandanten
#   - Dry-Run: IMMER vorher testen

# Cross-Tenant-Warnung:
# Falls das Backup für Tenant-ID X erstellt wurde, aber in Tenant-ID Y
# wiederhergestellt wird, erscheint eine Warnung:
# "Cross-Tenant-Restore: Das Backup wurde für Tenant-IDs [X] erstellt,
#  aber die Wiederherstellung erfolgt für Tenant-IDs [Y]."
# Dies ist beabsichtigt bei Tenant-Konsolidierung oder -Umzug.
```

**Warnung bei globalen Entitäten:**  
Bei einem tenant-scoped Backup wurden globale Entitäten (Role, Permission, SystemSettings) nicht gesichert. Deren Wiederherstellung ist nur aus einem globalen Backup möglich.

---

### Szenario E: Dry-Run — Validierung ohne Persistenz

**Anwendungsfall:** Backup vor dem echten Restore prüfen; sicherstellen, dass keine Fehler auftreten.

```bash
# Über Admin-UI:
# Admin > Datenverwaltung > Backup > [Backup wählen] > Wiederherstellen
#   - Option "Testlauf (Dry-Run)": aktivieren
#   - Alle anderen Optionen wie beim echten Restore einstellen
#   - Starten — die Transaktion wird am Ende automatisch ZURÜCKGEROLLT
#   - Warnungen und Statistiken prüfen

# Das System führt alle Restore-Schritte durch (FK-Checks deaktiviert,
# Entities angelegt, ManyToMany-Pivot-Tabellen befüllt),
# rollt aber die Transaktion am Ende zurück → keine Daten werden geändert.

# Typische Ausgabe nach Dry-Run:
# ✅ "Testlauf erfolgreich abgeschlossen (keine Daten wurden geändert)"
# ⚠️  Warnungen: Schema-Versionsunterschiede, fehlende optionale Felder
# ❌ Fehler: Ungültige Backup-Version, beschädigte Daten
```

**Validierungsschritte (Dry-Run + Validate):**

```bash
# 1. Backup-Datei hochladen (Admin-UI: Backup hochladen)
# 2. Validierung starten:
#    Admin > Backup > [Backup] > Validieren
#    Prüft: Format-Version, Metadaten-Struktur, Entity-Klassen, Pflichtfelder
# 3. Vorschau anzeigen:
#    Admin > Backup > [Backup] > Vorschau
#    Zeigt: Anzahl Datensätze pro Entity, bestehende DB-Einträge
# 4. Dry-Run starten (wie oben)
# 5. Bei 0 Fehlern: echten Restore durchführen
```

---

## 4. APP_SECRET bei Wiederherstellung

### Hintergrund

Ab **Backup-Format 2.0** werden sensible `SystemSettings`-Werte (SMTP-Passwörter, API-Keys, OAuth-Secrets) mit **AES-256-GCM** verschlüsselt. Der Schlüssel wird aus `APP_SECRET` via SHA-256 abgeleitet.

**Betroffene Felder** (Schlüsselmuster, case-insensitive):
- `*secret*`, `*password*`, `*private_key*`, `*api_key*`, `*client_secret*`, `*smtp_pass*`, `*oauth*`

### Problem

Wird auf einem Host mit **anderem `APP_SECRET`** wiederhergestellt, schlägt die Entschlüsselung fehl:

```
Encrypted secret could not be decrypted — ensure APP_SECRET matches the source environment,
or replace the secret manually after restore
```

### Lösungen

**Option 1 — APP_SECRET vom Quell-Host übernehmen (empfohlen bei Migration):**
```bash
# Auf Quell-Host
grep APP_SECRET /var/www/isms/.env.local
# Output: APP_SECRET=abc123...

# Auf Ziel-Host
echo "APP_SECRET=abc123..." >> .env.local
php bin/console cache:clear
```

**Option 2 — Verschlüsselte SystemSettings beim Restore überspringen:**
```bash
# Via RestoreService-Options: 'skip_entities' => ['SystemSettings']
# Oder Admin-UI-Checkbox "SystemSettings überspringen" (falls gesetzt).
# Nach Restore: Admin > Einstellungen > SystemSettings manuell befüllen.
```

**Option 3 — Backup ohne verschlüsselte Felder erstellen (bei bekannten Problemen):**
```bash
# Wenn BackupEncryptionService nicht konfiguriert ist, werden Felder
# unverschlüsselt gespeichert. Backup dann auf sicherem Kanal übertragen.
```

---

## 5. Integritätsprüfung (SHA-256)

### Wie funktioniert der Integrity-Check?

Jedes Backup enthält in den Metadaten einen SHA-256-Hash über den gesamten `data`-Block:

```json
{
  "metadata": {
    "sha256": "e3b0c44298fc1c149afb...",
    ...
  }
}
```

Der Hash wird beim Erstellen des Backups berechnet (`hash('sha256', json_encode($backup['data']))`). Beim Restore wird der Hash **automatisch verifiziert** (`RestoreService::verifyIntegrity()`) — bei Mismatch bricht der Restore mit `RuntimeException: Backup integrity check failed: sha256 mismatch` ab. Legacy-Backups ohne `sha256` laufen weiter und erzeugen eine Warnung im Ergebnis. Ops können zusätzlich manuell per Script prüfen (siehe unten).

### Manuelle Integritätsprüfung

```bash
# ZIP-Backup: zuerst extrahieren
unzip -p var/backups/backup_2026-04-24_03-00-00.zip backup.json > /tmp/backup.json

# SHA-256 aus Metadaten lesen
EXPECTED=$(python3 -c "
import json, sys
d = json.load(open('/tmp/backup.json'))
print(d['metadata'].get('sha256', 'nicht vorhanden'))
")
echo "Erwarteter Hash: $EXPECTED"

# SHA-256 des data-Blocks berechnen
ACTUAL=$(python3 -c "
import json, hashlib
d = json.load(open('/tmp/backup.json'))
h = hashlib.sha256(json.dumps(d['data'], separators=(',',':')).encode()).hexdigest()
print(h)
")
echo "Berechneter Hash: $ACTUAL"

# Vergleich
if [ "$EXPECTED" = "$ACTUAL" ]; then
  echo "INTEGRITAET OK"
else
  echo "WARNUNG: Hash-Mismatch — Backup moeglicherweise beschaedigt!"
fi
```

> **Hinweis:** Die json_encode-Serialisierung in PHP und Python kann bei Unicode-Escaping abweichen. Falls der Hash nicht übereinstimmt, trotzdem Dry-Run versuchen — die eigentlichen Daten können intakt sein.

### Was tun bei SHA-256-Mismatch?

| Ursache | Abhilfe |
|---------|---------|
| Backup bei Übertragung beschädigt (FTP binary mode vergessen) | Backup neu herunterladen / neu übertragen |
| Backup-Datei manuell editiert | Originales Backup verwenden |
| Python/PHP JSON-Serialisierungsunterschied | Dry-Run trotzdem versuchen |
| Echter Datenfehler im Backup | Älteres Backup verwenden |

---

## 6. Schema-Versionen und Kompatibilität

### Unterstützte Backup-Versionen

| Backup-Format | Beschreibung | Wiederherstellbar |
|---------------|--------------|-------------------|
| `1.0` | Legacy JSON-only (kein `schema_version`) | Ja (mit Warnung) |
| `2.0` | ZIP + Dateien + `schema_version` + `app_version` | Ja |
| Zukünftige Versionen > 2.x | Abhängig von Implementierung | Nein (explizite Ablehnung) |

### Schema-Versionswarnung

Das System prüft die `schema_version` aus den Backup-Metadaten gegen die aktuelle Datenbank-Migration:

```
Schema version mismatch: backup was created with schema "20260420140000",
current schema is "20260424120000". Some fields may be missing or incompatible.
```

Diese Warnung ist **nicht blockierend** — der Restore wird trotzdem versucht. Fehlende Felder werden mit Standardwerten belegt (`STRATEGY_USE_DEFAULT`).

### Vorgehen bei Schema-Mismatch

```bash
# Option 1: Schema auf Backup-Stand zurückrollen (bei Rollback-Szenario)
php bin/console doctrine:migrations:execute \
  'DoctrineMigrations\Version20260420140000' --down

# Option 2: Backup mit aktueller Schema-Version erstellen und neu einlesen
# (bei Migration auf neueres Schema)
# → Daten in aktuellem System lassen, kein Restore nötig

# Option 3: Fehlende Felder manuell nachpflegen
# → Dry-Run zeigt, welche Felder betroffen sind (Warnungen auslesen)
```

---

## 7. Häufige Fehler und Behebung

| Fehlermeldung | Ursache | Behebung |
|--------------|---------|----------|
| `FK constraint violation` | Migrations nicht angewendet | `php bin/console doctrine:migrations:migrate --no-interaction` |
| `Cannot assign null to property` | Veraltetes Backup (vor Fix 89ade1d3) | Restore mit `missing_field_strategy: use_default` |
| `SHA256 mismatch` | Backup beschädigt oder manuell bearbeitet | Frisches Backup verwenden; Dry-Run versuchen |
| `Unsupported backup version: 3.0` | Backup mit zukünftiger App-Version erstellt | App aktualisieren oder Backup manuell konvertieren |
| `Encrypted secret could not be decrypted` | APP_SECRET stimmt nicht überein | Siehe [Abschnitt 4](#4-app_secret-bei-wiederherstellung) |
| `EntityManager is closed` | DB-Constraint-Verletzung während Restore | Option "Bestehende Daten löschen" aktivieren und erneut versuchen |
| `ZIP backup does not contain backup.json` | Beschädigte ZIP-Datei | Backup neu herunterladen |
| `Cannot decompress backup: ext-zlib not available` | PHP-Extension fehlt | `apt install php8.4-zip` / `yum install php-zlib` |
| `Backup file not found` | Falscher Pfad oder Datei gelöscht | Backup erneut hochladen oder von Backup-Storage kopieren |
| Schema-Version-Warnung | Backup älter/neuer als aktuelles Schema | Nicht blockierend — Felder mit Defaults befüllen |
| `Cross-Tenant-Restore-Warnung` | Backup-Tenant != Ziel-Tenant | Bei Absicht: ignorieren; sonst falsches Backup gewählt |

### Fehler-Diagnose-Befehle

```bash
# Syntax-Check PHP-Dateien
find src -name "*.php" -print0 | xargs -0 -n1 php -l

# Service-Konfiguration prüfen
php bin/console lint:container

# DB-Schema vs. Entities prüfen
php bin/console doctrine:schema:validate

# Schema abgleichen (non-destructive)
php bin/console app:schema:reconcile --dry-run
php bin/console app:schema:reconcile

# Log-Ausgabe (letzten 100 Zeilen)
tail -100 var/log/prod.log | grep -E "ERROR|backup|restore"

# Cache leeren nach Problemen
php bin/console cache:clear --env=prod
```

---

## 8. Backup-Testplan (monatliches Drill)

### Empfohlener Zeitplan

| Aktion | Häufigkeit | Verantwortlich |
|--------|-----------|----------------|
| Automatisches Backup erstellen | Täglich (Cron via HTTP oder mysqldump) | Ops |
| Backup-Datei in externen Speicher kopieren | Täglich | Ops |
| Restore-Drill in Staging | Monatlich | ISMS-Admin |
| Vollständiger Disaster-Recovery-Test | Quartalsweise | ISMS-Admin + IT |
| Dokumentation aktualisieren | Bei App-Updates | ISMS-Admin |

### Monatlicher Restore-Drill — Checkliste

```bash
# 1. Staging-Umgebung bereitstellen (separate DB, separater Host)
# 2. Aktuellstes Backup von Produktion in Staging kopieren
# 3. Validierung durchführen:
#    Admin-UI Staging > Backup hochladen > Validieren
# 4. Dry-Run in Staging:
#    Admin-UI > Wiederherstellen > Dry-Run: JA
#    → Alle Warnungen und Fehler dokumentieren
# 5. Echten Restore in Staging (Bestehende Daten löschen: JA):
#    Admin-UI > Wiederherstellen > Dry-Run: NEIN
# 6. Funktionsprüfung nach Restore:
#    - Anmeldung möglich (temporäres Passwort)
#    - Risiken, Assets, Compliance-Daten vorhanden
#    - Dokumente abrufbar (falls ZIP-Backup)
#    - Audit-Log vorhanden (falls eingeschlossen)
# 7. Ergebnis im ISMS-Dokument festhalten (ISO 27001 Clause 8.7)
```

### RTO / RPO Orientierungswerte

| Szenario | Erwartetes RTO | Erwartetes RPO |
|----------|---------------|---------------|
| Rollback gleicher Host | 15–30 Minuten | Letztes Backup (täglich = max. 24h) |
| Frische DB gleicher Host | 30–60 Minuten | Letztes Backup |
| Serverumzug | 2–4 Stunden | Letztes Backup |
| Tenant-selektiver Restore | 15–30 Minuten | Letztes Backup |

---

## Verwandte Dokumente

- [BACKUP_ARCHITECTURE.md](BACKUP_ARCHITECTURE.md) — technische Referenz für Entwickler
- [docs/WORKFLOW_REQUIREMENTS.md](../WORKFLOW_REQUIREMENTS.md) — Regulatory Workflow Anforderungen
- [docs/setup/AUDIT_LOGGING.md](../setup/AUDIT_LOGGING.md) — Audit-Log Konfiguration
