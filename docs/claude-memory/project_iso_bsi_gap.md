---
name: project_iso_bsi_gap
description: "ISO 27001 → BSI IT-Grundschutz Gap-Feature + WS-5b Mapping-Qualität — beide gemerged 2026-06-11/12 (#924,"
metadata: 
  node_type: memory
  type: project
  originSessionId: 967ef94c-1958-4208-8742-d32c1c5a3832
---

**Gemerged:** #924 (Kern-Feature) + #926 (WS-5b Mapping-Qualität). Spec: `docs/superpowers/specs/2026-06-10-iso27001-bsi-grundschutz-gap-correctness-design.md` (v2, verschlankt — Reuse statt Rebuild). Plan: `docs/superpowers/plans/2026-06-10-iso27001-bsi-grundschutz-gap.md`.

**Was es tut:** Tenant mit gepflegtem ISO 27001 sieht ehrlich + geführt was für BSI IT-Grundschutz (nach gewähltem Absicherungsniveau) noch fehlt. Route `app_compliance_cross_gap` (`/compliance/cross-gap`, NICHT explizit `/{_locale}/` — Auto-Prefix!), `IsoToBsiGapService::buildGap()` → 6-State (gedeckt/partiell/ISO-offen/ungemappt-unbewertet/-eigenständig) + getrennte **Trust-Achse** + 4 Aktions-Buckets (Erledigt/Quick-Win-über-ISO/echte-BSI-Arbeit/prüfen). Tenant-scoped Fulfillment, evidenz-rückführbar, `data-tour-step`-Hooks (tour-ready).

**Trust-Tiers (provenanceSource):** `amtlich` (official_bsi_crosswalk, offizielle BSI-CRT-PDF 6.Ed.2023, 119 Pairs) > `amtlich_gestuetzt` (crt_corroborated, WS-5b-1) > `ki_validiert` (panel) > `bestaetigt` (manual/confirmed) > `heuristisch`. trustOf() in IsoToBsiGapService. heuristisch+Coverage → Bucket „prüfen" (Coverage-State bleibt erhalten, nicht versteckt).

**WS-5b Mapping-Qualität:** 182 heuristische Mappings → 38 CRT-korroboriert + **133 panel-validiert** (4-Personas-Konsens-Workflow, build-time) + 2 deprecated (Artefakte) + 9 needs_review. Verdikte-Fixture `fixtures/library/mappings/panel_verdicts/`, `app:bsi:corroborate-mappings` + `app:bsi:apply-panel-verdicts`. ISO↔BSI ist many-to-many → die meisten „Residuen" waren echte Sekundär-Korrespondenzen.

**OFFEN (Folge-Specs / auf User-Wort):**
- Interaktive **Use-Case-Tour** „Nach ISO → BSI-Gap finden" = eigenes Folge-Spec, dockt an die `data-tour-step`-Hooks (Level-Selektor, Bucket-Tiles, Review-Queue-Button, erste Gap-Card). Fundament bewusst tour-ready gebaut.
- **CRT-Pairing-Stichprobe** (menschlich): die 119 amtlichen Paarungen wurden per `pdftotext` aus der BSI-PDF extrahiert — Coverage verifiziert (alle 93 Annex-A + Clauses 4-10), aber jede Paarung sollte stichprobenartig gegen die PDF (2-Spalten-Tabelle, Alignment-Risiko).
- Kern-Asset-Scope-Reduktion (kern-Level zeigt aktuell alle hoch-Anf), präzises Aufwandsmodell (v1=grob tier-basiert via baseEffortDays).

**Vokabular (WS-1-Konsolidierung, wichtig):** Requirement-**Tier** = `absicherungsStufe` basis/standard/**hoch** (kanonisch, `anforderungsTyp` deprecated, `kern`→`hoch` normalisiert). Tenant-**Level** (Vorgehensweise) = `Tenant.bsiAssuranceLevel` basis/standard/**kern**. `AbsicherungsStufe::tiersForLevel()` mappt Level→Tier-Set. Eine Fulfillment-Quelle: tenant-scoped `ComplianceRequirementFulfillment`.
