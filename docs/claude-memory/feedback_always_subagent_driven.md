---
name: feedback_always_subagent_driven
description: "Implementierungspläne IMMER subagenten-getrieben ausführen (superpowers:subagent-driven-development), nicht inline."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 69475b3e-e0e1-42e0-9a07-b613585f0984
---

User-Präferenz: Implementierungspläne **immer** mit
`superpowers:subagent-driven-development` ausführen — frischer Implementer-Subagent
pro Task + 2-Stage-Review (spec → quality), nicht inline.

**Why:** explizit gesagt „immer subagenten getrieben" (2026-06-18). Passt zum
etablierten Ausführungsmodell ([[project_compliance_catalog_remediation]]):
Massenarbeit an Sonnet-Subagenten, Opus reviewt/gated; riskante Teile
(Daten-Migrationen, Kern-Logik) bleiben/werden von Opus gereviewt.

**How to apply:** Nach writing-plans nicht fragen welcher Ausführungsmodus —
direkt subagent-driven. Worktree off ECHTES origin/main (lokaler Clone oft stale,
[[project_compliance_catalog_remediation]]). Cheap-Model für mechanische Tasks,
capable für Migration/Kern/Review. Full-Gate-Sweep + grüne CI vor Merge je Phase.
