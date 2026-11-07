# Quick-Fix-Operator-UI — Betriebshandbuch (v3.5)

Dieses Dokument beschreibt die web-basierte Operator-UI unter `/quick-fix`. Sie richtet
sich an Personen mit Administratorzugriff auf den Server (Self-Hosted-Betrieb).

---

## Wann greift `/quick-fix`?

Die Quick-Fix-UI tritt in Kraft, wenn das System beim Start eine der folgenden
Situationen erkennt und deshalb den normalen Betrieb verweigert:

| Erkannte Situation | Typisches Symptom |
|---|---|
| Noch nicht angewandte Doctrine-Migrations | Seiten brechen mit `Column not found` oder `Table does not exist` |
| Schema-Drift zwischen Entity-Metadata und Datenbank | Felder in der Oberflaechezeigen keine Werte obwohl Daten vorhanden sind |
| Waggon-/Orphan-Datensaetze ohne gueltigen Tenant | Compliance-Berichte fehlen Zeilen; Multi-Tenant-Scoping liefert falsches Ergebnis |
| Datenduplikate durch abgebrochene Imports | Eindeutige-Constraint-Fehler bei Neuanlage |

Der `QuickFixGuard` (Middleware) faengt diese Zustaende ab und leitet den Browser
direkt auf `/quick-fix` weiter, bevor die Anwendung regulaer antwortet.

---

## Was kann ich tun?

### 1. Migrationen anwenden

```
Schaltflaeche: "Ausstehende Migrationen anwenden"
```

Fuehrt `doctrine:migrations:migrate --no-interaction` aus. Nach dem Durchlauf
startet automatisch ein Schema-Reconcile (siehe Punkt 2), um eventuelle
PREPARE/EXECUTE-Altmigrationen auszugleichen.

**Wann nutzen:** Immer nach einem Update des Containers oder nach dem Ziehen
eines neuen Release-Tags.

**Hinweis:** Migrationen mit DDL (`ALTER TABLE`, `CREATE TABLE`) setzen intern
`isTransactional()=false`. Dieser Modus ist in der UI sichtbar als
"Nicht-transaktionaler Schritt" im Live-Log.

---

### 2. Schema-Drift reconcilen

```
Schaltflaeche: "Schema-Reconcile ausfuehren"
```

Fuehrt `app:schema:reconcile` aus. Das Tool vergleicht Doctrine-Entity-Metadata
mit dem tatsaechlichen Datenbankschema und wendet additive Aenderungen an
(neue Spalten, neue Indizes, neue Tabellen).

**Destructive Operationen (DROP / TRUNCATE):** Werden nur ausgefuehrt, wenn Sie
die Checkbox "Ich bestatige destructive Aenderungen" explizit angehakt haben.
Ohne Haken wird ein Dry-Run-Bericht angezeigt.

```
Checkbox: "Ich bestatige destructive Aenderungen (DROP TABLE / TRUNCATE)"
```

> Destructive Operationen sind selten. Sie treten auf, wenn eine Migration
> eine Tabelle entfernen soll, die noch Daten enthaelt. Im Normalfall reicht
> ein Reconcile ohne Haken.

---

### 3. Daten-Integritaet pruefen und reparieren

Die folgenden Aktionen sind verfuegbar:

| Aktion | Beschreibung |
|---|---|
| **Orphans bereinigen** | Entfernt Datensaetze ohne Elterntenant (z. B. nach fehlgeschlagenem Tenant-Delete) |
| **Tenant-Mismatches korrigieren** | Setzt `tenant_id` auf Basis des naechsten erreichbaren Elternobjekts (Asset --> Risk-Link etc.) |
| **Duplikate zusammenfuehren** | Fasst identische Eintraege (gleicher Name + gleicher Tenant) zu einem zusammen; behaelt den aeltesten Datensatz |
| **Verwaiste Datei-Uploads in Quarantaene** | Verschiebt Dateien aus `public/uploads/`, die in keiner Doctrine-Entity referenziert werden, nach `var/quarantine/<datum>/`. Niemals direkt `unlink`. |
| **Cascade-Cleanup** | Loescht dangling Referenzen (WorkflowInstance ohne Ziel, abgelaufene MfaToken, SsoUserApproval ohne Reviewer, EvidenceReverificationTask ohne Anker, NotificationDelivery ohne Rule) unter einem Audit-Batch. Begruendung >= 20 Zeichen erforderlich. |
| **JSON-Schema-Validierung** | Nur Erkennung — listet Tenant.settings / TenantPolicySetting.value / NotificationRule.conditions / WorkflowStep.metadata-Verletzungen. Keine Auto-Reparatur. |
| **Audit-Log-Integritaet** | Nur Erkennung — Bulk-Batch-Mismatches, Tages-Luecken im AuditLog, NULL-Tenant-Eintraege. Keine Auto-Reparatur. |
| **Status-Enum-Drift** | Nur Erkennung — DB-Statuswerte, die nicht im neuen `App\Enum\<Entity>Status` enthalten sind. Manuelle Triage erforderlich. |

