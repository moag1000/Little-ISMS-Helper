# EU Framework Mapping Audit — Report

**Date:** 2026-05-30
**Scope:** NIS2, DORA, GDPR cross-framework mappings (Layer-1 mechanik + Layer-2 specialist audit). TISAX handled separately (workbook-derived, see below).
**Method:** `scripts/quality/audit_eu_mappings.py` (deterministic baseline) + `scripts/workflows/eu_mapping_audit.workflow.js` (37 specialist + adversarial-verify agents, 2.08M tokens, WebSearch against EUR-Lex / ENISA / ISO).
**Spec:** `docs/superpowers/specs/2026-05-30-eu-framework-mapping-audit-design.md`
**Backlog (machine-readable):** `var/audit/eu_mapping_backlog.csv` (60 rows, gitignored — regenerate via `--synthesize`).

---

## Verdict: the critique is correct, and now evidenced

The mapping *engine* is sound; the mapping *content* is thin and, in places, wrong. The audit produced specific, clause-cited findings — not opinions.

| Framework | Coverage (Layer-1) | Confirmed | Suspect (overstated/wrong) | Proposed (gap-fill) | Hypotheses | Refuted by 2nd pass |
|---|---|---|---|---|---|---|
| NIS2 | 100% (10/10 a–j) | 6 | 3 | 4 | 3 | 7 |
| DORA | 90% (Art.19/27 open) | 4 | 3 | 4 | 3 | 8 |
| GDPR | 87% (33/34/37 open) | 9 | 8 | 7 | 6 | 14 |

Systemic Layer-1 findings: **provenance 0%** (no `provenance_url` in any source CSV — the "Belegbarkeit" gap, confirmed mechanically) and **zero full-100 mappings** (every row partial — depth thinness).

Only **5 mappings survived double-skeptic verification** and are directly importable; the other 55 backlog rows carry reasoned notes and go to the human review queue. That conservative split is by design — nothing auto-published without a citation that a second skeptical specialist could not refute.

---

## Headline findings (evidence-backed)

### GDPR — data-subject-rights are mapped to the WRONG ISO 27701 controls (systematic)
The existing `gdpr_iso27701_v1.csv` maps Articles 15–18 to the **Privacy-by-Design** cluster (ISO 27701 A.7.4.x) instead of the **data-subject-rights** cluster (A.7.3.x):
- GDPR-15 (right of access) → A.7.4.1 *"Limit collection"* — wrong; belongs on A.7.3.x.
- GDPR-16 (rectification) → A.7.4.2 *"Limit processing"* — wrong.
- GDPR-17 (erasure) → A.7.4.3 *"Accuracy and quality"* — wrong; erasure is A.7.3.6.
- GDPR-18 (restriction) → A.7.4.4 *"PII minimization objectives"* — wrong.

This is the clearest "zu schwach auf der Brust" instance: four mappings rated 90–95% are mistargeted. **Action: fix_pct + re-target to A.7.3.x.**

### DORA — overstated 95% percentages and mis-paired articles
Second-pass verification against EUR-Lex Reg. (EU) 2022/2554 refuted every stage-A "confirmed" DORA mapping as overstated:
- Art.11 *"Response and recovery"* → A.5.24 @95% — A.5.24 is incident-mgmt *planning*; category overstatement.
- Art.10 *"Detection"* → A.5.29 / ISO 22301 §8.4 @95% — both are **business-continuity** controls, wrong function/phase for a detection article.
- Art.28 *"ICT third-party risk"* → A.5.19 @95% one-to-one — scope-overstated.

**Action: fix_pct down + re-pair.** All flagged with clause-cited evidence in the backlog.

### NIS2 — multi-limb measures rated as "full" on a single control
- Art.21.2.g (basic cyber hygiene **and** training) → A.6.3 @95% — A.6.3 covers only the *training* limb; hygiene spans A.8.7/8.8/8.9/8.22/5.15ff. **fix_pct.**
- Art.21.2.b (incident handling, full lifecycle) → A.5.24 @95% — A.5.24 is *planning/preparation* only. **fix_pct.**
- Art.21.2.f (assess effectiveness) → A.8.8 @70% — topical stretch; effectiveness lives in Clause 9.1 / A.5.35-36. **remove.**

---

## Gap-fills proposed for the unmapped requirements (grounded)

- **DORA Art.19** (major-incident reporting) → A.6.8 (reporting events) @70%, A.5.24 @65%, A.5.26 @55%.
- **DORA Art.27** (TLPT) → A.5.19 (supplier suitability of testers) @45% + A.8.8 (vuln mgmt).
- **GDPR-33/34** (breach notification to authority / data subjects) → A.5.5, A.5.24, A.5.26, A.6.8, ISO 27701 6.13.1.5.
- **GDPR-37** (DPO) → ISO 27701 6.3.1.1 @80%, A.5.2 @60%.
- **NIS2 breadth** (already-covered points, missing high-value controls): Art.21.2.e → A.8.8 (vuln handling, explicit in clause text); Art.21.2.i → A.8.3 (access restriction) + A.5.10 (acceptable use); Art.21.2.j → A.5.14 (information transfer).

---

## Directly importable (double-verified, 5)
These passed both the specialist and the adversarial refuter with a verbatim clause cite — Wave-1 ready:
1. NIS2 Art.21.2.h → ISO 27001:2022 A.8.24 (cryptography) @95% — verbatim 1:1.
2. NIS2 Art.21.2.d → A.5.19 (supplier relationships) @90%.
3. NIS2 Art.21.2.e → A.8.8 (vulnerability handling — explicit in clause) @85% — new mapping.
4. GDPR-6 → ISO 27701 A.7.2.2 @95%.
5. GDPR-21 → ISO 27701 A.7.3.5 @90%.

---

## TISAX (handled separately — no LLM audit needed)
The user's licensed VDA-ISA 6.0.2 workbook is the official ground truth. `tisax_extract` pulled **45 criteria → 67 ISO 27001 mappings** (+ NIST-CSF, BSI) from the "Verweisung auf andere Normen" column. These are import-ready as-is (criterion-number → clause). **Licensing:** only numbers + mappings ship; VDA-ISA requirement/evidence prose stays user-local (`var/`). See spec §5.1.

---

## Wave roadmap (next, off this backlog)

- **Wave 1 — quality/depth:** apply the `fix_pct` + `remove` rows (GDPR re-targeting A.7.4→A.7.3, DORA de-overstating, NIS2 multi-limb), backfill `provenance_url` on every retained row, re-import TISAX workbook mappings. Acceptance: no 100/exceeds without provenance; suspects → 0 (re-run `audit_eu_mappings.py`).
- **Wave 2 — breadth:** import the verified + reviewed proposed gap-fills (DORA Art.19/27, GDPR-33/34/37, NIS2 breadth controls). Acceptance: coverage up, gaps closed.
- **Wave 3 — gaps/cross-pairs:** EUCS/CRA mappings, eIDAS framework, direct EU pairs (DORA↔GDPR, NIS2↔DORA).

## Human review queue (55 rows)
Each `human_review_needed=yes` backlog row carries the refuter's clause-cited reasoning (for refuted items) or `hypothesis_pct` + `reasoning` + `uncertainty_reason` + `resolution_hint` (for hypotheses). The reviewer confirms/rejects a reasoned starting point — not a blank. The high refute rate (esp. DORA 8/8) reflects genuinely overstated existing percentages, not over-zealous verification (spot-checked against EUR-Lex).
