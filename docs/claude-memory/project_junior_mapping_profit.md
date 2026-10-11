---
name: project_junior_mapping_profit
description: "Junior-Mapping-Profit SHIPPED 2026-06-20 (#989): Mapping-Reuse-Payoff für ROLE_USER sichtbar+nutzbar — 3 Hebel."
metadata: 
  node_type: memory
  type: project
  originSessionId: 69475b3e-e0e1-42e0-9a07-b613585f0984
---

Mapping/Reuse-Payoff für Nicht-Experten-Junior (ROLE_USER) sichtbar + nutzbar gemacht. SHIPPED 2026-06-20, PR #989, subagenten-getrieben ([[feedback_always_subagent_driven]]). Vorher: Engine fertig + panel-validiert ([[project_dach_eu_mapping_program]]), aber Profit für USER unsichtbar/verriegelt.

**Hebel 1 — Leerstart:** Opt-in Starter-Pack. `LoadStarterPackJob` (AsyncJobInterface, Pack injizierbar `baseCodes=['ISO27001','BSI_GRUNDSCHUTZ']`+`gdprCode`, GDPR nur wenn `privacy`-Modul aktiv) + `MappingSeedService` (macht CLI-Seed-Commands service-aufrufbar via BufferedOutput) + POST `/compliance/frameworks/load-starter-pack` (ROLE_MANAGER+CSRF, dispatchWithProgress) + Empty-State-CTA (nur 0-Frameworks, nur Manager). Idempotent.

**Hebel 2 — Payoff sichtbar:** Klassen-Gate `ROLE_MANAGER`→`ROLE_USER` auf `ComplianceInheritanceController` + `SeedReviewQueueController` + `DataReuseHubController`; JEDE Schreib-Aktion per-Action `#[IsGranted('ROLE_MANAGER')]` (4-Augen intakt, USER-POST→403). Templates: Schreib-Controls hinter `is_granted('ROLE_MANAGER')` + "zur Manager-Freigabe"-Hinweis. Kohärenter Pfad: Nav→Data-Reuse-Hub(read-only)→CTA(Pending-Count)→Inheritance-Queue(read-only). Mappings sind globale Referenzdaten (kein tenant_id) → kein Cross-Tenant-Leak.

**Hebel 3 — Klartext:** 5 Glossar-Terms (config/glossary.yaml: Zuordnungsgrad/Vertrauensgrad/Transitive/Vererbung/MQS, 9001-Analogien) + `fa_glossary_tooltip`-Wrapping (embed-scope-sicher, GlossaryApiController lazy-load) + Guided-Tour-Schritt "fulfill-once" + Data-Reuse-Hub-CTA.

**Verbleibende Junior-/Mapping-Lücke (NICHT gemacht):** echtes per-Tenant-Modul-Gating fehlt weiter — `ModuleConfigurationService` global-only (active_modules.yaml, kein TenantContext/DB). Holding-Mandat unmöglich. Architektur-Change L. ([[project_compliance_catalog_remediation]])

Lehren: [[feedback_ci_test_discipline]] (voller Gate-Sweep, keine schweren Katalog-Loads in Tests, früh rebasen).
