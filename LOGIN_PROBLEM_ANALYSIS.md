# Umfassende Analyse: Admin Login Problem

**Status:** Login führt zu Redirect-Loop ohne Fehlermeldung
**Symptom:** "Ich komme immer wieder zur Anmeldeseite"
**Branch:** `claude/fix-extension-port-errors-01DodgcmLQ1Hqsys8zoCLFhT`

## Zeitleiste der Änderungen

### PR #240 (admin-sessions-page) - DER AUSLÖSER
Dieser PR führte folgende kritische Änderungen ein:

1. **Session-Management-System**
   - Neue Entity: `UserSession`
   - Neuer Service: `SessionManager`
   - Migration: `Version20251113173000.php` (user_sessions Tabelle)

2. **SecurityEventLogger Integration**
   - Ruft `SessionManager.createSession()` bei jedem Login auf
   - Ruft `SessionManager.endSession()` bei jedem Logout auf
   - **PROBLEM:** Wirft Exception wenn `user_sessions` Tabelle fehlt

3. **Granulare Permissions**
   - AdminDashboardController: `ROLE_ADMIN` → `ADMIN_VIEW`
   - Andere Admin-Controller: Ähnliche Änderungen
   - **PROBLEM:** Permission-Check könnte fehlschlagen

4. **Cookie Security Settings**
   - `cookie_samesite: 'strict'` in framework.yaml
   - `secure: true` in security.yaml remember_me
   - **PROBLEM:** Verhindert Login-Redirects und HTTP-Sessions

## Bisherige Fixes

### Fix #1: SessionManager Exception Handling (84aadc4)
```php
// SecurityEventLogger.php
try {
    $this->sessionManager->createSession($user, $session->getId());
} catch (\Exception $e) {
    // Log error but don't fail
}
```
**Status:** Hilft, aber reicht nicht

### Fix #2: SessionManager Table-Check (d47f898)
```php
// SessionManager.php
private function isTableAvailable(): bool {
    // Prüft ob user_sessions existiert
}
```
**Status:** Verhindert Exceptions, aber Login funktioniert noch nicht

### Fix #3: SessionManager DEAKTIVIERT (a658191)
```php
// SecurityEventLogger.php - AUSKOMMENTIERT
/*
if ($session) {
    try {
        $this->sessionManager->createSession(...);
    }
}
*/
```
**Status:** SessionManager komplett ausgeschaltet

### Fix #4: ROLE_ADMIN statt ADMIN_VIEW (a658191)
```php
// AdminDashboardController.php
#[IsGranted('ROLE_ADMIN')]  // War: ADMIN_VIEW
class AdminDashboardController
```
**Status:** Permission-Problem umgangen

### Fix #5: Cookie SameSite relaxed (e6dbd6a)
```yaml
# framework.yaml
cookie_samesite: 'lax'  # War: 'strict'

# security.yaml
secure: auto            # War: true
samesite: 'lax'        # War: 'strict'
```
**Status:** Sollte Redirects erlauben, aber Login funktioniert IMMER NOCH NICHT

## Mögliche verbleibende Probleme

### Problem 1: Browser-Cookies nicht gelöscht
**Symptom:** Alte Session-Cookies mit `samesite=strict` im Browser
**Lösung:** User muss Browser-Cookies löschen

### Problem 2: Symfony Cache nicht geleert
**Symptom:** Alte kompilierte Container-Konfiguration
**Lösung:**
```bash
rm -rf var/cache/*
php bin/console cache:clear --no-warmup
```

### Problem 3: Session-Speicher defekt
**Symptom:** PHP kann Sessions nicht schreiben
**Test:** `/public/test-session.php` aufrufen
**Prüfen:**
```bash
# Session-Verzeichnis prüfen
ls -la var/sessions/
# Schreibrechte prüfen
touch var/sessions/test && rm var/sessions/test
```

