---
name: project-backlog-2026-05-25
description: "Restposten-Backlog. Stand 2026-05-27: alle A-Items + B2/B3 done. Nur B1 (Term-Konsolidierung) + B4 (NC-Entity-Split) als UX-Decisions offen. Strategische C-Phasen weiter Backlog."
metadata: 
  node_type: memory
  type: project
  originSessionId: df58c764-60e6-489c-9ac8-c65766309351
---

Plan-Dokument unter `var/junior-isb-audit/BACKLOG_2026-05-25.md` (Original).

**Update 2026-05-27 — Re-Verifikation Ergebnis:**

Kategorie A (Bounded Refactors) komplett erledigt:
- ✅ A1 crisisTeams Quick-Create — `templates/business_continuity_plan/new.html.twig:48-73`
- ✅ A2 businessProcess Quick-Create — same file, lines 63-71
- ✅ A3 BC-Exercise participants/observers M2M — `BCExercise.php` legacy-string + `participantPersons`/`observerPersons` M2M, `BCExerciseType.php` EntityType
- ✅ A4 Supplier-Norm-Bridge — `templates/supplier/{new,edit}.html.twig`
- ✅ A5 Lifecycle-Status-Disable-Hint — `TrainingType.php:164-180` `training.help.status_readonly`

Kategorie B (UX-Decisions) zur Hälfte umgesetzt:
- ✅ B1 Term Tenant/Mandant/Organisation — System=Tenant, UI=Organisation/Organization (US-ISO). Translation-Sweep done 2026-05-27 (commit `ca5fc7413`). ADR: `docs/decisions/2026-05-27-tenant-organization-terminology.md`. Memory: [[feedback-tenant-organisation-term]]
- ✅ B2 Asset-Sub-Type — `src/Entity/AssetSubType.php` + BSI/TISAX-Presets
- ✅ B3 Department-Entity — `src/Entity/Department.php` + `ProcessingActivity.responsibleDepartmentEntity` FK
- ✅ B4 Nonconformity-Entity-Split — accepted: Hybrid-Modell (AuditFinding.type-Enum + separate CorrectiveAction-Entity) ist audit-defendable. Decision 2026-05-27. ADR: `docs/decisions/2026-05-27-nonconformity-modeling.md`. Memory: [[feedback-nonconformity-modeling]]

Code-TODOs:
- ✅ BusinessProcess §17 typed M2M — `upstreamProcesses`/`downstreamProcesses` aktiv. Stale Comments aufgeräumt im Commit `e61f666d6` (chore, 2026-05-27, local main).
- ✅ DocumentGenerator.php:302 — aufgelöst über DocumentSplitNeededRule Tier-2 Hint
- ℹ️ `CertificationBundleExporter.php:832/842` — `TODO(audit-gap)` by-design im Output (Holding-RACI-Marker), nicht entfernen

Kategorie C (Strategic Phases) weiter unangetastet:
- ⏸ C1 ROADMAP 8L Quick-Config (12d) — Enterprise-Sales-Trigger
- ⏸ C2 ROADMAP 8M Holding-Inheritance (10d) — Vorbedingung 8L
- ⏸ C3 ROADMAP 8K Classification-Schemas (15d) — BSI/TISAX-Customer-Trigger
- ⏸ C4 CM-Reuse Restposten — verbleibend ~5d trigger-gated (27701-Overlay, CMMI-Reifegradmodell, REST-API)
- ✅ C4-C1 Klon-Funktionen (3d) — delivered 2026-05-27, commit `77ce12982`. 8 Entities cloneable, ROLE_USER zugänglich. Pattern: [[project-clone-rollout]]
- ✅ C4-B4 Multi-Framework-Audit (1.5d) — delivered 2026-05-27, commit `da8766d48`. CrossFrameworkCoverageService + Show-Template-Section + PDF-Export. 7 Unit-Tests grün.

**Why:** Re-Verifikation Session 2026-05-27 ergab dass Sweep-Sessions zwischen 2026-05-25 und 2026-05-27 mehr Items als erwartet erledigt haben. Backlog war veraltet.

**How to apply:**
- Sofort startbar: alles erledigt. C4-B4 Multi-Framework-Audit delivered 2026-05-27.
- B1 + B4 vor Code-Start mit User klären (Term-Pick / NC-Split-Defendability).
- C-Phasen warten weiter auf Sales/Customer-Trigger.
- Bei nächstem Sprint-Plan: Backlog-File `var/junior-isb-audit/BACKLOG_2026-05-25.md` durch frischen Audit-Run ersetzen statt incremental updaten.
