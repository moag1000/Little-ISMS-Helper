# Deployment auf Strato/Plesk - Fehlerbehebung

## ⚠️ KRITISCH: Document Root MUSS auf `public/` zeigen! ⚠️

**OHNE diesen Schritt funktioniert die gesamte Anwendung NICHT!**

Die häufigste Ursache für "500 Internal Server Error", "Primary script unknown" und andere Deployment-Probleme ist ein **falsch konfiguriertes Document Root** in Plesk.

**❌ FALSCH:** `/httpdocs` oder `/httpdocs/little-isms-helper`
**✅ RICHTIG:** `/httpdocs/public` oder `/httpdocs/little-isms-helper/public`

Symfony-Anwendungen **müssen** auf das `public/` Verzeichnis zeigen, da dort:
- Der Front Controller `index.php` liegt
- Die `.htaccess` mit Rewrite-Regeln liegt
- Alle öffentlichen Assets (CSS, JS, Bilder) liegen
- Sicherheitskritische Dateien (`.env`, `config/`, `src/`) NICHT zugänglich sind

**Bitte prüfen Sie ZUERST diesen Punkt, bevor Sie mit anderen Schritten fortfahren!**

---

## Problem: "Primary script unknown" Fehler

Dieser Fehler tritt auf, wenn Apache/PHP-FPM die PHP-Dateien nicht finden kann. Dies ist ein häufiges Problem bei Symfony-Anwendungen auf Plesk, da das Document Root nicht korrekt konfiguriert ist.

## Lösung

### Schritt 1: Document Root in Plesk anpassen ⚠️ ZWINGEND ERFORDERLICH!

**Dies ist der wichtigste und kritischste Schritt des gesamten Deployments!**

Das Document Root **MUSS** auf das `public` Verzeichnis zeigen!

**Detaillierte Anleitung:**

