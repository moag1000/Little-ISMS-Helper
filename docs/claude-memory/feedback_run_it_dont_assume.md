---
name: feedback-run-it-dont-assume
description: "Grüne Unit-Tests belegen keine Funktionsfähigkeit — Fixture-Import und UI erst gegen echte DB + Browser fahren, das findet Defekte, die keine Suite sieht"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8e0e42f3-414a-4eb2-8290-c650a1a95dad
  modified: 2026-08-13T19:04:31.508Z
---

Am 2026-08-13 die TISAX/ISA-2027-Arbeit auf Wunsch komplett durchgeklickt
(Scratch-DB `lih_verify`, `symfony serve`, Playwright-Login). Trotz 1386 grüner
Tests, sauberem PHPStan und eigenen Integritätstests kamen **vier Defekte** raus,
die keine Suite gesehen hatte:

1. `Column 'mapping_type' cannot be null` — `MappingLibraryLoader` leitet die
   NOT-NULL-Spalte nur über `setMappingPercentage()` ab und rief das nur bei
   vorhandenem `relationship`. 149 ausgelieferte Mapping-Paare waren damit
   nicht importierbar. Fällt nur beim echten Import gegen eine DB auf.
2. Admin-Import-Button ohne Wirkung: POST endete in `render()`, Turbo verwirft
   das ("Form responses must redirect to another location"). Der Import lief,
   die Seite blieb stumm.
3. Skelett-Zweig im Template **ersetzte** den Import-Button statt ihn zu
   ergänzen → Katalog wäre nur per CLI ladbar gewesen.
4. Validierungsseite nannte die erkannte Katalog-Version nicht und zitierte für
   jedes Workbook die ISA-6-Größe ("ca. 80").

**Why:** Unit-Tests prüfen Struktur, nicht Verdrahtung. NOT-NULL-Spalten,
Turbo-Redirect-Verträge, Template-Verzweigungen und Text-Hardcodings sind erst
im laufenden System sichtbar.

**How to apply:** Bei Fixture-/Katalog-/Import-Arbeit immer einmal echt fahren:
Scratch-DB anlegen (`doctrine:schema:update --force --complete`),
`app:create-screenshot-user`, `var/setup_complete.lock` anlegen (sonst
Setup-Redirect), `symfony serve`, dann Import-Kommandos UND den UI-Pfad klicken.
Danach DB droppen und `.env.local` im Worktree entfernen. Siehe auch
[[feedback-mapping-targets-key]] — gleiche Familie: sieht vollständig aus, ist
zur Laufzeit tot.