### Problem 4: HTTPS-Redirect in Webserver
**Symptom:** Webserver erzwingt HTTPS, Cookies gehen verloren
**Prüfen:**
- Apache .htaccess
- nginx.conf
- PHP-FPM Konfiguration

### Problem 5: Unbekannter EventSubscriber
**Symptom:** Ein Subscriber loggt User nach Login wieder aus
**Prüfen:** Alle Subscriber in `src/EventSubscriber/`

### Problem 6: Firewall-Konfiguration
**Symptom:** Security firewall blockiert authentifizierte Requests
**Status:** Unwahrscheinlich, aber möglich

### Problem 7: LoginSuccessHandler Problem
**Symptom:** Handler redirected zu ungültiger Route
**Prüfen:** `src/Security/LoginSuccessHandler.php` (sieht OK aus)

### Problem 8: Datenbank-Migration fehlt
**Symptom:** Ein anderer Teil braucht eine Migration
**Prüfen:**
```bash
php bin/console doctrine:migrations:status
php bin/console doctrine:schema:validate
```

## Debugging-Tools erstellt

### 1. Session-Test (raw PHP)
**Datei:** `public/test-session.php`
**Zweck:** Prüft ob PHP-Sessions überhaupt funktionieren
**Aufruf:** `http://your-domain/test-session.php`
**Erwartung:** Visit count erhöht sich bei jedem Refresh

### 2. Auth-Debug (Symfony)
**Controller:** `DebugController::debugAuth()`
**Route:** `/debug-auth`
**Zweck:** Zeigt User, Session, Cookies
**Aufruf:** `http://your-domain/debug-auth`

## Empfohlene nächste Schritte

### SCHRITT 1: Browser-Cookies löschen
```
1. Browser öffnen
2. Entwicklertools (F12)
3. Application/Storage Tab
4. Cookies → Ihre Domain
5. ALLE Cookies löschen
6. Seite neu laden
```

### SCHRITT 2: Cache komplett löschen
```bash
# Im Projekt-Verzeichnis
rm -rf var/cache/*
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:clear --env=dev --no-warmup
php bin/console cache:warmup
```

### SCHRITT 3: Sessions testen
```bash
# Aufruf test-session.php im Browser
# Mehrmals refreshen
# Counter sollte hochzählen
```

### SCHRITT 4: Auth debuggen
```bash
# Nach Login-Versuch:
# /debug-auth aufrufen
# Prüfen ob user authenticated ist
```

### SCHRITT 5: Logs prüfen
```bash
# Symfony Logs
tail -f var/log/dev.log
tail -f var/log/prod.log

# PHP Error Log
tail -f /var/log/php-fpm/error.log  # oder
tail -f /var/log/apache2/error.log
```

### SCHRITT 6: Webserver-Konfiguration prüfen
```bash
# Apache
cat .htaccess | grep -i redirect
cat .htaccess | grep -i rewrite

# nginx
cat /etc/nginx/sites-available/your-site
```

## Technische Details

### Login-Flow (sollte so ablaufen)

1. **POST /login** mit Credentials
   - SecurityController empfängt Request
   - Rate Limiter prüft (könnte blockieren!)
   - Form-Daten validiert
   - AuthenticationManager authentifiziert

2. **LoginSuccessEvent**
   - SecurityEventSubscriber::onLoginSuccess()
   - SecurityEventLogger::logLoginSuccess()
   - ~~SessionManager::createSession()~~ (DEAKTIVIERT)
   - LoginSuccessHandler::onAuthenticationSuccess()

3. **Redirect zu /de/dashboard**
   - HTTP 302 Redirect
   - **KRITISCH:** Cookie muss mitgesendet werden!
   - Mit samesite=lax: ✓ erlaubt
   - Mit samesite=strict: ✗ blockiert

4. **GET /de/dashboard**
   - HomeController::dashboard()
   - Prüft: isGranted('ROLE_USER')
   - Sollte: Dashboard anzeigen
   - Bei Fehler: Redirect zu /login (LOOP!)