1. **In Plesk einloggen**
   - Öffnen Sie Ihr Plesk-Panel (z.B. https://yourdomain.com:8443)

2. **Domain auswählen**
   - Klicken Sie in der Seitenleiste auf "Websites & Domains"
   - Wählen Sie die Domain aus (z.B. little-isms-helper.banda-dismar.de)

3. **"Hosting-Einstellungen" öffnen**
   - Klicken Sie auf "Hosting-Einstellungen" (manchmal auch "Apache & nginx-Einstellungen")

4. **Document Root ändern:**

   **VORHER (FALSCH):**
   ```
   Document Root: /httpdocs
   ```
   oder
   ```
   Document Root: /httpdocs/little-isms-helper
   ```

   **NACHHER (RICHTIG):**
   ```
   Document Root: /httpdocs/public
   ```
   oder wenn die Anwendung in einem Unterverzeichnis liegt:
   ```
   Document Root: /httpdocs/little-isms-helper/public
   ```

   **⚠️ WICHTIG:** Vergessen Sie NICHT das `/public` am Ende!

5. **"OK" oder "Übernehmen" klicken**
   - Warten Sie, bis Plesk die Konfiguration neu lädt (kann 5-10 Sekunden dauern)

6. **Verifizieren:**
   - Öffnen Sie https://yourdomain.com
   - Sie sollten jetzt die Symfony-Anwendung sehen (ggf. noch ohne Styling, wenn Assets fehlen)
   - Bei FALSCHEM Document Root sehen Sie: "403 Forbidden" oder "Primary script unknown"

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
- `assets:install` - Assets aus Bundles kopieren (z.B. WebProfiler)
- `importmap:install` - JavaScript-Dependencies installieren

**Falls die Scripts nicht automatisch liefen oder Fehler auftraten, führen Sie manuell aus:**
```bash
php bin/console cache:clear
php bin/console importmap:install

# FÜR PRODUKTION: AssetMapper Assets kompilieren (KRITISCH!)
php bin/console asset-map:compile
```

**Was macht `asset-map:compile`?**
- Nimmt alle Assets aus `assets/` (CSS, JS)
- Erstellt versionierte Kopien in `public/assets/` (z.B. app-6zxcGag.css)
- Generiert manifest.json und importmap.json
- **Ohne diesen Schritt gibt es KEINE CSS/JS-Dateien in Produktion!**

**Verifizieren Sie, dass die Assets installiert wurden:**
```bash
ls -la public/assets/
# Sollte Verzeichnisse wie app/, vendor/, styles/, und viele *.css/*.js Dateien zeigen

# Prüfen Sie speziell:
ls -la public/assets/styles/
# Sollte app-HASH.css, components-HASH.css, etc. zeigen
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
  https://domain.com/assets/styles/components-6yrXj4J.css
  https://domain.com/assets/styles/app-6zxcGag.css
  ```
- Die URLs enthalten Versions-Hashes (z.B. `6yrXj4J`, `6zxcGag`)
- nginx/Apache Error-Log kann zeigen:
  ```
  openat() "/var/www/vhosts/domain.com/path/assets/..." failed
  ```

**Ursache:**
Die Symfony AssetMapper-Assets wurden nicht installiert. Das `public/assets/` Verzeichnis fehlt oder ist leer.

**Technischer Hintergrund:**
- Symfony AssetMapper kompiliert Assets aus `assets/` → `public/assets/`
- CSS-Dateien werden mit Versions-Hashes benannt für Cache-Busting (z.B. `app-6zxcGag.css`)
- In Produktion müssen diese Dateien vorab generiert werden
- Wenn sie fehlen, versucht Symfony sie dynamisch zu generieren → 500 Error

**Lösung:**

1. **Prüfen Sie, ob das Assets-Verzeichnis existiert:**
   ```bash
   ls -la public/assets/
   # Wenn Fehler "No such file or directory" → Assets fehlen!
   ```

2. **Assets manuell installieren:**
   ```bash
   cd /var/www/vhosts/yourdomain.com/httpdocs

   # JavaScript-Dependencies installieren (lädt externe Pakete: Stimulus, Turbo, Chart.js)
   php bin/console importmap:install

   # AssetMapper Assets kompilieren (KRITISCH für Produktion!)
   php bin/console asset-map:compile
   ```

   **⚠️ WICHTIG - Reihenfolge beachten:**
   - `importmap:install` MUSS VOR `asset-map:compile` ausgeführt werden!
   - `importmap:install` lädt externe Pakete (Stimulus, Turbo, Chart.js) von jsDelivr herunter
   - `asset-map:compile` kompiliert dann ALLE Assets (inkl. externe Pakete) nach `public/assets/`
   - Erstellt versionierte Dateinamen (z.B. app-6zxcGag.css, stimulus-abc123.js)
   - **Ohne beide Befehle fehlen CSS/JS in Produktion!**

   **Hinweis:** Bootstrap Icons werden über CDN geladen (siehe base.html.twig), nicht über AssetMapper.

3. **Verifizieren Sie, dass die Dateien erstellt wurden:**
   ```bash
   ls -la public/assets/styles/
   # Sollte Dateien wie app-HASH.css, components-HASH.css zeigen

   ls -la public/assets/vendor/@hotwired/
   # Sollte stimulus/ und turbo/ Verzeichnisse zeigen

   ls -la public/assets/controllers/
   # Sollte alle Stimulus Controller zeigen (theme_controller.js, search_controller.js, etc.)
   ```

4. **Cache leeren:**
   ```bash
   php bin/console cache:clear --env=prod
   ```

5. **Dateirechte prüfen:**
   ```bash
   chmod -R 755 public/assets
   ```

6. **Seite neu laden** - CSS und JS sollten jetzt laden!

**Häufige Ursachen, warum Assets fehlen:**
- `composer install` wurde mit `--no-scripts` ausgeführt
- Post-Install-Scripts sind fehlgeschlagen (Berechtigungsprobleme)
- Nach Git-Pull wurde `composer install` vergessen
- **`importmap:install` wurde vergessen** → JavaScript-Pakete (Stimulus, Turbo, Chart.js) fehlen!

**Prävention:**
Nach jedem `composer install` sollten die Post-Install-Scripts automatisch laufen:
```bash
# Prüfen Sie, ob die Scripts ausgeführt wurden
composer run-script auto-scripts
```

**⚠️ Spezialfall: Bootstrap Icons fehlen**

**UPDATE:** Bootstrap Icons werden jetzt über CDN geladen und nicht mehr über AssetMapper!

**Aktueller Stand (seit letztem Update):**
Bootstrap Icons werden über CDN in `templates/base.html.twig` geladen:
```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
```

**Warum CDN?**
AssetMapper hat ein bekanntes Problem (GitHub Issue #52620) mit dem `fonts/` Unterordner von Bootstrap Icons. Die Font-Dateien werden nicht korrekt kopiert, was zu fehlenden Icons führt. Der CDN-Ansatz ist:
- ✅ Zuverlässig und funktioniert sofort
- ✅ Schnell (jsDelivr ist weltweit gecached)
- ✅ Mit SRI-Hash gesichert
- ✅ Kein Troubleshooting nötig
- ✅ CSP ist konfiguriert für jsDelivr (in `SecurityHeadersSubscriber.php`)

**Falls Bootstrap Icons trotzdem fehlen:**

**Symptom 1: CSP blockiert Bootstrap Icons Fonts**

Browser-Konsole zeigt:
```
Loading the font 'https://cdn.jsdelivr.net/.../bootstrap-icons.woff2' violates
the following Content Security Policy directive: "font-src 'self' data: ..."
```

**Lösung:** CSP muss jsDelivr für Fonts erlauben (bereits im Code enthalten):
```php
// src/EventSubscriber/SecurityHeadersSubscriber.php
"font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net"
```

Nach Git-Pull sollte das automatisch behoben sein. Falls nicht, prüfen Sie die Datei manuell.

**Symptom 2: CDN-Link fehlt**

1. **Prüfen Sie, ob der CDN-Link in base.html.twig vorhanden ist:**
   ```bash
   grep "bootstrap-icons" templates/base.html.twig
   # Sollte den CDN-Link zeigen
   ```

2. **Prüfen Sie, ob der alte Import auskommentiert ist:**
   ```bash
   grep "bootstrap-icons" assets/app.js
   # Sollte auskommentiert sein: // import 'bootstrap-icons/...
   ```

3. **Cache leeren und Assets neu kompilieren:**
   ```bash
   rm -rf var/cache/prod/*
   php bin/console cache:clear --env=prod
   php bin/console asset-map:compile
   ```

4. **Browser Cache leeren:** Strg+F5 (oder Cmd+Shift+R)

**Kein Zugriff auf jsDelivr CDN?**
Falls Ihre Server-Firewall jsDelivr blockiert, können Sie Bootstrap Icons lokal in `assets/vendor/bootstrap-icons/` installieren und den Import-Pfad in `assets/app.js` anpassen. Siehe ältere Version dieser Dokumentation für Details.

### Fehler: Content Security Policy (CSP) blockiert Scripts ⚠️

**Symptome:**
- Browser-Konsole zeigt CSP-Fehler wie:
  ```
  Loading the script 'data:application/javascript,' violates the following
  Content Security Policy directive: "script-src 'self' 'unsafe-inline'..."
  ```
- Fehler kommen von verschiedenen Seiten (z.B. `analytics:1`)
- Source Maps (.map Dateien) werden blockiert (Warnungen, nicht kritisch)

**Ursache:**
Ein fehlerhafter JavaScript- oder CSS-Import versucht, ein Modul zu laden, das nicht existiert oder nicht aufgelöst werden kann. Dies erzeugt einen `data:application/javascript,` "URL", der von der CSP blockiert wird.

**Häufigste Ursachen:**
1. **Alte kompilierte Assets im Cache** - Nach Code-Änderungen (z.B. Bootstrap Icons Entfernung)
2. Ein Import in `importmap.php`, der nicht existiert
3. Ein fehlerhafter Import in einem Stimulus Controller
4. Assets wurden nicht neu kompiliert nach einem Git-Pull

**Lösung:**

```bash
cd /var/www/vhosts/yourdomain.com/httpdocs

# 1. Cache VOLLSTÄNDIG löschen (WICHTIG!)
rm -rf var/cache/prod/*
php bin/console cache:clear --env=prod

# 2. Alle Assets NEU kompilieren
php bin/console importmap:install
php bin/console asset-map:compile

# 3. Prüfen, dass keine fehlerhaften Imports existieren
cat public/assets/importmap.json | grep -i "data:"
# Sollte NICHTS finden!

# 4. Browser Cache leeren (Strg+F5 oder Cmd+Shift+R)
```

**Debug:**
```bash
# Alle JavaScript-Imports auflisten
php bin/console debug:asset-map --ext=js

# Prüfen Sie importmap.json auf Fehler
cat public/assets/importmap.json

# Suchen Sie nach CSS-Dateien, die JavaScript importieren (sollte es nicht geben!)
grep -r "@import.*\.js" assets/styles/
```

**⚠️ Hinweis zu Source Map Warnungen:**

Die Warnungen über blockierte `.map` Dateien sind **normal und unkritisch**:
```
Connecting to 'https://cdn.jsdelivr.net/.../bootstrap.min.css.map' violates CSP...
Connecting to 'https://cdn.jsdelivr.net/.../chart.umd.js.map' violates CSP...
```

Source Maps sind nur für Debugging gedacht und werden in Produktion nicht benötigt. Die CSP blockiert sie korrekt aus Sicherheitsgründen. Diese Warnungen können ignoriert werden!

**Kritisch ist nur:** `Loading the script 'data:application/javascript,'` → Das deutet auf einen fehlgeschlagenen Import hin!

### Fehler: JavaScript funktioniert nicht (Dark Mode, Suche, etc.) ⚠️ SEHR HÄUFIG!

**Symptome:**
- Dark Mode Toggle funktioniert nicht
- Globale Suche (⌘K) funktioniert nicht
- Command Palette (⌘P) öffnet nicht
- Dropdown-Menüs funktionieren nicht
- Diagramme/Charts werden nicht angezeigt
- Turbo (SPA-Navigation) funktioniert nicht
- Keine JavaScript-Fehler in der Konsole ODER viele "Failed to load module" Fehler

**Ursache:**
Die JavaScript-Dependencies (Stimulus, Turbo, Chart.js) wurden nicht installiert. Das passiert, wenn `php bin/console importmap:install` nicht ausgeführt wurde.

**Technischer Hintergrund:**
Diese Anwendung verwendet:
- **Stimulus** - für alle interaktiven Komponenten (Search, Dark Mode, Modals, etc.)
- **Turbo** - für SPA-ähnliche Navigation ohne Page-Reload
- **Chart.js** - für Diagramme im Analytics-Dashboard
- **Bootstrap JS** - für Dropdowns, Modals, etc.

Alle diese Pakete werden von jsDelivr heruntergeladen via `importmap:install` und dann von `asset-map:compile` nach `public/assets/vendor/` kompiliert.

**Diagnose:**

```bash
# 1. Prüfen Sie, ob JavaScript-Dependencies existieren
ls -la public/assets/vendor/@hotwired/
# Sollte stimulus/ und turbo/ Verzeichnisse mit .js-Dateien zeigen

ls -la public/assets/vendor/chart.js/
# Sollte chart.js Dateien zeigen

ls -la public/assets/vendor/bootstrap/
# Sollte bootstrap.js Dateien zeigen

# 2. Debug-Befehl für JavaScript-Dateien
php bin/console debug:asset-map --ext=js | head -50
# Sollte viele JavaScript-Dateien auflisten, inkl. @hotwired/stimulus, @hotwired/turbo, etc.

# 3. Browser-Konsole prüfen
# Öffnen Sie die Seite und drücken Sie F12 → Console
# Suchen Sie nach Fehlern wie:
# - "Failed to load module: @hotwired/stimulus"
# - "Uncaught TypeError: Cannot read property 'start' of undefined"
# - "net::ERR_FILE_NOT_FOUND" für .js-Dateien
```

**Lösung:**

```bash
cd /var/www/vhosts/yourdomain.com/httpdocs

# 1. Externe JavaScript-Pakete herunterladen (KRITISCH!)
php bin/console importmap:install

# 2. Alle Assets inkl. JavaScript kompilieren
php bin/console asset-map:compile

# 3. Verifizieren, dass die JS-Dateien erstellt wurden
ls -la public/assets/vendor/@hotwired/stimulus/
ls -la public/assets/vendor/@hotwired/turbo/
ls -la public/assets/vendor/chart.js/

# 4. Prüfen Sie die Stimulus Controller
ls -la public/assets/controllers/
# Sollte alle *_controller.js Dateien zeigen:
# - search_controller.js
# - theme_controller.js (Dark Mode)
# - command_palette_controller.js
# - etc.

# 5. Cache leeren
php bin/console cache:clear --env=prod

# 6. Browser Cache leeren und Seite neu laden (Strg+F5)
```

**Verifizieren Sie, dass es funktioniert:**

1. **Öffnen Sie die Seite**
2. **Drücken Sie F12 → Console**
3. **Sollte KEINE Fehler zeigen** (außer evtl. Warnungen)
4. **Testen Sie:**
   - Drücken Sie `⌘K` (oder `Ctrl+K`) → Suche sollte öffnen
   - Klicken Sie auf den Dark Mode Toggle → Sollte Theme wechseln
   - Drücken Sie `?` → Keyboard Shortcuts Hilfe sollte öffnen

**Falls es immer noch nicht funktioniert:**

```bash
# Prüfen Sie die importmap.json
cat public/assets/importmap.json | grep stimulus
# Sollte Zeilen mit @hotwired/stimulus zeigen

# Prüfen Sie die manifest.json
cat public/assets/manifest.json | grep stimulus
# Sollte die kompilierten Stimulus-Dateien mit Hashes zeigen

# Debug-Ausgabe für ein spezifisches Asset
php bin/console debug:asset-map @hotwired/stimulus
# Sollte Details über das Stimulus-Asset zeigen
```

**Häufige Ursachen:**

1. **`importmap:install` wurde nie ausgeführt** → Externe Pakete fehlen komplett
2. **`asset-map:compile` wurde vor `importmap:install` ausgeführt** → Falsche Reihenfolge!
3. **Berechtigungsfehler während `importmap:install`** → Download fehlgeschlagen
4. **Firewall blockiert jsDelivr** → Externe Pakete können nicht heruntergeladen werden
5. **Browser Cache** → Alte, fehlerhafte Version wird noch geladen

**Kritische Reihenfolge:**
```bash
# RICHTIG:
php bin/console importmap:install    # 1. Externe Pakete laden
php bin/console asset-map:compile    # 2. Alles kompilieren

# FALSCH:
php bin/console asset-map:compile    # Kompiliert ohne externe Pakete!
php bin/console importmap:install    # Zu spät!
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
# 1. JavaScript-Dependencies installieren (lädt Bootstrap Icons + andere externe Pakete!)
php bin/console importmap:install

# 2. AssetMapper Assets kompilieren (KRITISCH!)
php bin/console asset-map:compile

# 3. Migrationen ausführen
php bin/console doctrine:migrations:migrate --no-interaction

# 4. Bundle-Assets installieren (optional, für Dev-Bundles)
php bin/console assets:install public

# 5. Cache aufwärmen
php bin/console cache:warmup --env=prod

# 6. Deployment Wizard aufrufen (nur beim ersten Mal)
# Öffnen Sie im Browser: https://yourdomain.com/setup
```

**⚠️ WICHTIG - Reihenfolge beachten:**
- **Schritt 1 MUSS VOR Schritt 2 ausgeführt werden!**
  - `importmap:install` lädt externe Pakete (Bootstrap Icons, Chart.js, etc.) von jsDelivr herunter
  - Ohne Schritt 1 fehlen Bootstrap Icons und andere externe Dependencies!
- **Schritt 2 MUSS NACH Schritt 1 ausgeführt werden!**
  - `asset-map:compile` kompiliert ALLE Assets (inkl. heruntergeladene externe Pakete)
  - Erstellt versionierte Dateien in `public/assets/`
- **Ohne Schritte 1 + 2 gibt es KEINE CSS/JS/Icons in Produktion!**
- Schritt 3 erstellt/updated die Datenbank
- Schritt 4 ist optional (nur wenn Sie Dev-Bundles verwenden)
- Schritt 5 verbessert die Performance

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

### Die vier häufigsten Fehler bei Plesk-Deployment (in Reihenfolge der Wichtigkeit):

**⚠️ 1. Document Root zeigt NICHT auf `public/` Verzeichnis** (BLOCKIERT ALLES!)
   - **Ursache:** Plesk ist standardmäßig auf `/httpdocs` konfiguriert
   - **Symptome:** "Primary script unknown", 403 Forbidden, komplett leere Seite
   - **Lösung:** In Plesk Hosting-Einstellungen Document Root auf `/httpdocs/public` ändern
   - **⚠️ OHNE diesen Schritt funktioniert GAR NICHTS!**

**2. "Class 'DebugBundle' not found"**
   - **Ursache:** `APP_ENV` ist nicht auf `prod` gesetzt, aber Dependencies mit `--no-dev` installiert
   - **Symptome:** 500 Error, Fehler im Apache-Log
   - **Lösung:** `.env.local` mit `APP_ENV=prod` erstellen

**3. "Option FollowSymlinks not allowed here"**
   - **Ursache:** Plesk verbietet `Options +FollowSymlinks` in `.htaccess`
   - **Symptome:** 500 Error, Apache-Log zeigt Alert
   - **Lösung:** In `public/.htaccess` ändern zu `Options +SymLinksIfOwnerMatch`

**4. CSS/JS-Dateien laden nicht (500 Error)**
   - **Ursache:** AssetMapper-Assets wurden nicht kompiliert, `public/assets/` fehlt
   - **Symptome:** Seite lädt, aber ohne Styling
   - **Lösung:** `php bin/console importmap:install && php bin/console asset-map:compile`

### Kritische Deployment-Checkliste (in dieser Reihenfolge!):

1. **⚠️ ZUERST: Document Root → `/httpdocs/public` setzen** (ZWINGEND!)
2. `.htaccess` → `Options +SymLinksIfOwnerMatch` verwenden (Plesk-kompatibel)
3. `.env.local` → `APP_ENV=prod` + `APP_SECRET` + `DATABASE_URL` setzen
4. Composer → `composer install --no-dev --optimize-autoloader`
5. **⚠️ KRITISCH: Externe Pakete laden** → `php bin/console importmap:install` (Bootstrap Icons!)
6. **⚠️ KRITISCH: Assets kompilieren** → `php bin/console asset-map:compile`
7. Cache leeren → `php bin/console cache:clear --env=prod`
8. Dateirechte → `chmod -R 775 var/cache var/log`

**⚠️ WICHTIG - Reihenfolge der Asset-Befehle:**
- Schritt 5 (importmap:install) MUSS VOR Schritt 6 (asset-map:compile) ausgeführt werden!
- `importmap:install` lädt Bootstrap Icons von jsDelivr herunter
- `asset-map:compile` kompiliert dann alle Assets inkl. Bootstrap Icons
- **Ohne beide Schritte fehlen CSS/JS/Icons komplett!**

**Ohne Schritt 1 (Document Root) funktioniert die gesamte Anwendung nicht!**
Alle anderen Fehler können Sie nur beheben, wenn das Document Root korrekt konfiguriert ist.
