# EU Framework Mapping — Prüf- und Verbesserungsplan

**Date:** 2026-05-30
**Status:** Design approved, ready for implementation
**Trigger:** External critique — EU framework mappings "zu schwach auf der Brust" (too thin/weak). Quality is the paramount constraint: if mappings are not true-to-life, the tool fails the end user.

---

## 1. Problem Statement

The mapping *engine* is mature. The mapping *content* is thin. Audit of the codebase found:

- **Data model is strong** — `ComplianceMapping` carries source/target requirement, percentage (0–150), confidence, provenance + URL, methodology, MQV lifecycle (draft→review→approved→published→deprecated), 6-dimension MQS quality score, `MappingGapItem` children, temporal versioning. Services exist for transitive coverage, inheritance, quality scoring.
- **Data is the weak link:**
  - EU pairs are thin: NIS2↔ISO ≈40 rows, DORA↔ISO ≈44, TISAX↔ISO ≈50, GDPR↔ISO27701 ≈27 — against source catalogs with hundreds of requirements each → low coverage.
  - **eIDAS** entirely absent (no entity, no mappings).
  - **EUCS** + **CRA** wizards exist but zero mapping rows.
  - Mappings are mostly unidirectional and all hub through ISO 27001. No direct DORA↔GDPR, NIS2↔DORA, NIS2↔GDPR.
  - New-law mappings (EU AI Act) flagged medium-confidence.

The critique spans **all four dimensions**: coverage breadth, mapping quality/defensibility, missing EU frameworks, and direction/cross-mapping. Strategy: **quality (depth) first, then breadth on top.**

---

## 2. Approach (selected: Hybrid)

Two layers, each does what it is good at:

- **Layer 1 — Mechanik (deterministic, no LLM).** Counts what is countable: coverage %, bidirectional symmetry, provenance completeness, confidence/MQS distribution, list of unmapped requirements, suspicious mappings. Reproducible, CI-capable, token-free. This is the quantitative *Prüf* baseline.
- **Layer 2 — Specialist (judgment, multi-agent workflow).** Decides what needs judgment: is an existing mapping percentage defensible against the actual norm text? Which missing mappings are high-value and citable? Each EU framework is audited by the correct specialist persona, against official ground-truth sources.

Rejected alternatives: *Specialist-only* (LLMs count badly, hallucinate metrics); *Mechanik-only* (cannot judge correctness or propose missing mappings). Hybrid spends specialist tokens only where a script cannot reach.

---

## 3. Quality Spine — Anti-Hallucination (the defining constraint)

Four rules govern every mapping finding in Layer 2. Quality > quantity, always.

1. **Ground-Truth obligation.** Every verdict ("ISO A.5.7 ↔ NIS2 Art.21(2)(a) = 80%") must cite (a) the real target-norm clause text, and/or (b) an official published crosswalk. Priority to official crosswalks already used as sources: ENISA NIS2 guidance, EBA/EIOPA/ESMA DORA RTS, the VDA-ISA workbook (see §5), ISO 27701:2019 Annex D, BSI Kreuzreferenz. **No crosswalk + no clause text = not a published mapping**, only a flagged hypothesis.
2. **Adversarial verify.** Specialist-1 judges → Specialist-2 (different lens/persona) attempts to *refute*, default skeptical. Only findings both support survive to publishable. Prevents plausible-but-wrong.
3. **Confidence gate = human.** Anything the specialist is unsure of → `reviewStatus: needs_human_review`, never auto-`published`. Lifecycle stays `draft`/`review`. Maps onto the existing MQV lifecycle + MQS gate.
4. **Cite or stay silent — but never blank.** An agent may not emit a percentage or clause ID from memory. Either it is grounded (WebSearch / fixture / catalog / workbook actually read) **or** it becomes a *reasoned hypothesis* (see §6) — explicitly flagged, never published as fact. Invented references are an error, not output.

**Effect:** the end user only ever sees citable mappings. Everything unproven is visible in a review queue (with a reasoned starting point) instead of masquerading as false certainty. Auditor-defensible.

---

## 4. Methodology — Two Layers Concrete

