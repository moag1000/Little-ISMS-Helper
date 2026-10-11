---
name: sprint-roadmap-state
description: "Roadmap-Stand 2026-05-15: Sprint 4-9 + DORA-tool-wide + alle E2E-Fixes in main. Sprint 10-13 + Backlog noch offen."
metadata: 
  node_type: memory
  type: project
  originSessionId: 00707aab-5219-4853-97ae-8ba832ca1266
---

Stand 2026-05-15 (Fr). Release-PR #332 wartet auf Mo 09:00 UTC auto-merge (release-please-bot) → Tag v3.7.0.

**Roadmap-Quelle:** `docs/superpowers/plans/2026-05-09-feature-roadmap-from-competitor-analysis.md`

## Done (in main, bis Sprint 9)

Sprint 4 F1 SSO Wave 1 · Sprint 5A F4 Evidence-Cascade · Sprint 5B F1 SSO Wave 2 · Sprint 6a F3 Notifications Backend · Sprint 6b F3 Notifications UI · Sprint 7A F3 SLA Deadline Monitor · Sprint 7B F29 NIS-2-BSI-Portal · Sprint 8 F36 EU-Authority-Hub + F30 DORA-RoI-XBRL · Sprint 9A F11 FTE-Dashboard · Sprint 9B F16 Risk-Incident-Link

**Plus:** 6 Persona-Rollen (DPO/CISO/RISK_MANAGER/COMPLIANCE_MANAGER/GROUP_CISO/KONZERN_AUDITOR) + 33 Permissions + Permission-Rich-Table · User-Form prefill · Admin-Slim-Mega-Menu · AdminHub Aurora-Icons-Migration · Translation+Hub-Coverage · 6 deferred Admin-Settings-Pages (UI only, backend deferred) · Tech-Debt Round 1+2 · NIS-2 Migrations + nullable Contacts · DORA Phase-1 (isDoraRelevant entity-flag) · DORA Phase-2-tool-wide (Tenant.doraEntityCategory + is_dora_obligated() + dashboard tiles + AlvaHints) · Multi-select Industry-Presets · Policy-Doc dark-mode preview · Annex-A applicability propagation · SoA bulk-applicability + sticky-header · Evidence-preview-button · Control-ID regex 27001/27701/27017/27018 · Mega-menu Turbo/trailing-slash fixes · ~80 fehlende Aurora-Icons designed-und-wired

## Offen — Sprint 10-13

- **Sprint 10** F5b Wave 1 (BSI Kompendium 2024 + TISAX v6.0 Library) || F27 BSI-200-4-Übungs-Logbuch
- **Sprint 11** F10 Profile/Maturity per-Framework || F28 TISAX-ISA-Workflow
- **Sprint 12** F13 TIA (Transfer-Impact-Assessment) || F31 DPIA-National-Templates || F32 DPA-Generator
- **Sprint 13** F33 EU-AI-Act-Klassifizierung || F34 CRA-SBOM-Inventar

## Backlog (Sprint 14+)

F-NEU-ICT-PROVIDER (Asset-Library multi-tenant, dokumentiert in Roadmap), F26 Wave 2 (DSB-AT/EDÖB-CH/BaFin), F5b Wave 2 (Swiss-Stack nDSG+ISG), F39 ENISA EUVD Daily-Feed, F12 OSCAL-Roundtrip, F6 REST-API Bulk + Webhook-Lifecycle, F7 Field-Level-RBAC, F8 Health-Check, F9 i18n FR/IT/ES/NL/PT-BR, F14/F17 Audit-Findings-Inline + Procedures-Authoring, F20 Multi-Format-Doc-Export, F23 Supplier-Questionnaire, F21+F22 MCP-Server + Local-LLM, F24 EBIOS-RM, F18 No-Code-Framework-Builder, F37 One-Command-Setup-Hardening, F35 EUCS-Cloud-Audit

## Wave-2-Marker (Code-Hooks-da, Backend-deferred)

- SlaDeadlineMonitor Mercure-Push (Notifications Wave 2)
- Webhook real-network-verify (jetzt: `verifiedAt` set ohne I/O)
- Backup-Messenger-Dispatch + Data-Retention-Cron + Workflow-SLA-Watcher (Settings-UI da, Backend-Cron deferred)
- DORA-RoI Phase-2 Asset-Library für ICT-Provider

## Tech-Debt-Backlog

- WebTestCase ~12 pre-existing schema-drift errors
- Migration phantom-diff: 149 cosmetic + 1 destructive FK (wizard_session_tenant) deferred
- 13 unused entity columns mit `@deprecated`-marker, noch nicht entfernt
- PHPUnit 17 Symfony/Doctrine internal-deprecations
- Stimulus controllers: 88 missing per E2E Round 1
- 3 Bootstrap-icons dynamic-JS-string Stimulus-controller-cases
- 60 Aurora-Icons evtl noch designer-todo (Status nach PR #368 + design-update unklar)

## Release-Mechanik

Mo 09:00 UTC: `release-please-auto-merge.yml` cron merged Release-PR #332 → Tag v3.7.0 + Docker push. Stable-Release-Cadence-Disziplin in Memory `[[feedback_release_cadence_discipline]]` — manuelle Tags NUR bei urgent fixes.

## Konventionen

- Conventional Commits → release-please bumpt entsprechend
- Sonnet als Subagent-Standard für Sprint-Delivery (parallel-safe wenn unterschiedliche Scope)
- Foreground-Approval-Pattern: PR-Open + nicht-merge bis User OK
- Quality-Bar 18-Punkt End-to-End inkl. Alva-Hint pro Feature
- DDL-Migration `isTransactional()=false` Pflicht, Gate 4
- Aurora-Icons-Only, Gate 11
- Locale-Routing-Prefix global, kein doppel `/{_locale}/`, Gate 8/10
- 11 Quality-Gates `scripts/quality/*.py + *.sh`

Related: [[feedback_release_workflow]], [[feedback_release_cadence_discipline]], [[feedback_locale_in_links]], [[feedback_migration_consolidation]], [[feedback_migration_savepoint]], [[feedback_no_competitor_names]], [[feedback_no_touching_wip]]
