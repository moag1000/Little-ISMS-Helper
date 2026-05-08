# Policy-Wizard — Plan Index

Comprehensive implementation plan for the Policy-Wizard feature.
9268 lines of input across 4 phases. Use this README as the entry
point.

## Reading order

1. **Start here** — `00-README.md` (this file): index + executive summary
2. **Architecture** — `05-architecture.md`: domain model, services,
   wizard flow, hierarchy, SoA integration, approval workflow,
   versioning, auditor-trap mitigations, sector overlays. The single
   load-bearing document.
3. **Sprint roadmap** — `07-phase4-sprint-reconciliation.md`: 7-sprint
   breakdown reconciling all persona priorities. Read this before any
   implementation work.

## Specialist inputs (Phase 1)

- `01-iso27001-input.md` — 24 ISO 27001 topic policies (669 lines)
- `02-bsi-input.md` — 28 BSI Grundschutz Richtlinien + Schutzbedarf
  Methode (1258 lines)
- `03-dora-input.md` — DORA addon: 6 NEW + 18 EXTENDS over ISO
  baseline (817 lines)
- `04-bcm-input.md` — 13 BCM governance docs (ISO 22301 + BSI 200-4,
  1051 lines)
- `06-dpo-input.md` — Privacy / GDPR / ISO 27701 with Decision Matrix
  v2 collapsing 16 documents into 5 standalone + 8 sections + 1 thin
  host (1198 lines)

## Persona reviews (Phase 3)

`persona-reviews/`:
- 01-ciso-review.md (CISO Executive)
- 02-compliance-manager-review.md (Compliance Manager)
- 03-senior-consultant-review.md (Senior ISMS Consultant)
- 04-junior-implementer-review.md (Junior ISMS Implementer)
- 05-isb-practitioner-review.md (ISB Practitioner / DE Mittelstand)
- 06-external-auditor-review.md (External Certification Auditor)
- 07-risk-owner-review.md (Risk-Owner Business / Heads-of-Function)
- 08-ux-review.md (UX Specialist)
- 09-dpo-self-review.md (DPO Specialist self-critique)

## Decisions log (Phase 4 outputs)

The architecture in `05-architecture.md` already incorporates the
following P1 changes synthesised from persona feedback. No further
action needed before W1.

### Audit-readiness (top priority — auditor blockers)

| Change | Source | Architecture section |
|---|---|---|
| Climate-change wording HARDCODED ON for ISO 27001 | Auditor | §6 Step 2 |
| Variable-substitution markers HIDDEN in rendered docs | Auditor | §11.2 |
| Generation-to-approval min-elapsed time (24h regulated) | Auditor | §11.6 |
| Random-sample post-substitution validator (1-in-10) | Auditor | §11.7 |
| Top-level Information Security Policy excluded from bulk | Auditor | §9.2.1 |
| Dual-signoff DEFAULT-ON for regulated scope | Auditor | §9.2.1 |
| Bulk batch cap ≤10 documents | Auditor | §9.2.1 |
| Mandatory rationale ≥200 characters | Auditor | §9.2.1 |
| `PolicyAcknowledgement` entity (closes A.6.3 NC) | Auditor | §4.1 |
| PolicyAcknowledgement-coverage Alva-Hint (≥95%) | Auditor | §11.8 |

### Konzern-hierarchy

| Change | Source | Architecture section |
|---|---|---|
| Override-mode rename: forbidden_to_change / forbidden_to_relax / floor_only / ceiling_only / free | ISB | §7.3 |
| Konzern push-down trigger via Alva-Hint Tier-1 | ISB+CISO | §7.4 |
| Konzern-Defaults wizard variant (parent baseline push) | CISO | §7.4 |
| `settings_drift_detected` badge on subsidiary | ISB | §7.4 |

### Operator UX

| Change | Source | Architecture section |
|---|---|---|
| Targeted-re-run modus (Mode 2) for partial fixes | ISB | §6.3 |
| Sandbox preview modus (Mode 3) | UX | §6.4 |
| Risk-appetite-tier direction explicit (1=conservative) | Junior | §6 Step 4 |
| Review-interval hard-cap 24 months | Junior | §6 Step 4 |
| Self-approval guard | Junior | §6 Step 3 |
| Hierarchy-conflict jump-to-anchor + "request override" valve | UX | §6 Step 7 |
| Step 5a/5b split for DORA wizard-within-wizard | UX | §6 Step 5 |

### Function-Owner / business-side

| Change | Source | Architecture section |
|---|---|---|
| `PolicyTemplate.affectedFunctions` field | Risk-Owner | §4.1 |
| `WizardRun.affectedFunctions` field | Risk-Owner | §4.1 |
| `function_owner_review` workflow step | Risk-Owner | §9.1 |
| Bulk inbox grouping by `affected_function` | Risk-Owner | §9.2 |
| Function-Owner ack must complete before bulk-batch | Risk-Owner | §9.2.2 |