### Layer 1 — Mechanik
Script `scripts/quality/audit_eu_mappings.py`:
- **Input:** `fixtures/mappings/public/*.csv` + requirement catalogs (fixture / DB seed per framework).
- **Output per framework pair (`var/audit/<pair>_dossier.json`):**
  - **Coverage %** = mapped target reqs / total target reqs, plus list of unmapped requirement IDs.
  - **Bidirectional symmetry** = share of A→B with a matching B→A.
  - **Provenance completeness** = % of rows with valid source + URL (mandatory for lifecycle ≥ approved).
  - **Confidence / MQS histogram** = distribution low/med/high, MQS bins.
  - **Suspect mappings** = 100%/exceeds with low confidence, or missing rationale.
- Deterministic, reproducible, token-free, CI-gateable.

### Layer 2 — Specialist
Per framework, a dossier from Layer 1 → the right specialist persona:

| Framework | Specialist | Official crosswalk (ground-truth) |
|---|---|---|
| NIS2, DORA | isms-specialist | ENISA guidance, EBA/EIOPA/ESMA RTS |
| GDPR, eIDAS | dpo-specialist | ISO 27701:2019 Annex D, regulation text |
| TISAX | isms-specialist | **VDA-ISA workbook (see §5) — primary** |
| BSI C5, EUCS | bsi-specialist | BSI Kreuzreferenz, BSI C5 |
| CRA, EU AI Act | isms-specialist | EU regulation text directly |

Each specialist, two tasks:
- **(2a) Correctness audit (depth = quality):** existing mappings — is the percentage defensible against norm text? Is the rationale audit-proof? Flag wrong/overstated ones.
- **(2b) Breadth proposal (breadth = after quality):** Layer-1 gap list → which missing mappings are high-value and citable? Propose with percentage + source.

Every (2a)/(2b) finding passes the §3 spine: ground-truth cite → adversarial verify → confidence gate.

---

## 5. TISAX Ground-Truth — VDA-ISA Workbook

Real VDA-ISA 6.0.2 workbook on disk:
- **Primary (pre-filled):** `/Users/michaelbanda/ISA6_DE_6.0.2_CANCOM_27052026_Vorbefüllt_aktualisierte_Dokumente.xlsx`
- **Cross-check (blank, pure VDA reference, no customer data):** `/Users/michaelbanda/Downloads/ISA6_DE_6.0.2.xlsx`

Sheets carry the three TISAX tiers: **Informationssicherheit, Prototypenschutz, Datenschutz** — exact VDA-ISA 6 structure. Two columns make this gold-grade ground-truth:

1. **"Verweisung auf andere Normen"** — per ISA criterion, official cross-references to **ISO 27001:2022 + :2013, ISA/IEC 62443, NIST CSF, BSI-Standard 200-2, BSI IT-Grundschutz-Kompendium, NIST SP800-53r5** simultaneously. This is not merely TISAX↔ISO — it is a VDA-curated multi-standard mapping. One workbook enriches several framework pairs at once.
2. **"Mögliche Nachweise (nicht verbindlich)"** — evidence examples per criterion ("the evidence column"). Feeds the `auditEvidenceHint` MQV field and raises MQS (provenance + methodology dimensions: officially published + evidence-backed).

A TISAX extractor parses both columns. Stronger than WebSearch. The pre-filled workbook also exposes maturity data, but for *mapping* ground-truth only the criterion ID + reference + evidence columns are used (no customer data enters mappings).

### 5.1 Licensing constraint (hard rule)

The app may **NOT** ship the full VDA-ISA catalog — the requirement texts, the
"Anforderungen (muss/sollte)" prose, and the evidence-example prose are VDA
copyright. The TISAX extractor is therefore a **user-side tool**: the user points
it at *their own* licensed workbook. It is never bundled, and the workbook's
authored content never enters a shipped artifact.

What **is** allowed in the repo / shipped fixtures (facts, not copyrightable):
criterion **numbers** (`1.1.1`) and **mappings** (criterion-number → ISO 27001 /
NIST-CSF / BSI clause). What stays **local only** (`var/`, gitignored): requirement
texts and the evidence prose — usable as an audit-time quality signal on the
user's machine, never written into `fixtures/` or DB seeds. Concretely:
- `extract_workbook()` may return evidence (it runs locally), but any committed
  output (Wave-1 import into `fixtures/mappings/public/`) carries criterion-number
  → clause pairs ONLY — no evidence prose, no requirement text.
- The derived `tisax_catalog.json` holds criterion **numbers** only (no texts), so
  even though it lives in `var/`, it would be shippable if ever needed.

---

