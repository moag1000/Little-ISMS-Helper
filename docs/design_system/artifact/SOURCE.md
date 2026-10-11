# Design-System-Artefakt — Snapshot

Das Design System von Little ISMS Helper (FairyAurora v4) wird als claude.ai
Design-System-Artefakt gepflegt:

- **Artefakt:** https://claude.ai/artifact/D2J6dQUWYUBjq3q7MMeA3D (privat)
- **Ursprung:** Claude-Design-Projekt `b635a91d-510c-4461-8c7d-53183b236bd1`
- **Snapshot-Stand:** Artefakt-Version `1791580445-691a` (2026-10-09)

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

Keine. Alle 71 Abweichungen des ersten Abgleichs sind entschieden (2026-10-09,
„alles wie empfohlen“, https://claude.ai/artifact/WurNH6u4qFLFVfuyryxCKh) und
auf beiden Seiten umgesetzt; die Baseline ist leer. Gestaltung (Kanon H1/H5,
D07, Personas) kam aus dem Artefakt, Technik (z-index-Stack, Schatten) aus dem
Repo; ungenutzte Typo- und Legacy-Tokens sind aus dem Artefakt entfernt.

Nicht vom Gate erfasst: Tokens, die nur das Repo kennt (u. a. Severity-Farben,
Status-Tints, Timing `--t-*`, `--target-*`, Bootstrap-Brücke `--bs-*`). Das Gate
prüft nur die Richtung Artefakt → Repo.

## Komponenten-Ebene (2026-10-11)

Das Gate prüft nur Tokens. Die Komponenten-CSS (Kanon 2026-09 H1–H5:
Notch nur am Primary-CTA, Meldung = Tönungsrahmen, Violett nur für ✦/Alva,
kein Glow in Ruhe) wurde einmalig per 3-Wege-Merge aus dem Artefakt nach
`assets/styles/fairy-aurora-components.css`, `premium.css`, `app.css`,
`admin-panel.css` und `fairy-aurora-responsive.css` übernommen. Bewusst nicht
übernommen: im Repo ungenutzte Blöcke (Tutorial, `fa-audit-timeline`,
Legacy-`fa-maturity-heat`), der `fa-form-modal`-Block (`in-page-forms.css` ist
dort maßgeblich), der umgebaute Command-Palette (`.fa-cmdp`, bräuchte neues
Markup) und die Icon-Liste (das Repo hat mehr Icons als das Artefakt).

Die Bootstrap-Brücken (`.btn`, `.alert`) sehen seitdem aus wie die
Aurora-Komponenten (`.fa-cyber-btn`, `.fa-alert`), ohne dass Templates
umgeschrieben werden mussten.
