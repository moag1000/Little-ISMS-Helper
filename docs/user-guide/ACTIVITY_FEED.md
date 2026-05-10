# Activity-Feed — Benutzerhandbuch (v3.5)

Der Activity-Feed bietet eine chronologische Uebersicht aller sicherheitsrelevanten
Ereignisse im Tenant. Er dient als zentrale Anlaufstelle fuer den taeglichen
Ueberblick sowie als Recherche-Werkzeug fuer Auditoren.

---

## Zugriff

**Vollansicht:** `/de/activity-feed` (oder `/en/activity-feed`)

**Kompakt-Widget:** Eingebettet in "Mein Tag" (Bucket "Activity-Feed ungelesen")
und in das CISO-Dashboard.

---

## Datenquellen

Der Feed aggregiert Ereignisse aus vier Quellen:

| Quelle | Typische Ereignisse |
|---|---|
| **Audit-Log** | Entity erstellt / geaendert / geloescht, Statusuebergaenge, Bulk-Operationen |
| **Workflow-System** | Schritt freigegeben, Auto-Progression ausgeloest, Frist gerissen, Workflow abgeschlossen |
| **Dokument-Modul** | Dokument hochgeladen, neue Version, Policy veroeffentlicht, Policy-Acknowledgement erhalten |
| **Risikomanagement** | Neues Risiko, Risiko eskaliert (Score-Anstieg), Risikobehandlung genehmigt |

Jedes Ereignis enthaelt: Zeitstempel, Nutzer (oder "System" bei Auto-Progression),
Ereignistyp, betroffene Entitaet mit Direktlink.

---

## Scope-Filter

Der Feed kann per URL-Parameter `?scope=` gefiltert werden:

| Scope | Gefilterte Ereignisse |
|---|---|
| `compliance` | Nur compliance-relevante Events: Wizard-Abschluesse, Framework-Aenderungen, Mapping-Freigaben, SoA-Statuswechsel |
| `risk` | Risiko-Lifecycle-Events: neu, eskaliert, behandelt, akzeptiert |
| `incident` | Incident-Events: gemeldet, eskaliert, geschlossen, Post-Mortem freigegeben |
| `workflow` | Alle Workflow-Statusuebergaenge |
| `documents` | Dokument-Hochladen, Versionen, Policy-Acks |
| `admin` | Admin-Aktionen: Nutzer-Provisionierung, Tenant-Aenderungen, Modul-Aktivierungen |
| _(kein Parameter)_ | Alle Quellen, chronologisch |

Mehrere Scopes koennen kombiniert werden: `?scope=compliance,risk`

---

## Freitext- und Datumssuche

- **Freitext:** Suche in Entitaetsnamen, Nutzer, Ereignistyp.
- **Datumsbereich:** Von- / Bis-Datumsauswahl; Standard: letzte 30 Tage.
- **Nutzerfilter:** Dropdown mit allen Tenant-Nutzern (nur ADMIN/AUDITOR).
- **Entitaetstypfilter:** Risk / Asset / Control / Incident / Dokument / Workflow usw.

Alle Filter sind kombinierbar und werden als URL-Parameter serialisiert
(teilbar als Link).

---

## ActivityFeedWidget (reusable)

Das Widget kann in beliebige Twig-Templates eingebettet werden:

```twig
{# Kompakt: letzte 5 Events, keine Filter-UI #}
{% include '_components/_activity_feed_widget.html.twig' with {
    mode: 'compact',
    limit: 5,
    scope: 'compliance'
} %}

{# Vollansicht mit Filter-Bar #}
{% include '_components/_activity_feed_widget.html.twig' with {
    mode: 'full'
} %}
```

Verfuegbare Modi:

| Modus | Beschreibung |
|---|---|
| `compact` | Einfache Liste ohne Filter-UI; geeignet fuer Sidebar/Dashboard |
| `full` | Vollansicht mit Such- / Filter-Bar, Paginierung, Export-Button |

---

## CSV-Export

In der Vollansicht (`mode: full`) steht ein CSV-Export-Button bereit.
Der Export enthaelt alle gefilterten Events (max. 10.000 Zeilen).

Spalten: `timestamp`, `user_id`, `user_name`, `event_type`, `entity_type`,
`entity_id`, `entity_name`, `description`, `ip_address`.

> Der CSV-Export protokolliert selbst einen Audit-Log-Eintrag
> (`activity_feed.export_csv`) mit Nutzer, Zeitstempel und angewandten Filtern.

---

## Tamper-Evidenz und Audit-Trail-Verknuepfung

Der Activity-Feed ist eine Leseschicht ueber dem Audit-Log. Der Audit-Log
selbst ist HMAC-SHA256-gekettet (jeder Eintrag verweist auf den Hash des
Vorgaenger-Eintrags). Eine Manipulation ist damit nachweisbar.

Auditoren koennen aus dem Activity-Feed direkt auf den zugehoerigen
Audit-Log-Roheintrag springen ("Details im Audit-Log" Link pro Event).
Dort sind der HMAC-Hash und der verkettete Vorgaenger sichtbar.

---

## Retention

Events werden standardmaessig 24 Monate im Activity-Feed angezeigt. Der
Audit-Log darunter ist unbegrenzt gespeichert und kann per CLI abgefragt
werden.

```bash
# Rohen Audit-Log seit einem Datum abfragen
php bin/console app:audit-log:export --from=2026-01-01 --format=json > audit_export.json
```

---

## Verwandte Dokumente

- `docs/user-guide/MEIN_TAG.md` — Inbox-Integration des Activity-Feeds
- `docs/setup/AUDIT_LOGGING.md` — HMAC-Chain, technische Verifikation
- `docs/user-guide/PERSONA_DASHBOARDS.md` — Widget-Einbindung in Dashboards
