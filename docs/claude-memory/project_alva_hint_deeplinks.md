---
name: project_alva_hint_deeplinks
description: "Alva-Hint Deep-Link-Rollout (2026-06-05): count-Hints linken zu GENAU gezählten Entities + UI focus-notice + 4 Hygiene-Hints auf ROLE_USER geöffnet. GEMERGED PR #895 (7303490e5) + #897 (0169b21a8). Pattern + welche Rules geändert/übersprungen + Junior-Review-Lehren."
metadata: 
  node_type: memory
  type: project
  originSessionId: 1fb61597-8623-4c7b-a0d3-0b9024b54460
---

User-Bug: Count-Alva-Hints ("1 Dokument(e) stecken seit >14 Tagen...") verlinkten zur
ungefilterten Index-Seite, nicht zu GENAU den gezählten Entities. Rollout über alle ~24
count-basierten Global-Rules. **GEMERGED in main, PR #895, squash `7303490e5`.** (Branch war `fix/alva-hint-deeplinks`, Referenz #895 + Rollout-Commits
Commits, **auf User-Wunsch NICHT gepusht/gemergt — hält für Review**).

**Kanonisches Pattern (3 Teile, single-source-of-truth):**
1. **Repository-Finder** = EINE Methode liefert die exakte Entity-Liste (z.B.
   `DocumentRepository::findStuckInLifecycle`, `findApprovedWithoutAcknowledgement`,
   `AuditFindingRepository::findOpenWithoutRequirements`, `RiskRepository::findWithoutOwner`,
   `IncidentRepository::findOpenPrivacyWithoutSla`, `VulnerabilityRepository::findHighOpenWithoutPatch`,
   `RiskIncidentLinkRepository::findStaleLinksToOpenIncidents`). Rule UND Controller-Filter
   nutzen denselben Finder → können nie divergieren. Inline-DQL in Rules durch Finder ersetzt.
2. **Rule deep-linkt**: `count===1` → `*_show` mit `['id'=>$e->getId()??0]`; `>1` →
   `*_index` mit `['focus'=>'<key>']`. (Bei Threshold->5 nie 1, 1-Zweig trotzdem für Parität.)
3. **Controller-`focus`-Filter**: `$request->query->get('focus')`; bei Match Liste auf Finder-Set
   einschränken (ID-intersect wenn view/inheritance-Logik bleiben muss, sonst Liste ersetzen).

