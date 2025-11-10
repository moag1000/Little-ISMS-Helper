# Deployment auf Strato/Plesk - Fehlerbehebung

## Problem: "Primary script unknown" Fehler

Dieser Fehler tritt auf, wenn Apache/PHP-FPM die PHP-Dateien nicht finden kann. Dies ist ein häufiges Problem bei Symfony-Anwendungen auf Plesk, da das Document Root nicht korrekt konfiguriert ist.

## Lösung

### Schritt 1: Document Root in Plesk anpassen

Der wichtigste Schritt: Das Document Root muss auf das `public` Verzeichnis zeigen!

1. **In Plesk einloggen**
2. **Domain auswählen**
3. **"Hosting-Einstellungen" öffnen**
4. **Document Root ändern:**
   ```
   Standardmäßig: /httpdocs
   Ändern zu: /httpdocs/public
   ```
   ODER wenn Sie die Anwendung in einem Unterverzeichnis installiert haben:
   ```
   /httpdocs/little-isms-helper/public
   ```

5. **Speichern**

### Schritt 2: .htaccess Datei platzieren

Die `.htaccess` Datei muss im `public` Verzeichnis liegen (nicht im Root-Verzeichnis).

**Pfad:** `public/.htaccess`

Diese Datei wurde bereits erstellt und sollte folgendes enthalten:
- Rewrite-Regeln für Symfony
- Sicherheitseinstellungen
- Front Controller Handling

### Schritt 3: Dateirechte überprüfen

Stellen Sie sicher, dass die Dateien die richtigen Berechtigungen haben:

```bash
# Via SSH (falls verfügbar)
chmod -R 755 /var/www/vhosts/yourdomain.com/httpdocs
chmod -R 775 var/cache var/log
```

Oder in Plesk:
1. **Dateimanager öffnen**
2. **Rechte für Verzeichnisse:**
   - `var/cache`: 775
   - `var/log`: 775
   - Alle anderen: 755

### Schritt 4: PHP-Einstellungen in Plesk prüfen

1. **PHP-Einstellungen öffnen**
2. **PHP-Version prüfen:** Mindestens PHP 8.2
3. **PHP-Handler:** "FPM durch Apache bedient" auswählen
4. **Erforderliche PHP-Erweiterungen aktivieren:**
   - pdo
   - pdo_pgsql (oder pdo_mysql, je nach Datenbank)
   - intl
   - zip
   - opcache
   - mbstring
   - xml
   - soap

### Schritt 5: Composer Dependencies installieren

Via SSH (falls verfügbar):
```bash
cd /var/www/vhosts/yourdomain.com/httpdocs
composer install --no-dev --optimize-autoloader
```

Oder via Plesk SSH Terminal:
1. **"SSH-Zugang" in Plesk öffnen**
2. **Terminal öffnen**
3. **Befehle ausführen**

⚠️ **WICHTIG:** Nach `composer install` sollten die folgenden Scripts automatisch ausgeführt werden:
- `cache:clear` - Cache leeren
- `assets:install` - Assets ins public-Verzeichnis kopieren
- `importmap:install` - JavaScript-Dependencies installieren

**Falls die Scripts nicht automatisch liefen oder Fehler auftraten, führen Sie manuell aus:**
```bash
php bin/console cache:clear
php bin/console assets:install public
php bin/console importmap:install
```

**Verifizieren Sie, dass die Assets installiert wurden:**
```bash
ls -la public/assets/
# Sollte Verzeichnisse wie app/, vendor/, etc. zeigen
```

**Symptom wenn Assets fehlen:**
- Browser-Konsole zeigt: "Failed to load resource: 500 Internal Server Error" für CSS/JS-Dateien
- Seiten laden, aber ohne Styling
- CSS-Dateien wie `components-*.css` und `app-*.css` geben 500-Fehler zurück

### Schritt 6: Umgebungsvariablen setzen ⚠️ KRITISCH!

**WICHTIG:** Dieser Schritt ist ZWINGEND ERFORDERLICH, sonst erhalten Sie den Fehler:
```
Class "Symfony\Bundle\DebugBundle\DebugBundle" not found
```

#### Option A: .env.local Datei erstellen (EMPFOHLEN)

Erstellen Sie eine `.env.local` Datei im Root-Verzeichnis (neben `.env`):

```bash
# Kopieren Sie die Vorlage
cp .env.prod.example .env.local

# Bearbeiten Sie die Datei und setzen Sie:
```

**Minimale .env.local für Produktion:**
```
APP_ENV=prod
APP_SECRET=CHANGE_ME_TO_A_SECURE_RANDOM_STRING
DATABASE_URL="mysql://user:password@localhost:3306/database?serverVersion=8.0&charset=utf8mb4"
```

