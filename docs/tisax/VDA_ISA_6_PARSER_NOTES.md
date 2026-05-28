# VDA-ISA 6 Workbook Parser Notes

Verified against real ENX ISA 6 workbooks (2026-05-28).

## Download URLs

| Locale | URL | Size (approx.) |
|--------|-----|----------------|
| English | https://portal.enx.com/isa6-en.xlsx | ~259 KB |
| German  | https://portal.enx.com/isa6-de.xlsx  | ~275 KB |

Both files are licensed under CC BY-ND 4.0 by ENX Association / VDA.
They are **not committed** to the repository. See `tests/fixtures/.gitignore`.

## Sheet Names

### EN Workbook (Version 6.0.3 | 2023-04-12)

| Sheet | Purpose | Parsed? |
|-------|---------|---------|
| Welcome | Introduction text | No (active sheet — skipped) |
| Cover | Company info fields | No |
| Maturity levels | Maturity level definitions | No |
| Definitions | Glossary | No |
| **Information Security** | IS controls (chapters 1–7) | **Yes** (first preferred match) |
| Prototype Protection | PP controls (chapter 8) | No (separate import) |
| Data Protection | DP controls (chapter 9) | No (separate import) |
| Results | Spider-web diagram | No |
| Examples KPI | KPI examples | No |
| License | License text | No |
| Change history | Version history | No |

### DE Workbook (Version 6.0.2 | 2024-04-04)

| Sheet | Purpose | Parsed? |
|-------|---------|---------|
| Willkommen | Begrüßungsseite | No (active sheet — skipped) |
| Deckblatt | Firmenangaben | No |
| Reifegrade | Reifegradebeschreibungen | No |
| Definitionen | Glossar | No |
| **Informationssicherheit** | IS-Kontrollen (Kapitel 1–7) | **Yes** (first preferred match) |
| Prototypenschutz | PP-Kontrollen (Kapitel 8) | No |
| Datenschutz | DS-Kontrollen (Kapitel 9) | No |
| Ergebnisse | Spinnennetz-Diagramm | No |
| Beispiele für KPIs | KPI-Beispiele | No |
| Lizenz | Lizenztext | No |
| Änderungshistorie | Versionshistorie | No |

**Key insight:** The active sheet is "Welcome"/"Willkommen" — NOT the content sheet.
The parser resolves the correct sheet via `PREFERRED_SHEETS` list, not the active sheet.

## Header Structure

Header row is **row 2** in all content sheets.
Row 1 = merged title banner ("Information Security Assessment\nQuestionnaire").

### EN "Information Security" sheet — row 2 verbatim column headers

| Col | Index | Header |
|-----|-------|--------|
| A   | 0     | `Row_Format` |
| B   | 1     | `Is_Title?` |
| C   | 2     | `Control number` → **controlId** |
| D   | 3     | `Maturity level` |
| E   | 4     | `Implementation description` |
| F   | 5     | `Reference documentation` |
| G   | 6     | `Findings/Assessment result` |
| H   | 7     | `Control question` → **titleEn** |
| I   | 8     | `Objective` → **description** |
| J   | 9     | `Requirements\n(must)` → **mustLevel** |
| K   | 10    | `Requirements\n(should)` → **shouldLevel** |
| L   | 11    | `Additional requirements\nfor high protection needs` → **highLevel** |
| M   | 12    | `Additional requirements\nfor very high protection needs` → **veryHighLevel** |
| N   | 13    | `Additional requirements for Simplified Group Assessments (SGA)` |
| O   | 14    | `Usual person responsible for process implementation` |
| P   | 15    | `Reference to other standards` → **iso27001Ref** |
| Q   | 16    | `Reference to implementation guidance` |
| R   | 17    | `Measures/recommendations` |
| S–V | 18–21 | Assessment metadata (date, responsible, contact) |
| W   | 22    | `Further information` |
| X–Z | 23–25 | Support examples (normal/high/very-high) |
| AA  | 26    | `Possible questions (examples, not mandatory)` |
| AB  | 27    | `Possible evidence (not mandatory)` → **evidenceHint** |

