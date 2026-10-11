---
name: feedback-mapping-targets-key
description: "Library-Mappings mit pluralem `targets:` waren für Loader+Validator unsichtbar — bei neuen Fixtures immer gegen den echten Importer-Key prüfen"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8e0e42f3-414a-4eb2-8290-c650a1a95dad
  modified: 2026-08-12T21:37:31.809Z
---

`MappingLibraryLoader::load()` und `MappingValidatorService::validate()` lasen
ausschließlich den **singularen** `target:`-Key. Die sieben `tisax_to_*`-Fixtures
schreiben aber `targets: [...]` (Plural) — Ergebnis: Validator wertete jede Zeile
als „target=null ohne gap-relationship" → Fehler, Loader hätte alles geskippt.
**736 Control/Requirement-Paare waren ausgeliefert, aber nicht importierbar.**
Gefixt 2026-08-12 via `MappingValidatorService::expandEntries()` (beide Seiten
normalisieren; leere `targets: []` = kein Anker → verworfen statt Null-Marker)
+ Regressionstest `tests/Service/Library/MappingTargetsExpansionTest.php`.

**Why:** Gleiche Familie wie der Registry-Load-Path-Bug in
[[project-dach-eu-mapping-program]] — Fixture liegt da, sieht vollständig aus,
ist zur Laufzeit tot. Kein Test hat es gemerkt: die vorhandenen TISAX-Tests
prüfen nur Namensschema/IDs, nie ob der Importer die Zeilen lesen kann.

**Seit 2026-08-16 CI-gegated (Gate 60):**
`scripts/quality/check_fixture_unread_keys.py` vergleicht „Schluessel, die wir
ausliefern" gegen „Schluessel, die PHP-Code liest". 85 rein deskriptive Keys
(license_note, changelog, publisher, ...) sind baselined; ein NEUER ungelesener
Key bricht die CI. Damit faellt diese Defektklasse jetzt automatisch auf, statt
erst bei einer Handpruefung. Nebenbefund derselben Analyse: `cross_refs`
(2097 Eintraege, 55 Files) sind bewusst KEIN Import — sie zeigen ueberwiegend
auf das QUELL-Framework, ein Import erzeugte Quelle→Quelle-Kanten. Dokumentiert
in `docs/COMPLIANCE_CATALOG_ARCHITECTURE.md` §7.1.

**cross_refs geklaert (2026-08-23, PR #1030):** 93 % (1963/2097) loesen gegen
den korpusweiten ID-Bestand auf; die 134 Reste sind gueltige Zitate auf noch
nicht gemappte Stellen (DORA/DSGVO/BAIT/NIS2UmsuCG fachlich verifiziert).
Gepinnt via `tests/Service/Library/MappingCrossRefIntegrityTest.php`.
Zwei Beinahe-Fehlmeldungen dabei: (1) `27701-A.8.x`/`B.7.x` sehen gegen die
echte ISO-Annex-Konvention unmoeglich aus, sind aber das app-interne Schema
A=Controller / B=Processor mit eigener Nummerierung (siehe
`LoadIso27701RequirementsCommand`) — IMMER gegen den echten Loader pruefen,
bevor man Katalog-IDs als defekt meldet. (2) `§30` (NIS2UmsuCG) vs `§ 30`
(KRITIS): die Schreibweise ist in den KRITIS↔NIS2UmsuCG-Mappings das einzige
Unterscheidungsmerkmal der beiden Gesetze — pauschale Normalisierung deutet
Zitate still um. Nicht anfassen.

**How to apply:** Bei jeder neuen Mapping-/Katalog-Fixture einmal den echten
Konsumenten-Code auf den erwarteten Key lesen (nicht die Nachbardatei kopieren),
und einen Test schreiben, der *Importierbarkeit* prüft, nicht nur Struktur.
Discovery selbst ist hier ein glob über `fixtures/library/mappings/*.yaml` mit
Filter auf `library.target_framework` + `lifecycle.state != deprecated` —
kein Registry-Eintrag nötig.
