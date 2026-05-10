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

Drei Aktionen sind verfuegbar:

| Aktion | Beschreibung |
|---|---|
| **Orphans bereinigen** | Entfernt Datensaetze ohne Elterntenant (z. B. nach fehlgeschlagenem Tenant-Delete) |
| **Tenant-Mismatches korrigieren** | Setzt `tenant_id` auf Basis des naechsten erreichbaren Elternobjekts (Asset --> Risk-Link etc.) |
| **Duplikate zusammenfuehren** | Fasst identische Eintraege (gleicher Name + gleicher Tenant) zu einem zusammen; behaelt den aeltesten Datensatz |

Jede Aktion zeigt zuerst einen Vorschau-Bericht an. Sie muessen den Bericht
bestaetigen, bevor Aenderungen gespeichert werden.

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

## QuickFixGuard-Konfiguration

Der Guard wird ueber Umgebungsvariablen gesteuert. Setzen Sie diese in Ihrer
`.env.local` oder in der Docker-Compose-Datei:

| Variable | Standardwert | Beschreibung |
|---|---|---|
| `QUICK_FIX_ENABLED` | `true` | Guard global aktivieren / deaktivieren |
| `QUICK_FIX_TOKEN` | _(leer)_ | Optionaler Bearer-Token als Zugangsschutz |
| `QUICK_FIX_IP_ALLOWLIST` | _(leer)_ | Kommagetrennte IP-Adressen/-CIDR-Bloecke (z. B. `192.168.1.0/24,10.0.0.1`) |
| `QUICK_FIX_DEV_ONLY` | `false` | `true` = UI nur im `APP_ENV=dev` sichtbar |

**Empfehlung fuer Produktionssysteme:** `QUICK_FIX_TOKEN` auf einen langen
Zufallswert setzen und `QUICK_FIX_IP_ALLOWLIST` auf Ihr Verwaltungsnetz
beschraenken. So ist die UI auch bei versehentlichem Trigger nicht oeffentlich
zugaenglich.

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
