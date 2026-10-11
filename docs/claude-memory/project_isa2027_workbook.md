---
name: project-isa2027-workbook
description: "VDA ISA 2027 (78 Controls) analysiert — Lizenz ist CC BY-ND 4.0 + §9 (Volltext wäre shippable!), Importer frisst es, aber es fehlt eine Katalog-Versionsachse"
metadata: 
  node_type: memory
  type: project
  originSessionId: 8e0e42f3-414a-4eb2-8290-c650a1a95dad
  modified: 2026-08-12T22:06:32.593Z
---

Quelle: `~/Downloads/ISA2027-EN.xlsx` (EN-Workbook, 2026-08-13), analysiert am 2026-08-13.

**Lizenz — kippt die bisherige Prämisse.** Das ISA-2027-Workbook steht unter
**CC BY-ND 4.0**: „Share — copy and redistribute … **for any purpose, even
commercially**". Zusätzlich **Section 9 (nicht Teil der CC-Lizenz)** erlaubt
ausdrücklich die Weitergabe *modifizierter* Fassungen, wenn (1) klar als nicht
vom Lizenzgeber approbierte Modifikation markiert, (2) Lizenztext unverändert
mitgeliefert, (3) Logos/Marken des Lizenzgebers entfernt. Damit wäre der
**Volltext ausliefarbar** — im Gegensatz zur Annahme in
[[feedback-tisax-catalog-license]] und im Kopf von
`fixtures/library/frameworks/vda-isa-tisax-v6.yaml` („ENX-licensed Volltext is
NEVER shipped"). Entscheidung steht beim User; ISA 6 wurde nicht gegengeprüft
(Workbook liegt nicht vor).

**Katalogstruktur ISA 2027: 78 Controls** = 46 Information Security (Kap. 1–7)
+ 20 Prototype Protection (8.x) + 12 Data Protection (9.x).
Delta zu ISA 6 (80 im Repo): **72 unverändert, 6 neu** (6.1.3, 8.1.9–8.1.13),
**8 entfallen** (1.2.4, 8.3.1/8.3.2, 8.4.1–8.4.3, 8.5.1/8.5.2).
Referenz-Spalte liefert Anker für ISO 27001 (41 Controls, 69 A.x-Anker),
ISA/IEC 62443 (30) und NIST CSF 2.0 (34) — dieselbe Quelle, aus der schon das
ISA6→ISO-Mapping extrahiert wurde.

**Importer-Stand (empirisch, nicht gelesen-geraten):**
- `VdaIsaWorkbookParser` parst ISA 2027 **fehlerfrei**: alle 78 Controls über
  die drei Sheets, Header-Zeile 2, Spalten-Map inkl. `iso27001Ref` (48/78 gefüllt).
- `VdaIsaWorkbookValidator` prüft nur Format (≥30 Zeilen, ID-Regex) → 2027 besteht.
- Version-Gate im Wizard blockt nicht; es warnt nur bei `5.x`.
  Aber: `workbookVersion` wird bei 2027 als **NULL** gelesen und
  `workbookCompany` als **'0'** (Cover-Sheet-Felder liegen anders) → die
  „gehört zu anderer Organisation"-Warnung läuft ins Leere.
- **Kernproblem:** `TisaxRequirementMapper` legt unbekannte Control-IDs
  einfach **neu an** (create-if-missing). Ein ISA-2027-Upload landet damit im
  selben Framework `TISAX` (ISA-6-Katalog, 80 Zeilen), fügt die 6 neuen
  Controls hinzu und lässt die 8 entfallenen als Karteileichen stehen.
  Es gibt **keine Versionsachse** — User sagt aber, ISA 6 und ISA 2027 sind
  derzeit **beide zertifizierbar**, müssen also parallel laufen.
- ISA-6-Pfad ist an 5 Stellen hartkodiert: `TisaxCatalogueProvider`,
  `VdaIsaImporter`, `TisaxRebuildCatalogueCommand`, `LibraryImporterController`,
  `AlvaHint/Rule/Global/LibraryUpdatedRule`. Ein Parallelkatalog ist deshalb
  Design-Arbeit, kein Fixture-Drop-in.

**Umgesetzt (Commit 070471de4, Branch feat/alva-v2-ds-sync):** Katalog
`fixtures/library/frameworks/vda-isa-tisax-2027.yaml` (78 Nummern, Code
`TISAX-2027`), Versionsachse im `TisaxCatalogueProvider` (Default bleibt ISA 6,
`fixturePath()`/`normaliseVersion()` als SoT), `TisaxCatalogueVersionDetector`
(Signatur-Erkennung + Warnung bei Uneindeutigkeit), Wizard/Mapper/CLI
(`--catalogue-version`)/Admin-Importer verdrahtet, beide Parser-Defekte gefixt.
**Mappings (Commit b900d6ebf):** aus der Referenzspalte des Workbooks —
ISO 27001 (40 Controls/65 Paare), NIST CSF 2.0 (34/120), ISO 27017 (3/3),
plus Versions-Crosswalk ISA6→2027 (72 shared als `related`, 8 Gap-Marker,
6 neue dokumentiert). **62443 bewusst weggelassen**: 2027 zitiert 62443-2-1-
Klauseln, das bestehende ISA-6-Mapping nutzt ein anderes Schema → nur 1 von 51
löst auf. NIS-2 nicht abgeleitet (ENX-Analyse ist ISA-6-basiert).

**Volltext = Self-Upload des Worksheets** (User-Entscheidung 2026-08-13).
Trotz CC BY-ND + §9 wird der ISA-Volltext NICHT ausgeliefert; Kataloge bleiben
Nummern + Struktur.

**Registry-Lehre:** `FrameworkLoaderRegistry` löst genau EINEN Loader pro
Framework-Code auf. Ein `--catalogue-version`-Flag am ISA-6-Loader ließ
`TISAX-2027` unregistriert → alle 2027-Mappings wären tot gewesen. Fix:
eigener `LoadTisax2027RequirementsCommand`. Der Repo-Test
`MappingFrameworkCodeResolutionTest` fängt genau das ab.

**MRIS-Quercheck:** alle 31 Controls mit MRIS-Befund existieren im Workbook;
MRIS zählt 45 IS-Controls, das Workbook hat 46 (Zähldifferenz bei MRIS).