Jede Aktion zeigt zuerst einen Vorschau-Bericht an. Sie muessen den Bericht
bestaetigen, bevor Aenderungen gespeichert werden. Die letzten drei
Kategorien sind detection-only — eine versteckte automatische Reparatur
koennte echte Lifecycle-Bugs verbergen.

**Orphans einer Organisation zuordnen:** Jeder Datensatz wird mit seinem
Audit-Eintrag `admin.data_repair.orphan_reassigned` in einer eigenen
Transaktion gespeichert; scheitert einer (z. B. Deadlock oder Lock-Wait-Timeout),
bleibt er Orphan und der Lauf geht mit dem naechsten weiter, ohne eine
halb offene Transaktion zurueckzulassen. Der Audit-Eintrag traegt den
Entity-Kurznamen (`DataBreach`, `Risk`, …) wie alle anderen Audit-Zeilen.
Eintraege aus der Zeit vor 3.16 ("Alles reparieren" und der asynchrone
Komplett-Lauf) tragen noch den Bucket-Namen (`data_breaches`, `risks`, …);
das Audit-Log ist manipulationssicher verkettet und wird deshalb nicht
umgeschrieben. Wer im Audit-Log nach Typ filtert, sucht fuer alte
Zuordnungen nach beiden Schreibweisen. Kein Bericht und kein Filter der
Anwendung wertet `orphan_reassigned` nach Typnamen aus.

---

### 4. "Alles-Sicher-Reparieren" (Convenience-Schaltflaeche)

```
Schaltflaeche: "Alles sicher reparieren"
```

Fuehrt in dieser Reihenfolge aus:

1. Ausstehende Migrationen anwenden
2. Schema-Reconcile ohne destructive Aktionen
3. Orphans bereinigen
4. Tenant-Mismatches korrigieren

Duplikate-Zusammenfuehrung ist *nicht* Teil dieses Buttons, da sie
anwendungsspezifisch geprueft werden sollte.

---

### 5. Kein aktiver Instanz-Betreiber (`ROLE_SUPER_ADMIN`)

