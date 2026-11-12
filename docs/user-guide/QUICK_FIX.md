# Quick-Fix-Operator-UI — Betriebshandbuch

Dieses Dokument beschreibt die web-basierte Operator-UI unter `/quick-fix`. Sie richtet
sich an Personen mit Administratorzugriff auf den Server (Self-Hosted-Betrieb).

---

## Wann greift `/quick-fix`?

Wirft eine Seite einen Schema-Fehler (fehlende Tabelle, fehlende Spalte, z. B.
nach einem Update mit noch nicht angewandten Migrationen), leitet
`SchemaExceptionSubscriber` den Browser auf `/quick-fix` statt eine 500-Seite zu
zeigen. Die Seite ist auch direkt aufrufbar, etwa nach jedem Update oder bei:

| Situation | Typisches Symptom |
|---|---|
| Noch nicht angewandte Doctrine-Migrations | `Column not found` oder `Table does not exist` |
| Schema-Drift zwischen Entity-Metadata und Datenbank | Felder zeigen keine Werte, obwohl Daten vorhanden sind |
| Datensaetze ohne gueltige Organisation (Orphans) | Berichte fehlen Zeilen |
| Duplikate durch abgebrochene Imports | Unique-Constraint-Fehler bei Neuanlage |

Ist die Fallback-UI in den Quick-Fix-Einstellungen abgeschaltet, erscheint die
normale Fehlerseite.

---

## Was kann ich tun?

### 1. Migrationen anwenden

```
Schaltflaeche: "Ausstehende Migrationen anwenden"
```

Fuehrt `doctrine:migrations:migrate --no-interaction` aus. Nach dem Durchlauf
startet automatisch ein additives Schema-Reconcile (siehe Punkt 2), wenn danach
noch nicht-destruktive Abweichungen bestehen.

**Wann nutzen:** Nach jedem Update (Container-Image oder neuer Release-Stand).

**Wer darf:** nur als Super-Admin angemeldet oder mit dem Installer-Token (S33, siehe „Zugriffsschutz“).

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

Auf `/quick-fix`:

| Aktion | Beschreibung |
|---|---|
| **Orphans zuordnen** | Ordnet Datensaetze ohne Organisation einer Organisation zu (nur bei genau einer Organisation, siehe unten) |
| **Tenant-Mismatches korrigieren** | Setzt `tenant_id` auf Basis des Elternobjekts (Asset → Risk-Link etc.) |
| **Duplikate** | Zaehlt Eintraege mit gleichem Schluessel (Titel, Name, Nummer, Dateiname) je Organisation. Seit S29 nur ein Bericht: gleicher Titel heisst nicht doppelt (zwei Vorfaelle "Phishing" sind zwei Vorfaelle). Pruefen unter Admin → Datenreparatur → Duplikate, dann ueber den Datensatz archivieren oder loeschen; Nachweise bleiben (DeletionPolicy). |

Weitere Pruefungen unter **Admin → Datenreparatur** (`/admin/data-repair`):

| Aktion | Beschreibung |
|---|---|
| **Verwaiste Datei-Uploads in Quarantaene** | Verschiebt Dateien aus `public/uploads/` und `var/uploads/`, die kein Datensatz referenziert, nach `var/quarantine/<datum>/`. Nie direkt `unlink`. |
| **Cascade-Cleanup** | Loescht dangling Referenzen (WorkflowInstance ohne Ziel, abgelaufene MfaToken, SsoUserApproval ohne Reviewer, EvidenceReverificationTask ohne Anker, NotificationDelivery ohne Rule) unter einem Audit-Batch. Begruendung >= 20 Zeichen erforderlich. |
| **JSON-Schema-Validierung** | Nur Erkennung — listet Tenant.settings / TenantPolicySetting.value / NotificationRule.conditions / WorkflowStep.metadata-Verletzungen. Keine Auto-Reparatur. |
| **Audit-Log-Integritaet** | Nur Erkennung — Bulk-Batch-Mismatches, Tages-Luecken im AuditLog, NULL-Tenant-Eintraege. Keine Auto-Reparatur. |
| **Status-Enum-Drift** | Nur Erkennung — DB-Statuswerte, die nicht im neuen `App\Enum\<Entity>Status` enthalten sind. Manuelle Triage erforderlich. |

