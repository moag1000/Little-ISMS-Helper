# Setup Wizard - Szenario-Analyse

## ✅ ABGEDECKTE Szenarien

### 1. Erstinstallation (Happy Path)
**Ablauf:**
- User öffnet `/setup`
- Kein `setup_complete.lock` → Wizard startet
- DB konfigurieren → `.env.local` schreiben
- Admin erstellen → Migrationen + User
- Module wählen → Base Data → Fertig

**Status:** ✅ **FUNKTIONIERT**
**Code:** `DeploymentWizardController::index()` prüft Lock-File

---

### 2. Setup bereits abgeschlossen - Admin-Zugriff
**Ablauf:**
- `setup_complete.lock` existiert
- Admin öffnet `/setup`
- Redirect zu Index mit "Setup already complete" Message

**Status:** ✅ **FUNKTIONIERT**
**Code:**
- `SetupSecuritySubscriber` erlaubt Admin-Zugriff
- `DeploymentWizardController::index()` zeigt Message

---

### 3. Setup bereits abgeschlossen - Nicht-Admin
**Ablauf:**
- `setup_complete.lock` existiert
- Nicht-authentifizierter User öffnet `/setup`
- Redirect zu Login
- Authentifizierter Non-Admin → Access Denied

**Status:** ✅ **FUNKTIONIERT**
**Code:** `SetupSecuritySubscriber::onKernelRequest()`
- Zeilen 64-75: Prüft Authentifizierung und Rolle

---

### 4. Passwörter mit Sonderzeichen
**Ablauf:**
- User gibt DB-Passwort mit `@`, `:`, `/` ein
- Passwort wird URL-encoded in DATABASE_URL