### DE "Informationssicherheit" sheet — row 2 verbatim column headers (selected)

| Col | Index | Header |
|-----|-------|--------|
| A   | 0     | `Row_Format` |
| B   | 1     | `Is_Title?` |
| C   | 2     | `Kontrollnummer` → **controlId** |
| H   | 7     | `Kontrollfrage` → **titleDe** |
| I   | 8     | `Ziel` → **description** |
| J   | 9     | `Anforderungen \n(muss)` → **mustLevel** |
| K   | 10    | `Anforderungen \n(sollte)` → **shouldLevel** |
| L   | 11    | `Zusatzanforderungen\nbei hohem Schutzbedarf` → **highLevel** |
| M   | 12    | `Zusatzanforderungen\nbei sehr hohem Schutzbedarf` → **veryHighLevel** |
| P   | 15    | `Verweisung auf andere Normen` → **iso27001Ref** |
| AB  | 27    | `Mögliche Nachweise (nicht verbindlich)` → **evidenceHint** |

## Section/Subsection Row Detection

Column A (`Row_Format`) is the key to skipping non-control rows:

- `Row_Format = "header"` → Section heading row (e.g. "1 IS Policies and Organization", "1.1 Information Security Policies") — **skip**
- `Row_Format = "control"` → Actual control row — **parse**
- `Row_Format = ""` → Also actual control row — **parse**

Control IDs additionally follow the pattern `N.N.N` (2+ dots). Bare integers (`1`, `2`) or
two-part IDs (`1.1`, `8.2`) are section/subsection markers — **skip**.

## Column Count

The "Information Security" sheet has up to **90 columns** (col CL). Most are empty for
self-assessment purposes; PhpSpreadsheet `getHighestDataColumn()` returns the last
non-empty column.

## Control ID Pattern

All leaf-level controls have IDs matching: `/^\d{1,2}(\.\d{1,2}){2}$/`
(e.g. `1.1.1`, `5.2.10`, `8.4.3`, `9.6.2`).

Chapters 1–7 = Information Security (IS).
Chapter 8 = Prototype Protection (PP).
Chapter 9 = Data Protection (DP).

The "Information Security" sheet contains chapters 1–7 only (46 controls in ISA 6).

## Known Quirks

1. **Active sheet mismatch** — active sheet is "Welcome", NOT the content sheet.
   The parser's `resolveSheet()` handles this via `PREFERRED_SHEETS` list.

2. **Row 1 is a merged title cell** — PhpSpreadsheet reads it as a single wide string.
   The parser correctly identifies row 2 as the header row.

3. **`Is_Title?` column (col B)** — boolean flag in the workbook, not a text label.
   The alias "title" must NOT appear in `titleEn` aliases — it would falsely match this column
   instead of "Control question" (col H).

4. **Multiline header cells** — `Requirements\n(must)` and `Anforderungen \n(muss)` contain
   a literal newline. PhpSpreadsheet preserves newlines in cell values.
   The aliases handle both the multiline version and substring fallbacks.

5. **`+` prefix DDE sanitization** — many requirement cells start with `+` (list items).
   These get an apostrophe prepended for DDE injection prevention. This is correct behavior;
   consumer code strips the leading `'` if needed.

6. **"Reference documentation" (col F) vs. "Reference to other standards" (col P)** —
   col F is the company's own reference documentation field (blank for assessment).
   col P has the actual ISO 27001:2022 / BSI references for each control.
   The alias "reference" must NOT be used for `iso27001Ref` — it matches col F first.

## Parser Versions Tested

| Workbook | Version | Date | Controls Extracted |
|----------|---------|------|--------------------|
| `isa6-en.xlsx` | 6.0.3 | 2023-04-12 | 46 |
| `isa6-de.xlsx` | 6.0.2 | 2024-04-04 | 46 |