**APP_SECRET generieren:**
```bash
# Via SSH:
openssl rand -hex 32

# Oder:
php -r 'echo bin2hex(random_bytes(32))."\n";'
```

#### Option B: Apache Umgebungsvariablen in Plesk

Alternativ in Plesk:
1. **"Apache & nginx Einstellungen" öffnen**
2. **"Zusätzliche Apache-Anweisungen" Abschnitt**
3. **Umgebungsvariablen setzen:**

```apache
SetEnv APP_ENV prod
SetEnv APP_SECRET your-secret-key-here
SetEnv DATABASE_URL "mysql://user:password@localhost:3306/database?serverVersion=8.0&charset=utf8mb4"
```

⚠️ **ACHTUNG:** Ohne `APP_ENV=prod` wird Symfony im Dev-Modus laufen und DebugBundle laden wollen, was nicht installiert ist!

## Strato-spezifische Hinweise

### Datenbank-Konfiguration

Strato bietet in den meisten Tarifen PostgreSQL an:

1. **Plesk > Datenbanken**
2. **Neue Datenbank erstellen**
3. **Zugangsdaten notieren:**
   - Host: `localhost` oder `127.0.0.1`
   - Port: `5432` (PostgreSQL) oder `3306` (MySQL)
   - Datenbank-Name
   - Benutzername
   - Passwort

4. **DATABASE_URL in .env.local setzen:**
```
# PostgreSQL
DATABASE_URL="postgresql://username:password@localhost:5432/dbname?serverVersion=14&charset=utf8"

# MySQL (alternative)
DATABASE_URL="mysql://username:password@localhost:3306/dbname?serverVersion=8.0&charset=utf8mb4"
```

### SSH-Zugang aktivieren

Falls noch nicht aktiviert:
1. **Plesk > "Website & Domains"**
2. **"SSH-Zugang"**
3. **"/bin/bash" als Shell aktivieren**
4. **SSH aktivieren**

### Composer auf Strato

Strato-Server haben oft Composer bereits installiert. Prüfen Sie:
```bash
composer --version
```

Falls nicht vorhanden:
```bash
# Composer lokal installieren
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"

# Verwenden Sie dann:
php composer.phar install --no-dev --optimize-autoloader
```

## Fehlerbehebung

### Fehler: CSS/JS-Dateien werden nicht geladen (500 Error) ⚠️ HÄUFIG!

**Symptome:**
- Login-Seite lädt, aber ohne Styling
- Browser-Konsole zeigt:
  ```
  Failed to load resource: the server responded with a status of 500 (Internal Server Error)
  components-*.css:1
  app-*.css:1
  ```
- nginx/Apache Error-Log zeigt:
  ```
  openat() "/var/www/vhosts/domain.com/path/favicon.svg" failed (2: No such file or directory)
  ```

**Ursache:**
Die Symfony AssetMapper-Assets wurden nicht installiert. Das `public/assets/` Verzeichnis fehlt oder ist leer.

**Lösung:**

1. **Prüfen Sie, ob das Assets-Verzeichnis existiert:**
   ```bash
   ls -la public/assets/
   # Wenn Fehler "No such file or directory" → Assets fehlen!
   ```

2. **Assets manuell installieren:**
   ```bash
   cd /var/www/vhosts/yourdomain.com/httpdocs
   php bin/console assets:install public
   php bin/console importmap:install
   ```

3. **Cache leeren:**
   ```bash
   php bin/console cache:clear --env=prod
   ```

4. **Dateirechte prüfen:**
   ```bash
   chmod -R 755 public/assets
   ```

5. **Seite neu laden** - CSS und JS sollten jetzt laden!

**Prävention:**
Nach jedem `composer install` sollten die Post-Install-Scripts automatisch laufen. Falls nicht, führen Sie manuell aus:
```bash
composer run-script auto-scripts
```

### Fehler: "Option FollowSymlinks not allowed here" ⚠️ HÄUFIG!

**Symptome:**
- Apache Error-Log zeigt:
  ```
  [core:alert] /var/www/.../public/.htaccess: Option FollowSymlinks not allowed here
  ```
- 500 Internal Server Error beim Aufruf der Seite
- Eventuell "Internal Server Error" ohne weitere Details

**Ursache:**
Plesk und viele Shared-Hosting-Umgebungen verbieten aus Sicherheitsgründen die Direktive `Options +FollowSymlinks` in `.htaccess` Dateien. Die `AllowOverride` Einstellung ist auf dem Server eingeschränkt.

**Lösung:**

1. **Öffnen Sie `public/.htaccess`**

2. **Suchen Sie die Zeile:**
   ```apache
   Options +FollowSymlinks
   ```

3. **Ersetzen Sie sie durch:**
   ```apache
   Options +SymLinksIfOwnerMatch
   ```

4. **Seite neu laden** - Der Fehler sollte behoben sein!