## 6. Human Queue — Reasoned Hypotheses (never blank)

Rule 4 of §3 means "do not publish as fact" — **not** "leave blank." Every `needs_human_review` item carries a reasoned best-guess so the reviewer confirms/rejects a hypothesis instead of researching from zero:

- **hypothesis_pct** — best estimate
- **reasoning** — the chain (keyword overlap, structural analogy, partial crosswalk)
- **uncertainty_reason** — exactly what is missing for a proof (no official crosswalk / ambiguous norm text / scope difference)
- **resolution_hint** — which source/clause would settle it
- **confidence_band** — low / med

Clear separation: **Fact** (verified → publishable) vs **reasoned hypothesis** (queue, with rationale). Fast queue.

---

## 7. Workflow Architecture

Per-framework pipeline (no barrier — slowest framework never blocks fastest):

```
phase 1  Mechanik baseline (inline, before workflow)
         → audit_eu_mappings.py → <pair>_dossier.json per framework

phase 2  Workflow, pipeline(frameworks):
  stage A  Specialist audit (correct persona)
           Input: dossier + ground-truth source (CSV / workbook / crosswalk URL)
           Tasks 2a correctness + 2b breadth proposal
           every finding MUST carry a ground-truth cite (§3 R1+R4)
           → schema: { confirmed[], suspect[], proposed[], hypotheses[] }

  stage B  Adversarial verify (different lens, parallel per finding)
           "Refute this mapping. Default: doubtful."
           re-read the source. ≥1 refute → demote to needs_human_review
           → schema: { verdict: hold|refute, evidence }

phase 3  Synthesis (inline, after workflow)
         all strands → plan findings + backlog CSV + finding table
```

Keys:
- **Pipeline not parallel** — TISAX (heavy workbook parse) does not block NIS2.
- **Stage B only on confirmed/proposed** — hypotheses skip verify, go straight to the human queue.
- **Structured output enforced (schema)** — no free-text waffle, directly usable.
- **Mechanik numbers never come from an agent** — coverage/symmetry from phase 1; the agent receives them only as context.

---

## 8. Deliverables

**Plan doc** — this file (§1–§7 as reference) plus, after the audit runs, a **finding table** per framework: Coverage % (IST), Provenance %, MQS median, # suspect, # hypotheses.

**Backlog CSV** `var/audit/eu_mapping_backlog.csv` — one row per proposed mapping change, directly convertible into the existing import flow (`ImportMappingCsvCommand`):

```
framework_pair, action(add|fix|remove), source_req, target_req,
proposed_pct, ground_truth_cite, confidence, verify_verdict,
human_review_needed, hypothesis_pct, reasoning, uncertainty_reason,
resolution_hint, confidence_band
```

**Wave roadmap:**
- **Wave 1 (quality / depth):** fix/remove suspect mappings, backfill provenance, TISAX workbook re-import with multi-standard refs + evidence column.
- **Wave 2 (breadth):** import high-value `proposed` mappings (raise NIS2 / DORA / GDPR coverage).
- **Wave 3 (gaps):** EUCS/CRA mappings, eIDAS framework (new), direct EU pairs (DORA↔GDPR, NIS2↔DORA, NIS2↔GDPR).

---

## 9. Acceptance Criteria — Definition of "wirklichkeitsnah"

Overall gate:
- Every *published* mapping change carries a verifiable ground-truth citation.
- Adversarial-verify passed **or** human-review-flagged — no middle ground.
- Wave 1: no `100%`/`exceeds` mapping remains without provenance.
- No invented norm citation (spot-check published rows against the cited source).
- Every `needs_human_review` item is non-blank — carries a reasoned hypothesis (§6).

Per-wave acceptance is measurable by the Layer-1 script (coverage %, provenance %, suspect count → 0 for resolved pairs).

---

## 10. Out of Scope

- OSCAL profile interchange (noted gap, not this effort).
- Auto-publishing any mapping without human sign-off on uncertain items.
- Touching the mapping *engine* / entities (model is sufficient) — this effort is content + audit, plus the one new `audit_eu_mappings.py` script and a TISAX workbook extractor.
- Importing customer/maturity data from the pre-filled workbook into mappings (only criterion ID + reference + evidence columns are used).
- Shipping any VDA-ISA requirement text or evidence prose (see §5.1 — only criterion numbers + mappings may be committed; the catalog itself is user-loaded).
