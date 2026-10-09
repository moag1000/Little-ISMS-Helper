# Design-System-Artefakt — Snapshot

Das Design System von Little ISMS Helper (FairyAurora v4) wird als claude.ai
Design-System-Artefakt gepflegt:

- **Artefakt:** https://claude.ai/artifact/D2J6dQUWYUBjq3q7MMeA3D (privat)
- **Ursprung:** Claude-Design-Projekt `b635a91d-510c-4461-8c7d-53183b236bd1`
- **Snapshot-Stand:** Artefakt-Version `1791578758-4a78` (2026-10-09)

Der Artefakt-Link ist privat, CI kann ihn nicht abrufen. Darum liegt hier ein
Snapshot seiner Token-Schicht. Er ist die Referenz, gegen die
`scripts/quality/check_design_tokens_sync.py` (CI-Gate 61) die App prüft.

| Datei | Im Artefakt | Inhalt |
|---|---|---|
| `tokens.css` | `project/tokens.css` | daraus generierte CSS-Custom-Properties |
| `fairy-aurora.css` | `project/assets/styles/fairy-aurora.css` | importiert `tokens.css` und deklariert die übrigen Tokens |

Dateien hier nicht von Hand ändern — sie sind eine Kopie.

## Synchronisieren

**Artefakt → Repo** (das Design hat sich geändert):

1. In einer Claude-Sitzung sagen: „Design System ins Repo synchronisieren".
2. Claude liest die zwei Dateien aus dem Artefakt, ersetzt den Snapshot hier und
   aktualisiert die Versionszeile oben.
3. `python3 scripts/quality/check_design_tokens_sync.py --baseline scripts/quality/baselines/design_tokens_sync.txt`
   zeigt jede neue Abweichung. Pro Abweichung entweder `assets/styles/` angleichen
   oder sie bewusst in die Baseline aufnehmen.
4. PR wie gewohnt; CI-Gate 61 prüft dasselbe.

**Repo → Artefakt** (ein Token wurde in der App geändert oder neu angelegt):
den Wert im Artefakt nachziehen,
dann wie oben den Snapshot erneuern. Danach den Baseline-Eintrag löschen.

## Bekannte Abweichungen

Die Baseline `scripts/quality/baselines/design_tokens_sync.txt` listet die beim
ersten Abgleich gefundenen Abweichungen (71). Die Entscheidung, welche Seite
jeweils gilt, ist dokumentiert unter
https://claude.ai/artifact/WurNH6u4qFLFVfuyryxCKh. Jeder erledigte Punkt
entfernt seine Zeilen aus der Baseline.

Nicht vom Gate erfasst: Tokens, die nur das Repo kennt (u. a. Severity-Farben,
Status-Tints, `--target-*`, `--z-overlay`, Bootstrap-Brücke `--bs-*`). Sie
fehlen im Artefakt und werden dort ergänzt.