**Erklärung:**
- `+FollowSymlinks` erlaubt das Folgen aller symbolischen Links (Sicherheitsrisiko)
- `+SymLinksIfOwnerMatch` erlaubt nur Links, die demselben Benutzer gehören (sicher)

Diese Änderung ist bereits in der neuesten Version der `.htaccess` enthalten.

**Alternative (falls das nicht funktioniert):**
Falls auch `SymLinksIfOwnerMatch` nicht erlaubt ist, können Sie die Zeile komplett auskommentieren:
```apache
# Options +SymLinksIfOwnerMatch
```
**Achtung:** Dies kann in seltenen Fällen zu Problemen mit RewriteRules führen. Testen Sie nach der Änderung!

### Fehler: "Class 'Symfony\Bundle\DebugBundle\DebugBundle' not found" ⚠️ HÄUFIG!

**Fehlermeldung im Apache Error Log:**
```
PHP message: 2025-11-10T21:08:09+00:00 [critical] Uncaught Error:
Class "Symfony\Bundle\DebugBundle\DebugBundle" not found
```

**Ursache:**
Die Anwendung läuft im Dev-Modus (`APP_ENV=dev`), aber die Dev-Dependencies wurden nicht installiert (weil `composer install --no-dev` ausgeführt wurde).

**Lösung:**

1. **Erstellen Sie eine `.env.local` Datei** (falls noch nicht vorhanden):
   ```bash
   cd /var/www/vhosts/yourdomain.com/httpdocs
   cp .env.prod.example .env.local
   ```

2. **Setzen Sie APP_ENV auf prod:**
   ```
   APP_ENV=prod
   APP_SECRET=your-secure-random-string-here
   DATABASE_URL="mysql://user:pass@localhost:3306/dbname?serverVersion=8.0&charset=utf8mb4"
   ```

3. **Cache leeren:**
   ```bash
   php bin/console cache:clear --env=prod
   ```

4. **Seite neu laden** - Der Fehler sollte weg sein!

**Prüfen Sie die aktuelle Umgebung:**
```bash
# Via SSH
php bin/console about

# Sollte zeigen: Environment: prod
```

**Alternative:** Wenn Sie `.env.local` nicht verwenden möchten, setzen Sie die Umgebungsvariable in Plesk:
- "Apache & nginx Einstellungen" → "Zusätzliche Apache-Anweisungen"
- Fügen Sie hinzu: `SetEnv APP_ENV prod`

### Fehler: "Primary script unknown" bleibt bestehen

**Mögliche Ursachen:**

1. **Document Root falsch:**
   - Prüfen Sie nochmals: Muss auf `public` Verzeichnis zeigen!
   - Pfad sollte sein: `/httpdocs/public` NICHT `/httpdocs`

2. **SCRIPT_FILENAME Variable falsch:**

   Fügen Sie in "Zusätzliche Apache-Anweisungen" hinzu:
   ```apache
   <FilesMatch \.php$>
       SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1
   </FilesMatch>
   ```

3. **.htaccess wird nicht geladen:**

   Prüfen Sie in "Apache & nginx Einstellungen":
   - "Allow web users to use .htaccess" muss aktiviert sein

   Oder fügen Sie in "Zusätzliche Apache-Anweisungen" hinzu:
   ```apache
   <Directory /var/www/vhosts/yourdomain.com/httpdocs/public>
       AllowOverride All
       Require all granted
   </Directory>
   ```

4. **Datei-Pfade stimmen nicht:**

   Prüfen Sie via SSH die tatsächlichen Pfade:
   ```bash
   ls -la /var/www/vhosts/yourdomain.com/httpdocs/public/
   # Sollte index.php anzeigen

   cat /var/log/apache2/error_log | grep "Primary script"
   # Zeigt den Pfad, den Apache sucht
   ```

### Fehler: "500 Internal Server Error"

1. **Fehlerlog prüfen:**
   ```bash
   tail -f /var/log/apache2/error_log
   ```

2. **Symfony Cache leeren:**
   ```bash
   php bin/console cache:clear --env=prod
   chmod -R 775 var/cache var/log
   ```

3. **PHP Memory Limit erhöhen:**

   In Plesk > "PHP-Einstellungen":
   - memory_limit: 256M (oder höher)
   - max_execution_time: 300

### Fehler: "Permission denied"

```bash
# Besitzer auf Web-Server User setzen
chown -R <plesk-user>:psacln /var/www/vhosts/yourdomain.com/httpdocs
chmod -R 755 /var/www/vhosts/yourdomain.com/httpdocs
chmod -R 775 var/cache var/log
```

Ersetzen Sie `<plesk-user>` mit Ihrem Plesk-Benutzernamen (z.B. aus `ls -la` ersichtlich).

### Fehler: Database connection failed