**Wer was sieht:** `/quick-fix` bleibt ohne Anmeldung erreichbar, damit ein
Betreiber den Stand einer kaputten Instanz sieht. Ohne Super-Admin-Sitzung
oder Installer-Token zeigt die Seite aber nur den Stand und keine Knöpfe: keine
SQL-Anweisungen, keine Datenbank-Fehlermeldung und keinen
Datenintegritaets-Abschnitt (der zaehlt Zeilen aller Organisationen und ist der
teure Teil jedes Seitenaufrufs).

**Geteilte Zeilen sind keine Orphans:** Zeilen ohne Organisation, die absichtlich
allen gehoeren (SSO-Anbieter, Tags, Mapping-Luecken, Tour-Anpassungen,
FTE-Konstanten, globale Benachrichtigungsvorlagen — alles mit
`SharedTenantRowsInterface`), zaehlen nicht als Orphans und werden nie einer
Organisation zugeordnet. Mit mehr als einer Organisation ordnen *Orphans
zuordnen* und *Alles sicher reparieren* nichts mehr „der ersten Organisation“
zu; der Lauf verweist auf Admin → Datenreparatur, wo pro Datensatz die
Organisation gewaehlt wird.

Die letzten drei Kategorien sind detection-only — eine versteckte automatische Reparatur
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

**Freitext-Verantwortliche** gibt es seit 2026-11 nicht mehr. Die Migration
`Version20261108100000` (hier unter *Pending-Migrations anwenden*) traegt jeden
alten Freitext als Benutzer oder Person ein: genau ein passender Benutzer der
Organisation (E-Mail, „Vorname Nachname“, „Nachname, Vorname“), sonst genau eine
passende Person, sonst eine neue Person mit Vermerk „Aus Freitext übernommen“
(Personen-Verzeichnis). Danach entfernt `Version20261108100100` die alten
Spalten. Die fruehere Seite „Freitext-Verantwortliche“ und die CLI
`app:migrate:freetext-owners` sind entfallen. Neue Personen aus der Migration
lassen sich im Personen-Verzeichnis mit einem Benutzerkonto verknuepfen, dann
erreichen Erinnerungen und Mein Tag die Person.

---

### 4. "Alles-Sicher-Reparieren" (Convenience-Schaltflaeche)

```
Schaltflaeche: "Alles sicher reparieren"
```

Fuehrt in dieser Reihenfolge aus, jeden Schritt mit eigenem Audit-Eintrag:

1. Orphans zuordnen (nur mit genau einer Organisation, siehe oben)
2. Tenant-Mismatches korrigieren
3. Duplikate zaehlen (nur Bericht, nichts wird geloescht)

Migrationen und Schema-Reconcile sind nicht Teil dieses Buttons (Punkte 1 und 2).

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

### 6. Freigabeketten abgleichen (regulatorische Workflows)

```
Schaltfläche: "Workflow-Definitionen abgleichen"
```

Die 15 Freigabeketten (Datenpanne, Vorfall, Risikobehandlung, DSFA, CAPA …) sind in
`config/workflows/regulatory/*.yaml` definiert. Eine laufende Freigabe liest den
zuständigen Freigeber aber aus der Datenbank. Nach einem Update kann die Datenbank
hinter der Definition liegen (Beispiel: die Risikobehandlung wartet noch auf den
Risikomanager statt auf den Risikoeigner).

Der Abschnitt **Freigabeketten** zeigt jede abweichende Kette mit den Unterschieden
(`step 1 role: ROLE_RISK_MANAGER → owner`). Die Schaltfläche schreibt die Datenbank
aus den Definitionen:

- vorhandene Schritte werden an Ort und Stelle geändert, laufende Freigaben bleiben
  auf ihrem Schritt;
- ein Schritt, den die Definition nicht mehr hat, bleibt stehen, solange eine Freigabe
  darauf wartet (Hinweis nach dem Abgleich);
- fehlende Ketten werden angelegt.

Der Abgleich läuft auch jede Nacht um 04:20 (Scheduler) und beim ersten Start einer
Freigabe, deren Kette noch keine Zeile hat. Nur Super-Admin oder Installer-Token;
Audit-Log-Eintrag `quick_fix.workflows_synced`.

