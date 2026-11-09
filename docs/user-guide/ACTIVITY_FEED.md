# Activity-Feed — Benutzerhandbuch

Der Activity-Feed zeigt die letzten Ereignisse der eigenen Organisation in zeitlicher
Reihenfolge, neueste zuerst.

## Zugriff

`/de/activity-feed` (oder `/en/activity-feed`), für jede angemeldete Person (`ROLE_USER`).
Die Seite zeigt höchstens 50 Einträge.

## Datenquellen

| Quelle | Inhalt | Wer sieht es |
|---|---|---|
| **Audit-Log** | die letzten Einträge (Aktion, Objekttyp, Beschreibung, Person) | nur Admins (`ROLE_ADMIN`), wie die Audit-Log-Seite |
| **Workflows** | laufende Freigabe-Workflows | alle |
| **Dokumente** | zuletzt geänderte Dokumente | alle |
| **Risiken** | zuletzt geänderte Risiken | alle |

Jeder Eintrag zeigt Zeitpunkt, Person (oder „—“), Titel und, wo vorhanden, den Link zum Datensatz.
Audit-Log-Einträge haben keinen Link; die Einzelheiten stehen unter **Admin > Audit-Log**
(`/admin/audit-log`).

## Filter

`?scope=compliance` zeigt nur compliance-relevante Ereignisse (Audit-Log-Einträge zu Compliance-Objekten,
Dokument-Freigabe-Workflows, Dokumente; keine Risiken). Ohne Parameter oder mit `?scope=all` erscheinen alle
Quellen. Andere Werte gelten als `all`.

## Widget

`_components/_activity_feed_widget.html.twig` rendert die Liste (`items`, `max` (Vorgabe 25),
`showHeader`, `compact`). Das Haupt-Dashboard zeigt die letzten Aktivitäten über
`_components/_activity_feed.html.twig`.

## Audit-Log

Der Feed ist eine Leseschicht. Nachweis und Manipulationsschutz liefert das Audit-Log (HMAC-Kette, Prüfung
mit `app:audit-log:verify`, Aufbewahrung `app:audit-log:cleanup`), siehe `docs/setup/AUDIT_LOGGING.md`.

## Verwandte Dokumente

- `docs/user-guide/MEIN_TAG.md` — was auf mich wartet
- `docs/user-guide/PERSONA_DASHBOARDS.md` — Dashboards