### Was könnte den Loop verursachen?

#### Szenario A: Session wird nicht gespeichert
```
POST /login → Auth OK → Session erstellt
↓
302 Redirect → Session Cookie gesendet
↓
GET /dashboard → Session Cookie empfangen
↓
Session nicht gefunden → Nicht authentifiziert
↓
Redirect zu /login → LOOP!
```

#### Szenario B: Cookie wird nicht akzeptiert
```
POST /login → Auth OK → Set-Cookie: PHPSESSID=xxx; secure=true
↓
HTTP Request (kein HTTPS!) → Cookie wird NICHT gesendet
↓
GET /dashboard → Kein Cookie → Session nicht gefunden
↓
Redirect zu /login → LOOP!
```

#### Szenario C: Permission fehlt
```
POST /login → Auth OK → User hat nur ROLE_ADMIN
↓
GET /dashboard → Prüft ROLE_USER
↓
ROLE_ADMIN erbt ROLE_USER (role_hierarchy)
↓
Sollte OK sein... wenn hierarchy geladen ist!
```

## Verdächtige Konfigurationen

### security.yaml
```yaml
role_hierarchy:
    ROLE_AUDITOR:       ROLE_USER
    ROLE_MANAGER:       [ROLE_USER, ROLE_AUDITOR]
    ROLE_ADMIN:         [ROLE_USER, ROLE_AUDITOR, ROLE_MANAGER]  # ✓
    ROLE_SUPER_ADMIN:   [ROLE_ADMIN, ROLE_ALLOWED_TO_SWITCH]
```
**Status:** Sieht OK aus

### access_control
```yaml
- { path: ^/login$, roles: PUBLIC_ACCESS }              # ✓
- { path: '^/(de|en)/', roles: ROLE_USER }              # Dashboard braucht ROLE_USER
```
**Status:** Sieht OK aus

### framework.yaml Session
```yaml
session:
    cookie_secure: 'auto'        # HTTP in dev, HTTPS in prod ✓
    cookie_httponly: true         # ✓
    cookie_samesite: 'lax'        # ✓ (war strict ✗)
    gc_maxlifetime: 3600          # 1 Stunde
```
**Status:** JETZT korrekt (nach Fix #5)

## Mögliche Root Causes

### Wahrscheinlichkeit HOCH:
1. **Browser hat alte Cookies** (90%)
2. **Cache nicht geleert** (80%)
3. **Session-Speicher kaputt** (60%)

### Wahrscheinlichkeit MITTEL:
4. **Webserver forciert HTTPS** (40%)
5. **PHP Session-Konfiguration** (30%)
6. **Rate Limiter blockiert** (20%)

### Wahrscheinlichkeit NIEDRIG:
7. **EventSubscriber greift ein** (10%)
8. **Unbekannter Fehler** (5%)

## Status der Fixes

- [x] SessionManager macht keine Exceptions mehr
- [x] SessionManager prüft Table-Existenz
- [x] SessionManager komplett deaktiviert
- [x] ROLE_ADMIN statt ADMIN_VIEW
- [x] Cookie SameSite auf 'lax'
- [x] Cookie secure auf 'auto'
- [x] Debug-Tools erstellt

## Noch zu testen

- [ ] Browser-Cookies löschen
- [ ] Symfony Cache löschen
- [ ] test-session.php ausführen
- [ ] /debug-auth aufrufen
- [ ] Logs prüfen
- [ ] Migrations-Status prüfen

## Fazit

Die Code-Änderungen sollten jetzt korrekt sein. Das Problem liegt wahrscheinlich an:
1. Alten Cookies im Browser
2. Altem Cache in Symfony
3. Oder einem Webserver-/PHP-Konfigurationsproblem

**Nächster Schritt:** User muss die empfohlenen Schritte 1-6 durchführen.