**Status:** ✅ **FUNKTIONIERT** (nach Fix #3)
**Code:** `EnvironmentWriter::writeDatabaseConfig()`
- Zeilen 46-47: `urlencode($user)`, `urlencode($password)`

**Beispiel:**
```
Input:  p@ss:word
Output: mysql://user:p%40ss%3Aword@localhost/db
```

---

### 5. Existierende .env.local
**Ablauf:**
- `.env.local` existiert bereits
- User konfiguriert DB neu
- Backup wird erstellt (`.env.local.backup`)
- Neue Config überschreibt alte

**Status:** ✅ **FUNKTIONIERT**
**Code:** `EnvironmentWriter::createBackup()`
- Zeilen 240-246: Erstellt Backup vor Überschreiben

---

### 6. APP_SECRET fehlt
**Ablauf:**
- `.env.local` hat kein APP_SECRET
- Wizard generiert automatisch eins

**Status:** ✅ **FUNKTIONIERT**
**Code:** `EnvironmentWriter::ensureAppSecret()`
- Zeilen 100-108: Generiert mit `random_bytes(32)`

---

### 7. Datenbank existiert bereits
**Ablauf:**
- User gibt DB-Name ein, DB existiert schon
- Connection-Test erfolgreich
- Flag `create_needed` = false
- Keine Neuanlage, weiter zu Migrationen

**Status:** ✅ **FUNKTIONIERT**
**Code:** `DatabaseTestService::testMysqlConnection()`
- Zeilen 117-123: Unterscheidet "DB existiert" vs "DB fehlt"

---

### 8. Migration bereits ausgeführt
**Ablauf:**
- Migrationen wurden schon ausgeführt
- `doctrine:migrations:migrate` erkennt Status
- Exit Code 0, keine Fehler

**Status:** ✅ **FUNKTIONIERT**
**Code:** Doctrine Migrations sind idempotent
**Hinweis:** Doctrine prüft `migration_versions` Tabelle

---

### 9. Admin-User existiert bereits
**Ablauf:**
- User gibt Email ein, die bereits existiert
- `SetupPermissionsCommand` prüft vorher
- Warning: "User with email X already exists"
- Command erfolgreich (Exit Code 0), aber keine Duplikate

**Status:** ✅ **FUNKTIONIERT**
**Code:** `SetupPermissionsCommand::createAdminUser()`
- Zeilen 254-257: Prüft existierende User

---

### 10. Verschiedene Datenbanktypen
**Ablauf:**
- MySQL: Standard-Port 3306, Server-Version 8.0
- PostgreSQL: Standard-Port 5432, Server-Version 14
- SQLite: Keine Host/Port, nur Dateiname

**Status:** ✅ **FUNKTIONIERT**
**Code:**
- `EnvironmentWriter::writeDatabaseConfig()` - Match-Statement
- `DatabaseTestService` - Separate Methoden pro Typ

---

## ⚠️ TEILWEISE ABGEDECKTE Szenarien

### 11. Setup-Abbruch mit Session-Loss
**Ablauf:**
1. User konfiguriert DB → `.env.local` geschrieben
2. Browser/Session-Timeout
3. User kehrt zurück
4. Session leer → `setup_database_configured` = false
5. User muss DB neu konfigurieren

**Status:** ⚠️ **FUNKTIONIERT, ABER UMSTÄNDLICH**
**Problem:** Session-basierter State ist nicht persistent
**Auswirkung:** User muss bereits abgeschlossene Steps wiederholen

**Workaround:**
- `.env.local.backup` existiert
- User kann theoretisch wiederherstellen
- Aber keine automatische Erkennung

---

### 12. Setup-Abbruch nach Admin-Erstellung
**Ablauf:**
1. User erstellt Admin-User
2. Migrationen ausgeführt
3. User in DB gespeichert
4. Session-Loss
5. User kehrt zurück → Step 2 (Admin-Erstellung)
6. Gibt DIESELBE Email ein
7. Command: "User exists" → Exit Code 0, aber keine Neuanlage
8. Wizard: "Success!" (täuschend)

**Status:** ⚠️ **FUNKTIONIERT, ABER VERWIRREND**
**Problem:** User weiß nicht, ob neuer User erstellt wurde oder alter verwendet
**Code-Stelle:** `DeploymentWizardController::createAdminUserViaCommand()`
- Zeilen 373-377: Exit Code 0 = Success, auch wenn User existiert

**Verbesserung nötig:**
- Command sollte unterscheiden zwischen "created" und "already exists"
- Controller sollte entsprechende Message zeigen

---

## 🚨 NICHT ABGEDECKTE Szenarien

### 13. Datenbank-Connection während Setup verloren
**Ablauf:**
1. User konfiguriert DB → Test erfolgreich
2. DB-Server geht offline
3. User kommt zu Step 2 (Admin-Erstellung)
4. Migrationen schlagen fehl

**Status:** ❌ **FEHLER NICHT BEHANDELT**
**Problem:**
- Migration-Fehler wird angezeigt
- User steckt fest
- Keine Möglichkeit zurück zu Step 1

**Lösung nötig:**
- "Back" Button in jedem Step
- Oder: Connection-Test vor jedem DB-Operation
- Oder: Global Error Handler mit "Retry" Option

---

### 14. .env.local ist schreibgeschützt
**Ablauf:**
1. User konfiguriert DB
2. `EnvironmentWriter::writeEnvVariables()` schlägt fehl
3. Exception: "Failed to write to .env.local"

**Status:** ❌ **FEHLER ZEIGT TECHNISCHE DETAILS**
**Problem:**
- Exception wird direkt zum User durchgereicht
- Kein hilfreicher Hinweis auf Lösung

**Code-Stelle:** `EnvironmentWriter::writeEnvVariables()`
- Zeile 100: `throw new \RuntimeException()`

**Lösung nötig:**
- Try-Catch in Controller
- User-freundliche Fehlermeldung
- Hinweis: "Prüfen Sie Dateiberechtigungen für .env.local"

---

### 15. var/ Verzeichnis existiert nicht
**Ablauf:**
1. User wählt SQLite
2. `DatabaseTestService::createSqliteDatabase()` versucht `var/` zu erstellen
3. Fehlschlag bei fehlenden Berechtigungen

**Status:** ⚠️ **TEILWEISE ABGEDECKT**
**Code:** `createSqliteDatabase()` macht `mkdir($dbDir, 0755, true)`
- Zeilen 200-202: Erstellt Verzeichnis rekursiv

**Problem:** Keine Fehlerbehandlung bei fehlgeschlagenem `mkdir`

---

### 16. Gleichzeitiger Setup-Versuch (Race Condition)
**Ablauf:**
1. Admin A startet Setup
2. Admin B startet Setup (bevor A fertig ist)
3. Beide konfigurieren DB parallel
4. Beide erstellen Admin-User
5. Beide schreiben `.env.local`

**Status:** ❌ **NICHT ABGEDECKT**
**Wahrscheinlichkeit:** Sehr gering (nur bei Erstinstallation)
**Auswirkung:**
- Letzter Schreiber gewinnt (`.env.local`)
- Beide User werden erstellt (verschiedene Emails?)
- Lock-File wird zweimal erstellt (okay)

**Lösung nötig:**
- Lock-Mechanism während Setup
- Z.B. `.setup.lock` während laufendem Wizard
- Nach Completion in `setup_complete.lock` umbenennen

---

### 17. Unvollständige Module-Konfiguration
**Ablauf:**
1. User wählt Module mit Dependencies
2. Dependency-Resolver fügt Module hinzu
3. Base-Data Import für ein Modul schlägt fehl
4. User geht weiter zu Sample Data

**Status:** ⚠️ **TEILWEISE ABGEDECKT**
**Code:** `DataImportService::importBaseData()` gibt Errors zurück
**Problem:** User kann trotz Fehlern fortfahren

**Lösung nötig:**
- Option "Retry Failed Imports"
- Oder: Blockierung von "Weiter" bei kritischen Fehlern

---

### 18. Browser-Back während Setup
**Ablauf:**
1. User ist bei Step 4 (Module)
2. User drückt Browser-Back
3. Kommt zu Step 3 (Requirements)
4. Ändert nichts, geht zu Step 4
5. Session-State ist inkonsistent?

**Status:** ⚠️ **UNSICHER**
**Problem:** Session-State könnte überschrieben werden
**Test benötigt:** Manueller Test mit Browser-Back

---

### 19. Falsche DATABASE_URL Syntax
**Ablauf:**
1. `.env.local` wird korrekt geschrieben
2. User ändert manuell `.env.local`
3. Macht Syntaxfehler in DATABASE_URL
4. Symfony kann nicht booten

**Status:** ❌ **NICHT ABGEDECKT**
**Problem:** Setup-Wizard ist nicht mehr erreichbar
**Lösung:**
- Backup-Recovery-Route?
- Oder: Manuelle Wiederherstellung aus `.env.local.backup`

---

### 20. Fehlende PHP-Extensions
**Ablauf:**
1. User wählt PostgreSQL
2. `pdo_pgsql` Extension fehlt
3. Connection-Test schlägt fehl mit kryptischer PDO-Exception

**Status:** ⚠️ **TEILWEISE ABGEDECKT**
**Code:** `SystemRequirementsChecker` prüft Extensions in Step 3
**Problem:** Das ist ZU SPÄT! Extensions sollten in Step 1 geprüft werden

**Lösung nötig:**
- Extension-Check VOR DB-Typ-Auswahl
- Oder: DB-Typ-Auswahl nur für verfügbare Extensions

---

### 21. PostgreSQL ohne Superuser-Rechten
**Ablauf:**
1. User gibt PostgreSQL-Credentials ein
2. User hat KEINE CREATE DATABASE Berechtigung
3. `createPostgresqlDatabase()` schlägt fehl

**Status:** ❌ **NICHT ABGEDECKT**
**Code-Stelle:** `DatabaseTestService::createPostgresqlDatabase()`
- Zeile 260: `CREATE DATABASE` ohne Permission-Check

**Lösung nötig:**
- Try-Catch für Permission-Denied
- User-freundliche Message: "Sie benötigen CREATE DATABASE Berechtigung"
- Alternative: Admin legt DB manuell an, User gibt existierende DB ein

---

### 22. MySQL Strict Mode Probleme
**Ablauf:**
1. User hat MySQL mit Strict Mode
2. Migrationen schlagen fehl bei bestimmten Constraints
3. Kryptische SQL-Fehler

**Status:** ❌ **NICHT ABGEDECKT IM WIZARD**
**Hinweis:** Doctrine Migrations sollten Strict-Mode-kompatibel sein
**Aber:** Keine explizite Prüfung im Setup

---

### 23. Sehr langsame Datenbank
**Ablauf:**
1. User konfiguriert Remote-DB über langsame Verbindung
2. Connection-Test dauert >5 Sekunden
3. Timeout (PDO::ATTR_TIMEOUT = 5)
4. Fehler: "Connection timed out"

**Status:** ✅ **FUNKTIONIERT**
**Code:** `DatabaseTestService` setzt 5s Timeout
**Aber:** Kein Hinweis für User, dass langsame Verbindung das Problem sein könnte

---

## 📊 Zusammenfassung

| Kategorie | Anzahl | Status |
|-----------|--------|--------|
| **Vollständig abgedeckt** | 10 | ✅ |
| **Teilweise abgedeckt** | 6 | ⚠️ |
| **Nicht abgedeckt** | 7 | ❌ |
| **GESAMT** | 23 | - |

---

## 🎯 Kritikalität der Lücken

### 🔴 **KRITISCH** (Sofort beheben)

1. **Szenario #14: .env.local schreibgeschützt**
   - Häufigkeit: Mittel (Production-Deployments)
   - Auswirkung: Setup kann nicht abgeschlossen werden
   - Lösung: Try-Catch + User-Hinweis

2. **Szenario #20: Fehlende PHP-Extensions**
   - Häufigkeit: Hoch (bei manuellen Installationen)
   - Auswirkung: Verwirrende Fehler
   - Lösung: Extensions-Check VOR DB-Auswahl

3. **Szenario #21: PostgreSQL ohne CREATE DATABASE**
   - Häufigkeit: Mittel (restriktive DB-Server)
   - Auswirkung: Setup schlägt fehl ohne klare Erklärung
   - Lösung: Permission-Check + Alternative anbieten

---

### 🟡 **WICHTIG** (Bald beheben)

4. **Szenario #11: Setup-Abbruch mit Session-Loss**
   - Häufigkeit: Niedrig (aber ärgerlich)
   - Auswirkung: User muss Steps wiederholen
   - Lösung: State-Recovery aus `.env.local` / DB

5. **Szenario #13: DB-Connection während Setup verloren**
   - Häufigkeit: Niedrig
   - Auswirkung: User steckt fest
   - Lösung: "Back" Buttons oder Retry-Mechanismus

6. **Szenario #17: Unvollständige Module-Konfiguration**
   - Häufigkeit: Mittel
   - Auswirkung: Inkonsistenter System-State
   - Lösung: Retry-Option für fehlgeschlagene Imports

---

### 🟢 **NICE-TO-HAVE** (Optional)

7. **Szenario #12: Setup-Abbruch nach Admin-Erstellung**
   - Häufigkeit: Niedrig
   - Auswirkung: Verwirrung (aber funktional okay)
   - Lösung: Bessere Rückmeldung

8. **Szenario #16: Race Condition**
   - Häufigkeit: Sehr niedrig
   - Auswirkung: Minimal
   - Lösung: Lock-File während Setup

9. **Szenario #18: Browser-Back**
   - Häufigkeit: Niedrig
   - Auswirkung: Unklar (Test benötigt)
   - Lösung: POST-Redirect-GET Pattern

---

## 🛠️ Empfohlene Verbesserungen

### Sofort implementieren:

1. **Extensions-Check in Step 1**
   ```php
   // BEFORE DB type selection
   if ($type === 'postgresql' && !extension_loaded('pdo_pgsql')) {
       $this->addFlash('error', 'PostgreSQL PDO extension not installed');
   }
   ```

2. **Try-Catch für .env.local Schreibfehler**
   ```php
   try {
       $this->envWriter->writeDatabaseConfig($config);
   } catch (\RuntimeException $e) {
       $this->addFlash('error', 'Cannot write .env.local. Check file permissions.');
   }
   ```

3. **Permission-Check für PostgreSQL**
   ```php
   // Test CREATE DATABASE permission
   $stmt = $pdo->query("SELECT has_database_privilege('postgres', 'CREATE')");
   ```

### Mittel-term:

4. **State-Recovery Mechanismus**
   - Prüfe `.env.local` Existenz
   - Prüfe ob Migrationen ausgeführt
   - Prüfe ob Admin existiert
   - Biete "Continue Setup" statt von vorne

5. **"Back" Buttons in jedem Step**

6. **Retry-Mechanismus für fehlgeschlagene Imports**

---

## ✅ Fazit

Das Setup-Wizard-Konzept ist **grundsätzlich solide**, deckt aber einige wichtige Edge Cases noch nicht ab.

**Hauptprobleme:**
1. ❌ Session-basierter State (nicht persistent)
2. ❌ Fehlende Extension-Checks vor DB-Auswahl
3. ❌ Unzureichende Error-Handling für File-Permissions
4. ❌ Keine DB-Permission-Checks

**Empfehlung:**
- Jetzt: Die 3 kritischen Fixes implementieren (1-2 Stunden)
- Später: State-Recovery + Back-Buttons (4-6 Stunden)
- Optional: Race-Condition-Lock (1 Stunde)