1. **Prüfen Sie die DATABASE_URL**
2. **Testen Sie die Verbindung:**
   ```bash
   php bin/console doctrine:query:sql "SELECT 1"
   ```

3. **Stellen Sie sicher, dass der DB-Server läuft und erreichbar ist**

## Symfony-spezifische Schritte nach Deployment

Nach dem ersten Deployment:

```bash
# 1. Migrationen ausführen
php bin/console doctrine:migrations:migrate --no-interaction

# 2. Assets installieren
php bin/console assets:install --symlink

# 3. Cache aufwärmen
php bin/console cache:warmup --env=prod

# 4. Deployment Wizard aufrufen (nur beim ersten Mal)
# Öffnen Sie im Browser: https://yourdomain.com/setup
```

## Checkliste

**⚡ QUICK CHECK:** Führen Sie das automatische Check-Script aus:
```bash
./deployment-check.sh
```

Dieses Script überprüft automatisch alle kritischen Punkte unten und gibt detailliertes Feedback.

**Manuelle Checkliste:**
- [ ] Document Root auf `public` Verzeichnis gesetzt
- [ ] .htaccess Datei in `public` Verzeichnis vorhanden
- [ ] PHP-Version >= 8.2
- [ ] Alle PHP-Erweiterungen aktiviert
- [ ] Composer Dependencies installiert (`composer install --no-dev --optimize-autoloader`)
- [ ] ⚠️ **KRITISCH:** Assets installiert (Verzeichnis `public/assets/` existiert)
- [ ] ⚠️ **KRITISCH:** `.env.local` mit `APP_ENV=prod` erstellt
- [ ] APP_SECRET mit sicherem zufälligen String gesetzt
- [ ] DATABASE_URL korrekt konfiguriert
- [ ] Dateirechte korrekt gesetzt (755/775)
- [ ] Datenbank erstellt und erreichbar
- [ ] Migrations ausgeführt
- [ ] Cache geleert und aufgewärmt (`php bin/console cache:clear --env=prod`)
- [ ] Umgebung verifiziert (`php bin/console about` zeigt "Environment: prod")

## Zusätzliche Optimierungen für Produktion

### OPcache aktivieren

In Plesk > "PHP-Einstellungen":
- opcache.enable = On
- opcache.memory_consumption = 256
- opcache.max_accelerated_files = 20000
- opcache.validate_timestamps = 0 (in Production)

### HTTPS erzwingen

In "Apache & nginx Einstellungen":
```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

Oder aktivieren Sie "Permanent SEO-sichere 301-Weiterleitung von HTTP auf HTTPS" in Plesk.

### Sicherheit

1. **Setup-Routen blockieren nach Ersteinrichtung:**

   In `.htaccess` im `public` Verzeichnis hinzufügen:
   ```apache
   # Block setup routes in production
   RewriteRule ^setup - [F,L]
   ```

2. **.env Dateien schützen:**
   ```apache
   <FilesMatch "^\.env">
       Require all denied
   </FilesMatch>
   ```

## Support

Bei weiteren Problemen:

1. **Strato Support kontaktieren** für Server-spezifische Fragen
2. **Symfony Logs prüfen:** `var/log/prod.log`
3. **Apache Logs prüfen:** Via Plesk > "Logs"

## Zusammenfassung

Die vier häufigsten Fehler bei Plesk-Deployment:

1. **CSS/JS-Dateien laden nicht (500 Error)**
   - **Ursache:** Assets wurden nicht installiert, `public/assets/` fehlt
   - **Lösung:** `php bin/console assets:install public && php bin/console importmap:install`

2. **"Option FollowSymlinks not allowed here"**
   - **Ursache:** Plesk verbietet `Options +FollowSymlinks` in `.htaccess`
   - **Lösung:** In `public/.htaccess` ändern zu `Options +SymLinksIfOwnerMatch`

3. **"Primary script unknown"**
   - **Ursache:** Document Root zeigt nicht auf `public` Verzeichnis
   - **Lösung:** Document Root auf `/httpdocs/public` setzen

4. **"Class 'DebugBundle' not found"**
   - **Ursache:** `APP_ENV` ist nicht auf `prod` gesetzt
   - **Lösung:** `.env.local` mit `APP_ENV=prod` erstellen

**Kritische Schritte:**
- Document Root → `public` Verzeichnis
- `.htaccess` → `Options +SymLinksIfOwnerMatch` verwenden (Plesk-kompatibel)
- `.env.local` → `APP_ENV=prod`
- Composer → `--no-dev` Flag verwenden
- **Assets installieren** → `php bin/console assets:install public` + `importmap:install`
- Cache leeren → `php bin/console cache:clear --env=prod`

Ohne diese Schritte funktioniert die Symfony-Anwendung nicht auf Plesk.