Phantom-Reparaturen ("Migration als ausgefuehrt markieren", "Alle
Phantom-Diffs markieren") tragen Migrationen in `migration_versions` ein,
**ohne sie auszufuehren**. Trifft das die reine Daten-Migration
`Version20261019120000_operator_bootstrap` aus 3.15.3, wird der
Setup-Admin nie zum Instanz-Betreiber befoerdert — dann gibt es kein Konto,
das Benutzer ohne Organisation, instanzweite Backups oder Systemrollen
verwalten kann.

`/quick-fix` zeigt im Abschnitt Datenintegritaet einen roten Hinweis
**"Kein aktiver Instanz-Betreiber"**, sobald kein *aktives* Konto
`ROLE_SUPER_ADMIN` hat (auch wenn das Betreiber-Konto deaktiviert wurde).
Der Hinweis erscheint nur angemeldeten Besuchern (`/quick-fix` ist öffentlich).
Eine Web-Reparatur gibt es bewusst nicht (Shell-Zugriff = Betreiber).
Auf der Server-Shell:

```bash
php bin/console app:user:grant-super-admin admin@example.com
```

Der Befehl zeigt E-Mail, Organisation und aktuelle Rollen, fragt nach einer
Bestaetigung (`--yes` fuer Skripte) und schreibt einen Audit-Log-Eintrag.

---

## Wann brauche ich stattdessen die CLI?

Die Quick-Fix-UI deckt 95 % der Betriebsfaelle ab. Die CLI ist vorzuziehen, wenn:

- Ein Reconcile-Dry-Run Aenderungen anzeigt, die Sie vor der Ausfuehrung
  im Detail in der Shell pruefe moechten.
- Sie eine Backup-Wiederherstellung durchfuehren (-> `docs/operations/DISASTER_RECOVERY.md`).
- Die Quick-Fix-UI selbst nicht erreichbar ist (Webserver-Fehler auf PHP-Ebene).

Relevante CLI-Befehle:

```bash
# Dry-Run ohne Aenderungen
php bin/console app:schema:reconcile --dry-run

# Anwenden (additive, kein DROP)
php bin/console app:schema:reconcile

# Mit destructiven Aktionen (Vorsicht!)
php bin/console app:schema:reconcile --allow-destructive

# Datenbankstatus pruefen
php bin/console doctrine:migrations:status

# Orphan-Bereinigung separat
php bin/console app:data:repair-orphans --dry-run
php bin/console app:data:repair-orphans
```

---

## Zugriffsschutz (QuickFixGuard)

`/quick-fix` braucht keine Anmeldung, damit ein Betreiber ein Instanz retten kann,
deren Login nach einem Update an einer ausstehenden Migration scheitert. Es gibt
zwei Stufen:

| Stufe | Aktionen | Voraussetzung |
|---|---|---|
| Ansehen + Migrationen | Statusseite, „Ausstehende Migrationen anwenden“, additiver Schema-Abgleich, Job-Fortschritt | Guard-Einstellungen unten (Standard: offen) |
| Daten und destruktives Schema | destruktiver Schema-Abgleich (DROP/TRUNCATE), Migration als ausgeführt markieren, Phantom-Diff markieren, Schema-Force-Update, alle Daten-Reparaturen | zusätzlich Anmeldung als Super-Admin **oder** Installer-Token aus `var/setup-token` (Feld auf der Sperrseite, wird als Sitzungs-Cookie gemerkt) |

Die erste Stufe lässt sich unter **Admin > Einstellungen > Quick-Fix** weiter
einschränken (Kategorie `quick_fix` der Systemeinstellungen):

| Einstellung | Standard | Wirkung |
|---|---|---|
| Installer-Token verlangen | aus | Auch Statusseite und Migrationen nur mit Token |
| Nur in Entwicklungsumgebung | aus | UI nur bei `APP_ENV=dev` |
| IP-Allowlist | leer | Kommagetrennte IPs/CIDR-Blöcke, z. B. `192.168.1.0/24, 10.0.0.1` |

**Empfehlung für Produktivsysteme:** IP-Allowlist auf das Verwaltungsnetz setzen.
Hinter einem Proxy/CDN muss `SYMFONY_TRUSTED_PROXIES` stimmen, sonst sieht der
Guard die Proxy-IP.

---

## Audit-Trail der Quick-Fix-Aktionen

Alle Aktionen werden im System-Audit-Log eingetragen (`AuditLogger`-Kategorie
`system.quickfix`). Ein Eintrag enthaelt:

- Ausloesender Nutzer (oder `SYSTEM` fuer automatische Checks)
- Zeitstempel
- Durchgefuehrte Aktion (Migration-IDs, Anzahl reparierter Zeilen)
- Ergebnis (`success` / `partial` / `failed`)

Die Eintraege sind via `/de/admin/audit-log?category=system.quickfix` abrufbar.

---

## Weiterleitung nach erfolgreicher Reparatur

Nach Abschluss aller Aktionen zeigt die UI eine Zusammenfassung und einen
"Jetzt zur Anwendung"-Link. Dieser leitet auf `/de/dashboard` (oder den
urspruenglich angeforderten Pfad) weiter.

---

## Verwandte Dokumente

- `docs/operations/DISASTER_RECOVERY.md` — Backup-Restore-Runbook
- `docs/MIGRATION_GUIDE.md` — Migrations-Strategie und DDL-Regeln
- `docs/ADMIN_GUIDE.md` — Admin-Portal-Referenz
