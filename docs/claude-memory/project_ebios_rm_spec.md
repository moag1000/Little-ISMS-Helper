---
name: project_ebios_rm_spec
description: "EBIOS-RM (F24) Design-Spec fertig + approved; 4 Entscheidungen getroffen; reuse-first, M-L statt XL."
metadata: 
  node_type: memory
  type: project
  originSessionId: 5f89d394-84e9-4154-94a3-bf677b9fc53d
---

EBIOS Risk Manager (F24) Spec: `docs/superpowers/specs/2026-06-05-ebios-rm-design.md`
(docs/ ist gitignored → lokal). **APPROVED for build** 2026-06-05.

**Kern-Entscheidung:** EBIOS ersetzt NICHT ISO 27005 — geführtes 5-Atelier-Frontend,
Atelier-5 erzeugt normale `Risk`-Rows ins bestehende Register. Reuse-first:
BusinessProcess=valeur métier · Asset+AssetDependency-Graph=biens supports+Angriffspfade ·
Control=mesures · Supplier/DoraSubcontractor=écosystème · ComplianceFramework=socle ·
WizardRun+Symfony-Workflow=Ateliers · AuditLogger=Sign-off. Nur 4 NEUE Entities:
EbiosStudy + EbiosRiskSource + EbiosStrategicScenario + EbiosOperationalScenario (fast nur FKs).

**4 User-Entscheidungen (greenlit):**
1. `ROLE_EBIOS_LEAD` neu; `ROLE_CISO` erbt es via role_hierarchy.
2. `Risk.origin` Option A — nullable enum (iso27005 default / ebios), Reporting-Seam ohne Join + Migration.
3. Socle: ANSSI-Baseline mitgeliefert (Default) **+** EbiosStudy.securityBaseline = FK auf BELIEBIGES Framework (ISO27001/BSI wählbar).
4. Modul `ebios_rm` (depends risks/controls/assets), gated, Alva-Hint-Empfehlung wenn tenant.country=FR. Global opt-in.

**Spec v2 (2026-06-05): nach 6-Persona-Review überarbeitet (§11).** 4 CRITICAL-Fixes eingebaut:
1. `RiskScaleMapper` gravité/vraisemblance 1–4 → impact/probability 1→1,2→2,3→4,4→5 (Identity-Map korrumpiert Register, Risk validiert Range 1-5).
2. `Risk.businessProcess` nullable FK — existiert NICHT, ist die valeur-métier-Spine.
3. Risk auf STRATEGIC-Scenario-Level (nicht pro operational scenario), probability=max(vraisemblance).
4. attackPath ist EIGENES Artefakt, NICHT Asset.dependsOn-Reuse (inverted semantics).
HIGH: four_eyes+freeze auf treatment_done (actingUser≠signoffUser); Sign-off-Snapshot in newValues; reopen-Idempotenz (producedRisk in-place updaten, sonst F46-Doppel); socle-Guardrail (complete/published+coverage-snapshot); Privacy-Reuse (ProcessingActivity/DPIA/TOM, privacy-gated); Risk.ebiosTrace JSON.
UX/Junior (TODO build): Study-Hub + per-atelier-Subpages (fa-stepper nur Progress-Chrome), Stimulus-Chip-Builder für ordered paths (NICHT TomSelect/textarea), fa-entity-badge 11. Type 'ebios', Path-Viz via _fa_subcontractor_chain (kein Graph-Lib), German-primary-Labels+French-sub+Skalen-Legenden+Pre-Flight-Prereq-Panel, "Risiko erzeugen"-Button mit Preview.
Portfolio: NIS2/DORA-Mapping-Library → EBIOS-Risks in Cross-Framework-Coverage.
**Effort re-rated L** (Form+JSON-Builder+i18n-Tail, nicht Entities). 5 Schema-Changes: Risk.businessProcess/ebiosTrace/origin + 4 Entities.
**Walking-Skeleton-Sequenz:** EbiosStudy + Risk-Felder + Atelier-1→5-Bridge + RiskScaleMapper + Modul-Gate → Risk end-to-end beweisen → DANN Wizard-Chrome.
Build noch nicht gestartet. Nächster Schritt: subagent-driven nach Spec (Entities→Workflow→Service+Bridge→Wizard→Loader→Nav/Modul→Tests).
Bau-Lehren aus dieser Session beachten: [[project_phase3_progress]] (Gate-Line-Shift, Public-Routes-routes.yaml, #[CurrentUser]-Gate, Nav-Resolver).
