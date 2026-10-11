---
name: feedback-nonconformity-modeling
description: "B4 decision: AuditFinding.type-enum + separate CorrectiveAction-Entity ist audit-defendable. Kein separates Nonconformity-Entity-Split nötig."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: f94a1c13-01b9-4ace-8321-f7d76cfa1b21
---

ISO 27001 Cl. 10.1–10.2 / ISO 19011 Cl. 6.4.8 / ISO 17021 Cl. 9.4.8 sind erfüllt durch:
- `AuditFinding.type` Enum (observation / opportunity / minor_nc / major_nc)
- nc-spezifische Felder auf AuditFinding (nonconformityDetails JSON, ncRootCauseSummary, ncCorrectionDueDate, ncVerifiedAt, ncVerifiedBy) — nur sichtbar wenn `isNonconformity()`
- Separates `CorrectiveAction`-Entity mit 7-State-Lifecycle + previousCapa-Self-FK für ineffective-Chain

CAPA-Split ist also schon da — nur nicht über separate Nonconformity-Tabelle, sondern über CorrectiveAction.

**Why:** User-Entscheidung 2026-05-27. Senior-Consultant-Audit ergab dass aktuelles Hybrid-Modell audit-defendable ist. Refactor-Aufwand 3-5d ohne konkreten ROI.

**How to apply:**
- Neue ISO-Anforderungen → erst prüfen ob via `nonconformityDetails` JSON oder CorrectiveAction.* abgedeckt
- Form-Layer: nc_capa Section nur bei `isNonconformity()`
- Type-Gating für CAPA-Pflicht: NCs forcieren Root-Cause, OFIs/Observations bleiben CAPA-frei
- Refactor zu separater Nonconformity-Entity nur bei: (1) Auditor-Beanstandung, (2) Post-Closure-Immutability als Hard-Req, (3) Multi-Org-Report Segregation

**ADR:** `docs/decisions/2026-05-27-nonconformity-modeling.md`
**Closed:** B4 item from [[project-backlog-2026-05-25]]
