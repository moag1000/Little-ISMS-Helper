---
name: project_resource_planning_spec
description: "Ressourcenplanungs-Modul KOMPLETT gemerged: MVP #906 + Finish #995/#996 (16/16 Adapter, Capacity-Config, Cl-9.3-Report, Teams-Menü, Alva-Hint). 2 interne Specs + 6 Entscheide."
metadata: 
  node_type: memory
  type: project
  originSessionId: 1fb61597-8623-4c7b-a0d3-0b9024b54460
---

**Neues Modul `resource_planning`** (Maßnahmenplanung · Kapazitäts-Roadmap PT/KW · Stammdaten). ISO 27001 Cl. 6.2/7.1/9.3/10.1. Specs sind **gitignored intern**:
- `docs/superpowers/plans/2026-06-06-resource-planning-content-model.md` — Entscheidungs-/Inhalts-SoT.
- `docs/superpowers/plans/2026-06-06-resource-planning-engineering-spec.md` — Bau-Vorlage (Doctrine-Mapping, SourceAdapter, UX-Reuse, 5-PR-Schnitt).

**6 Entscheide geklärt 2026-06-06:**
1. Person: **1 Netto-Feld** `ismsAvailabilityPct` (additive Erweiterung der Bestands-`Person`, API-Platform).
2. Team-Kapazität = **Σ** Mitglieder-PT (⌀ nur Anzeige).
3. Code-Entity **`ActionItem`** (NICHT `Measure` — ISO-„measures"/Control-Kollision), UI-Label „Maßnahme".
4. Auto-Konvertierung pro Quelle **off** default, Admin opt-in.
5. Status: **Option A** schlanker Enum+Guard+AuditLogger (Votum Consultant+Compliance-Manager; voller `LifecycleService` = Gold-Plating). Auflagen: Fassade `ActionItemStatusService::transition()`, reason-Feld mitführen, Enum/Transition-Namen = künftige `action_item.yaml`. Eskalation auf LifecycleService nur wenn Transition RBAC-Freigabe/4-Augen tragen muss.
6. `Team` als Doctrine **JOINED-Basis ab Tag 1**; `CrisisTeam extends Team`-Migration = Sequenz B, eigener PR nach MVP.

**Kern-Architektur:** Maßnahmenplanung = Sammel-Hub (nativ + 16 MyDay-Quellen via `SourceAdapter`, Provenance via polymorphe `ActionItemReference` refType+refId, kein Mirror). Roadmap = Jahres-Einplanung PT/KW. Recurrence-Anker = Abschlussdatum. Reuse: `Person` + generisches `Team` (Vorbild `CrisisTeam`, Join `team_members` analog `crisis_team_persons`).

**Design-SoT:** `docs/design_system/sections/planning-roadmap.html` + `previews/{security-roadmap,roadmap,roadmap-admin}.html` + neue Komponente `fa-savebar`.

**Status 2026-06-20:** GEMERGED auf origin/main (PR #906). Entities `ActionItem/ActionItemReference/RoadmapGroup/RoadmapTask/RoadmapAllocation`, Controller (`ActionItemController`/`Roadmap*Controller`), Voters, `Service/Planning/*`, 2 Migrationen, Templates alle live. Modul fertig — NICHT erneut „PR-1 bauen" (war stale gedacht).
**Bau-Historie:** Worktree `/tmp/lih-planning` (branch `feat/resource-planning`, off origin/main), 8 Commits.

**FINISH 2026-06-20/21 (PR #995 + #996, beide gemerged):** PR-5-Scope abgeschlossen:
- #995: **16/16 SourceAdapter** (8 neue: risk_treatment/dsr/data_breach/policy_ack/training/workflow/four_eyes/dpia; audit_checklist=skip, von internal_audit gedeckt) · **Capacity-Konstanten tenant-konfigurierbar** (PlanningSettings.fullTimeHoursPerWeek/hoursPerDay, 40/8-Fallback) · **Cl-9.3-Rollup-Report** (`CapacityRollupService` + `app_planning_roadmap_report`, deterministisch year/week-injiziert, Σ-Kapazität vs Σ-Last je Gruppe/Scope).
- #996: **gated Teams-Menüpunkt** (ROLE_MANAGER) + **Alva-Hint** `PlanningDueActionItemsRule` (fällige Maßnahmen 14d → Deep-Link `filter=due`).
- Adapter-Accessoren IMMER gegen echte Entity verifizieren (Spec-Schätzungen falsch: DSR `getDeadlineAt` nicht `getDeadline`, DPIA `getNextReviewDate`, Breach due=detectedAt+72h, FourEyes kein `getRequestedAt`). isCompleted-Terminal-Set gegen echtes Enum/Konstanten prüfen — Stub-Unit-Tests fangen Accessor-Fatals NICHT (echte Entity-Instanzen im Test nutzen).
- **Review-Bug (adversarialer Re-Check fing 2):** H-1 = `createFormBuilder([array])` UMGEHT entity-`#[Assert\Positive]` (validiert Array, nicht Entity) → `hoursPerDay=0` persistierte → `DivisionByZeroError` 500. Fix: Field-level `'constraints'=>[new Assert\Positive()]` (feuert bei Array-Binding) + defensiver `<=0`→Default-Guard im Service. Lehre: bei array-gebundenem Form Constraints AUF DAS FIELD, nicht auf Entity-Attribute verlassen. M-1 = Menüpunkt nicht role-gated obwohl Controller `IsGranted` → 403-Link.
- Gate-27 (`em_writes_in_controller`, line-numbered) NICHT im Default-Sweep — bei Controller-Edits IMMER mitlaufen + `--write-baseline` bei Zeilen-Shift. Worktree-vendor war SYMLINK (stale-Main-Falle) → echtes `composer install` nötig für vertrauenswürdiges Test/lint/debug:container-Signal.

**Nach-Finish (2026-06-21):**
- **Smoke-Tests #998** (13, gemerged): Functional-Coverage geschlossen — Routen-RBAC/H-1-Reject/empty-Report + Capacity-Guard + Conversion-Pipeline + Alva-Rule, gegen echte DB. CI-safe via CrisisTeam-Harness (CI macht `touch config/setup_complete.lock`).
- **L-1 #1001** (gemerged): SourceAdapter-`findConvertible` status-scoped (6 Adapter, behavior-preserving, Terminal-Set aus isCompleted). Composite-(tenant_id,status)-Index für später geflaggt.
- **Sequenz B (CrisisTeam→Team JOINED) — EVALUIERT + VERWORFEN (YAGNI).** Brainstorm 2026-06-21: unter Minimal-Churn (Member separat, Felder via Alias, BCM-Felder aufs Child) teilen CrisisTeam+Team praktisch nichts → volles BCM-regulatorisch-kritisches Daten-Migrations-Risiko für ~null funktionalen Gewinn. KEIN aktueller polymorpher Team-Consumer (kein modulübergreifender Picker, kein Person.teams das beide spannt, keine Roadmap-Sicht für Krisen-Teams). JOINED-Base war spekulativ („*can* migrate later"). Entscheid: nicht bauen, Base ruht (kostet nichts) bis konkreter Consumer existiert → DANN Migration MIT dem Feature. Lehre: spekulative Inheritance-Hooks nicht „weil vorbereitet" einlösen; Risk≫Reward unabhängig prüfen wenn User „bist du sicher?" fragt. Lehren CI-Verify: Gates die lokaler Sweep zuerst verpasste → `check_twig_entity_properties` (Template-Getter muss existieren: User=fullName/email, Person=fullName), `check_aurora_icon_names` (Gate 15, nur Allowlist-fa-icons), `check_nav_area_parity` (neue Routes brauchen Prefix→Area-Eintrag in `_mega_menu.html.twig` `_nav_map`). `failOnNotice=true` in phpunit → Test-Mocks `createStub` nicht `createMock`-ohne-Expectation. `FormBuilder` hat KEIN `setTranslationDomain()` → via createFormBuilder-Options (PHPStan fing den Runtime-500). em_writes/translation_nesting Baselines sind zeilen-nummeriert → nach Code-Shift `--write-baseline`. Worktree braucht echtes `composer install` (symlinked vendor bricht PSR-4 zur Worktree-src).

**Warum wichtig:** baureif → gebaut → CI-grün. Nächster Schritt = User-Entscheid Merge PR #906. Bezug: [[feedback_no_inline_table_edit]], [[project_market_positioning_review]] (Cl-6.2/7.1-Feature), [[feedback_json_fields_use_builder]], [[project_persona_skills]] (Lifecycle-Votum via Consultant+Compliance-Manager-Persona).