### Privacy / DPO (Decision Matrix v2)

| Change | Source | Document |
|---|---|---|
| Lawful-Basis + Consent → RoPA-Methodology sub-procedures | DPO Self | 06-dpo §0 |
| Children's + Special-Category → Privacy-Policy appendices | DPO Self | 06-dpo §0 |
| A.5.34 thin host (was suppressed) | DPO Self | 06-dpo §0 |
| Cookie/ePrivacy explicit OUT-OF-SCOPE | DPO Self | 06-dpo §0 |
| AI Act deferred to Phase 1-F (out of v1) | DPO Self | 06-dpo §0 |
| `dpo_section_required` flag + per-section sub-state machine | DPO Self | 06-dpo §0.A |
| Sectoral DPO templates (Healthcare § 22 BDSG, FinServ DORA) | DPO Self | 06-dpo §0 |
| Lead-DPA logic for multi-EU-state Konzern | DPO Self | 06-dpo §0 |

## v1 Scope frozen

**Standards:** ISO 27001 (W2 baseline) → DORA addon (W4) → BSI
Grundschutz (W5) → BCM ISO 22301 + BSI 200-4 (W5) → GDPR-section
pattern + 5 standalone privacy docs (W6).

**Document upper bound (quintuple stack):** ~52 governance documents.

**Translation budget:** ~5650 keys per language.
- Legal agency canonical EN+DE: ISO + DORA + BCM (~2630 keys)
- In-house DE-source: BSI templates (~1680 keys)
- In-house EDPB-base: Privacy (~700 keys)
- Product i18n: UI / wizard chrome (~560 keys)

## v1 Out-of-scope (deferred to v2)

Explicit list in `07-phase4-sprint-reconciliation.md` §4. Highlights:
- DORA CTPP-mode (Critical Third-Party Provider)
- AI Act Reg. EU 2024/1689 addon (Phase 1-F)
- Mobile sign-off
- ISO 27701:2025 dual-template support (ship :2019 + :2025-deltas)
- Microenterprise-fork for non-DORA tenants

## Implementation gates (must be true before W1 starts)

10 prerequisites in `07-phase4-sprint-reconciliation.md` §7.
Highlights:
1. `TenantBranding` entity exists for letterhead-PDF (W7).
2. `ROLE_GROUP_BCM_OFFICER` + `ROLE_FUNCTION_OWNER` added to RBAC.
3. `PasswordPolicyResolver` lifted to generic
   `TenantSettingResolver<T>`.
4. Defang test fixture (4 hardcoded bulk-approval rules).
5. `TenantPolicySettingChangeAttempt` log table.
6. Translation-agency contracted OR in-house plan locked.
7. Architecture §3 doc-count math validated by DPO.
8. Existing `Workflow` entity supports parallel-approver pattern.
9. Existing `Document` entity has `supersedes` + `isImmutable` fields.
10. `Compliance-Wizard` infrastructure ready for new check-types.

## 7-Sprint roadmap (one-line per sprint)

```
W1 — Domain + audit-readiness baseline   (entities + 4 defangs hardcoded)
W2 — Wizard core (ISO 27001 only)        (7-step + sandbox + Function-Owner)
W3 — Doc-gen + SoA + Konzern-Defaults    (DocumentGenerator + push-down)
W4 — DORA + Compliance-Wizard checks     (6 NEW + 18 EXTENDS + IndustryPresetBundle)
W5 — BSI Grundschutz + BCM               (28 BSI + 13 BCM + auto-BCExercise)
W6 — DPO Phase + GDPR-section pattern    (5 standalone + 8 sections + DPO veto)
W7 — Polish, exports, evidence layer     (PDF + ZIP + Konzern roll-up dashboard)
```

Total: 7 sprints × 3-4 weeks = ~5-7 months elapsed for a 2-engineer
team. Translation effort runs parallel from W3 onward.

## Open questions (deferred to W1 kickoff)

- Translation-agency vendor selection (cost + quality + DE-native).
- Konzern-Defaults UX vs Konzern-CISO single-tenant view —
  decided by UX team before W3.
- AI Act addon scope: Phase 1-F separate consultation in v1.x,
  not v2.

## Status

Phase 1 — Specialist input ✅
Phase 2 — Architectural synthesis ✅
Phase 3 — Persona reviews (9 agents) ✅
Phase 4 — Architecture P1 fixes + DPO Matrix v2 + Sprint
          reconciliation ✅
Phase 5 — Plan index + final commit ✅ (this file)

**Ready for W1 implementation.** The branch `feature/policy-wizard`
is the integration target.
