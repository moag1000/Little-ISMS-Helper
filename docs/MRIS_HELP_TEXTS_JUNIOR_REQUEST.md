# MRIS-Hilfetexte — Bedarfsliste aus Junior-ISB-Sicht

**Status:** Anfrage an Senior-Consultant zur Lieferung der konkreten Tooltip-/Glossar-Texte.
**Erstellt:** 2026-04-26 — Junior-Persona-Befragung im Rahmen der MRIS-Plan-Vollerfüllung.
**Lizenz Quellwerk:** Peddi (2026) MRIS v1.5 — CC BY 4.0.

## Hintergrund

Der Junior-ISB (ehem. QMB mit 9001-Erfahrung, neu auf 27001) wurde über das fertige
MRIS-Modul (Phasen 1-5) befragt. 20 Verwirrungspunkte identifiziert, daraus
3 Top-Blocker abgeleitet. Diese Liste ist der Leitfaden für den Consultant: für jeden
Eintrag soll der Senior-Berater den finalen Tooltip-/Inline-Hilfe-/Glossar-Text liefern,
den wir dann ins Tool einbauen.

## Bedarfsliste (20 Items)

| Konzept/Feld | Wo im Tool | Junior-Verwirrung | Hilfe-Format | Norm-Verweis |
|---|---|---|---|---|
| „Standfest" | SoA-Liste, Spalte „MRIS" | Klingt gut — heißt „gut" oder „mythos-resistent"? Bin ich fertig? | Tooltip | MRIS v1.5 Kap. 4 |
| „Teilweise degradiert" | SoA-Liste, MRIS-Filter | Klingt nach kaputt — soll ich abschalten? | Inline-Hilfe + Beispiel | MRIS v1.5 Kap. 5 |
| „Reine Reibung" | SoA-Liste, MRIS-Badge (rot) | Reibung wovon? Versteh ich gar nicht | Glossar + Beispiel-Control | MRIS v1.5 Kap. 6 |
| „Nicht betroffen" | SoA-Liste, MRIS-Spalte | = „Not Applicable"? Doppelt mit Anwendbarkeit? | Tooltip mit Abgrenzung | MRIS v1.5 Kap. 7 |
| MHC-01..13 Codes | SoA-Detail, AI-Agent-Listen | Codes ohne Klartext, kein Link | Hover-Tooltip + Detail-Link | MRIS v1.5 Kap. 9 |
| Reifegrad „Initial / Defined / Managed" | MHC-Detail-Page | Was unterscheidet Initial von „nichts"? | Beispiel pro Stufe | MRIS v1.5 Kap. 9.5 |
| Soll vs. Ist | MHC-Detail-Page | Welcher ist Pflicht? Darf Soll < Ist sein? | Inline-Hilfe + 9001-Analogie | — |
| „Mythos-Bedrohungslage" | Modul-Intro | Mythos = Märchen? Gefakte Bedrohungen? | Glossar prominent | MRIS v1.5 Kap. 1 |
| MTTC | `/mris/kpis` | Abkürzung ohne Auflösung | Tooltip + Formel | MRIS Kap. 10.6 |
| KEV-Patch-Latency | `/mris/kpis` | KEV ist unbekannt | Tooltip + Link CISA | CISA KEV Catalog |
| TLPT-Findings-Closure | `/mris/kpis` | TLPT = Pentest? | Tooltip | DORA Art. 26 |
| Phishing-resistente MFA | `/mris/kpis` | Ist nicht jede MFA gleich? | Tooltip mit Whitelist | NIST SP 800-63B |
| „auto" vs. „manuell" Badge | `/mris/kpis`-Kacheln | „manuell" = unbenutzbar? | Status-Hinweis | — |
| AI-Agent „Capability-Scope" | AI-Agent-Form | Liste? Freitext? Grenzen wovon? | Pflichtfeld-Beispiel + Vorlage | MRIS MHC-13 + ISO 42001 A.6.2 |
| AI-Risikoklasse | AI-Agent-Liste, Badge | Was ist Copilot? Hochrisiko? | Entscheidungs-Wizard | EU AI Act Art. 6 + Anhang III |
| Bedrohungsmodell-Doc | AI-Agent-Form | Wo lege ich das an? | Inline-Link + Vorlage | MRIS MHC-13 |
| Extension-Allowlist | AI-Agent-Form | Welche Extensions? VS-Code? Browser? | Tooltip + Beispiel | MRIS MHC-13 |
| Branchen-Baseline | Console-Command | Kein UI-Knopf — Shell-Pflicht? | UI-Button mit Dry-Run | — |
| Doku-Vollständigkeit % | AI-Agent-Liste | Was zählt zur Vollständigkeit? | Hover mit Pflichtfeld-Liste | EU AI Act Art. 11 |
| Flankierende MHCs | SoA-Liste (graue Codes) | Tipps oder Pflicht? | Tooltip mit Status | MRIS Kap. 9 |

## Top-3-Blocker (Junior-Originalton)

1. **„Reine Reibung" — was tu ich damit?**
   Bei 9001 hatten wir „nicht-wertschöpfende Tätigkeit → wegoptimieren". Hier hab ich
   ein Annex-A-Control im Audit. Darf ich's auf „nicht anwendbar" setzen? Wer entscheidet?
   → **Brauch eine Entscheidungsroutine** (Wenn-Reibung → Risk-Owner-Freigabe → SoA-Begründung).

2. **Reifegrad-Stufen ohne Beispiele.**
   Initial / Defined / Managed sagt nichts. Bei 9001/CMMI hatten wir 5 Stufen mit Audit-
   Beispielen.
   → **Brauch Evidence-Checklist-Snippet pro Stufe.**

3. **AI-Agent-Risikoklassifikation.**
   Soll Copilot, Cursor, ChatGPT-Enterprise einsortieren — keine Ahnung ob Hochrisiko.
   → **Brauch Entscheidungsmatrix mit Beispielen** (analog 9001-Lieferantenbewertung).

## Liefer-Anforderungen an Consultant

Pro Item:
- **Tooltip-Text** (DE + EN, max 200 Zeichen, Aktiv-Sprache)
- **Inline-Hilfe** (max 500 Zeichen, mit konkretem Beispiel) — wo passend
- **Glossar-Eintrag** (Definition + 9001-Analogie + Norm-Quelle) — wo Verständnislücke fundamental ist
- **Norm-Verweis** prominent (Klausel-ID + Versions-Stand)

## Format-Vorgabe

YAML-Datei `fixtures/mris/help-texts.yaml` mit Struktur:
```yaml
key: 'mris.help.standfest'
tooltip:
  de: '...'
  en: '...'
inline_help:
  de: '... mit Beispiel: ...'
  en: '... with example: ...'
glossar_entry:
  term: 'Standfest'
  definition_de: '...'
  analogy_9001_de: '...'
  source: 'Peddi (2026) MRIS v1.5 Kap. 4'
```

So kann das Tool die Texte deklarativ laden ohne Code-Änderungen pro Hilfetext.

---

**Quellenangabe (CC-BY-4.0):**
Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5.
Lizenz: Creative Commons Attribution 4.0 International.