---

## Wann brauche ich stattdessen die CLI?

Die Quick-Fix-UI deckt 95 % der Betriebsfaelle ab. Die CLI ist vorzuziehen, wenn:

- Ein Reconcile-Dry-Run Aenderungen anzeigt, die Sie vor der Ausfuehrung
  im Detail in der Shell pruefen moechten.
- Sie eine Backup-Wiederherstellung durchfuehren (-> `docs/operations/DISASTER_RECOVERY.md`).
- Die Quick-Fix-UI selbst nicht erreichbar ist (Webserver-Fehler auf PHP-Ebene).

Relevante CLI-Befehle:

```bash
# Dry-Run ohne Aenderungen
php bin/console app:schema:reconcile --dry-run

# Nur additive Aenderungen, DROP-Statements werden uebersprungen
php bin/console app:schema:reconcile --non-destructive-only

# Alle Aenderungen; vor jedem DROP fragt der Befehl nach
php bin/console app:schema:reconcile

# Datenbankstatus pruefen
php bin/console doctrine:migrations:status
```

Orphans und Duplikate repariert man ohne CLI unter Admin → Datenreparatur.

---

## Zugriffsschutz (QuickFixGuard)

`/quick-fix` ist ohne Anmeldung erreichbar, damit ein Betreiber sieht, warum eine Instanz nach einem
Update nicht läuft. Ändern darf die Seite die Datenbank nur für Berechtigte (S33): Migrationen verschieben
Daten und sollen laufen, wenn der Betreiber es entscheidet (nach dem Backup), nicht wenn jemand die Seite
findet.

| Stufe | Aktionen | Voraussetzung |
|---|---|---|
| Ansehen | Statusseite (Zahlen, kein SQL), Job-Fortschritt | Guard-Einstellungen unten (Standard: offen) |
| Ändern | Ausstehende Migrationen anwenden, Schema-Abgleich (additiv und destruktiv), Migration als ausgeführt markieren, Phantom-Diff markieren, Schema-Force-Update, Freigabeketten abgleichen, alle Daten-Reparaturen | Anmeldung als Super-Admin **oder** Installer-Token |

**Anmelden:** „Als Super-Admin anmelden“ auf der Quick-Fix-Seite öffnet die Anmeldung und kehrt danach (auch
nach dem zweiten Faktor) zum Quick-Fix zurück. Das reicht in den meisten Fällen, auch mit ausstehenden
Migrationen.

**Wenn die Anmeldung nach einem Update scheitert:** den Installer-Token benutzen. Unter **Admin >
Quick-Fix-Einstellungen** sieht der Super-Admin den „Quick-Fix-Link mit Installer-Token“ (fehlt die Datei
`var/setup-token`, etwa bei Docker, erzeugt „Link erzeugen“ sie). Den Link **vor** dem Update kopieren und
aufbewahren; er öffnet den Quick-Fix ohne Anmeldung, das Token wird als Sitzungs-Cookie gemerkt. Ohne Link:
Datei `var/setup-token` im Dateimanager des Hosters öffnen und das Token im Feld der Quick-Fix-Seite eingeben.

Die erste Stufe lässt sich unter **Admin > Quick-Fix-Einstellungen**
(`/admin/quick-fix-settings`) weiter einschränken (Kategorie `quick_fix` der Systemeinstellungen):

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

Reparaturen und Phantom-Markierungen schreiben Audit-Eintraege mit Aktionen
`quick_fix.*` (z. B. `quick_fix.repair.orphan_assigned`,
`quick_fix.repair.tenant_mismatch_fixed`,
`quick_fix.force_mark_migration_executed`). Filter: Admin → Audit-Log
(`/admin/audit-log?action=quick_fix.repair.orphan_assigned`).

---

## Verwandte Dokumente

- [DISASTER_RECOVERY.md](../operations/DISASTER_RECOVERY.md) — Backup-Restore-Runbook
- [HOSTING_WORKER.md](HOSTING_WORKER.md) — Worker und geplante Aufgaben
- [ADMIN_GUIDE.md](../ADMIN_GUIDE.md) — Admin-Portal-Referenz