**Geändert (12):** LifecycleStuck(#895), OverdueAuditFinding(focus=overdue),
NonconformityAutoTaskTip(nc_unreferenced), OpenIncidentOhneSla(privacy_sla),
RiskOhneOwner(no_owner), RisksLinkedToOpenIncidents(incident_linked, DISTINCT risks),
VulnerabilityWithoutPatch(unpatched), ApprovedDocOhneAck(no_ack),
Bsi2004ImprovementActionOverdue(1 log→show, >1→improvement_overdue),
MissedSlaDeadline(1→underlying entity show via type-map Incident/AuditFinding/Document,
sonst Fallback admin_notification_rule_index — heterogene Monitor-Typen, kein unified index),
AuthorityTemplateOverdue(focus=overdue via neuem Shared-Service
`OverdueAuthorityNotificationResolver`, weil Art-33-Logik+AuditLog-Export-Check geteilt werden muss),
OverdueAuthorityReport(focus=overdue, Hub filtert obligations status==='overdue').

**Übersprungen — bereits korrekt (4):** OutdatedEvidence (Ziel `app_evidence_reverification_index`
zeigt schon exakt die outdated Controls), OpenFindingReference (linkt schon präzise via
`?finding_ref=` targeted-Wizard), Nis2RegistrationOverdue/DueSoon (single per-tenant Profile,
Index zeigt genau das eine).

**Übersprungen — Absence/Aggregate/Milestone, by-design korrekt (9):** Settings/KonzernSettingsDrift
(Config-Drift, keine clickbare Entity), MissingDpoForGdpr/MissingSsoForEnterprise (Absence →
Setup-Seite zum Anlegen), DoraRoiExportDue (single record, %year%), NoNotificationChannel/
MissingBcExercise (Absence), FteSavingsMilestone (Celebration), LibraryUpdated (Re-Import-Prompt).
→ **Lehre:** "Missing X / No Y"-Hints sollen zur Erstell-/Setup-Seite linken, NICHT deep-linken;
nur "N existierende X überfällig/ohne Z"-Hints brauchen das Pattern.

**UI-Loop geschlossen:** Shared Partial `templates/_components/_focus_filter_notice.html.twig` (Aurora
`fa-filter-chip`) zeigt auf gefilterter Liste WAS gefiltert ist (+Count) + "Alle anzeigen"-Chip.
Neue Domain `focus_filter` (de/en). In 8 Index-Templates eingebunden; Controller passen `focus`
an render. translation_nesting-Gate: **Top-Level-Key MUSS = Domain-Name** (`focus_filter:` nicht
`focus:`), Keys dann `focus_filter.focus.<key>`. Dynamic-Key-Gate: keine konkatenierten trans-Keys
→ statisches Dict im Twig (`{lifecycle_stuck: 'focus_filter.focus.lifecycle_stuck'|trans(...)}`).

**Polish-Runde #897 (`0169b21a8`, gemergt):** 3 Review-Gaps + Junior-Acceptance.
- Regression-Net `tests/AlvaHint/FocusFilterNoticeWiringTest.php` (DB-frei, Source-Inspection):
  jeder emittierte focus-Key braucht Partial-Label + de/en-Übersetzung + Index-Include; clear-Link
  erhält Filter. Fängt stillen Verfall bei neuem focus-Key.
- „Alle anzeigen" erhält andere Filter: Partial baut clear_url selbst aus
  `path(app.request.attributes.get('_route'), app.request.query.all|merge({focus: null}))` (Symfony
  droppt null-Params). clear_url NICHT mehr aus Templates passen.
- KonzernSettingsDrift → `?view=inherited` (zeigt Eltern-Docs zum Übernehmen) + Unit-Test.

**Junior-Acceptance-Review (persona-implementer-junior, alle Befunde verifiziert):** Technik solide,
aber: (a) **alle** Deep-Link-Hints waren ROLE_MANAGER+ gated → frischer ROLE_USER sah KEINEN, obwohl
Ziel-Aktionen (risk edit/owner) für USER offen — Gate saß am Sehen nicht am Tun. **User-Entscheid:
4 Hygiene-Hints (RiskOhneOwner/LifecycleStuck/VulnWithoutPatch/ApprovedDocOhneAck) → ROLE_USER**,
regulatorische bleiben Manager+. Ziel-Listen vorher als USER-erreichbar verifiziert (kein 403).
(b) Chip-Denglisch `'Ohne Owner'`→`'Ohne Verantwortlichen'`; (c) Body leakte DB-Spalten
`riskOwner/riskOwnerName` → Klartext. **Lehre: Hint-Bodies/Chips nie Code-Feldnamen/Denglisch; Hint
NUR sichtbar gaten wenn Ziel-Aktion derselben Rolle offen ist.** Persona-Subagent-Befunde nachverifiziert
(Memory-Regel) — alle 4 stimmten.

**PHPStan-Falle (CI fing's, lokal-Worktree nicht):** `count === 1`-Branch nach `if (count <= THRESHOLD) return null`
mit THRESHOLD>1 → `int<6,max> === 1 always false` → Build-RED. Bei >threshold-Hints den Single-Entity-Branch
WEGLASSEN, direkt Index+focus. Echten Fehler via `gh api repos/<o>/<r>/actions/jobs/<jobId>/logs`
(Code-Quality-Job-ID via `gh run view <rid> --json jobs`). god_class+em_writes pro Push rebaselinen
(reiner Line-Shift, entry-count gleich halten als Sanity). Worktree-`lint:twig` false-positive
`Unknown "can_view_field"` (Custom-Twig-Fn nicht registriert) — wie PHPStan-Worktree-Unzuverlässigkeit.

Jede geänderte Rule hat Unit-Test (count 0→null, 1→show+id, >1→index+focus). 622 AlvaHint-Tests
grün. god_class+em_writes rebaselined (reiner Line-Shift, entry-count 454/454, 38/38 — siehe
[[feedback_ci_gate_on_success]]). WebTestCase-Controller-Tests lokal NICHT lauffähig (Worktree
ohne DB: "Access denied 'app'@'localhost'") — laufen erst in CI. Neuer Service `final` entfernt
damit mockbar.
