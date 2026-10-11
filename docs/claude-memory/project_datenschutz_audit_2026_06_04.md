---
name: project_datenschutz_audit_2026_06_04
description: "DSB-Audit (DSGVO/BDSG/ISO27701) 2026-06-04 — KOMPLETT abgearbeitet: W1-W4 (#860-868) + gesamtes Backlog N-1..N-7 (#869-872, N-5 war #867). Nichts mehr offen."
metadata: 
  node_type: memory
  type: project
  originSessionId: 1fb61597-8623-4c7b-a0d3-0b9024b54460
---

DSB-Audit des Tools am 2026-06-04 (dpo-specialist-Lens, 5 Subsysteme parallel). Kernmodellierung überdurchschnittlich; scharfe Risiken waren fehlende **Durchsetzungs-Gates** (Pflichtschritte als Feld+Audit da, aber nicht erzwungen).

**Wichtig:** Cross-Tenant-Leak-Befund eines Agents war FALSCH (verifiziert) — alle Entities haben `ManyToOne(Tenant)`, `TenantFilter` greift via `hasAssociation('tenant')`. FK-Index-Name `tenant_id` ≠ skalar. Agent-Befunde IMMER nachverifizieren.

**Welle 1 — GEMERGED (#860, squash f54f38e3d):**
- H-1 (Art. 12(6)): `DataSubjectRequestService::complete()` + `updateStatus()` blocken Abschluss von access/rectification/erasure/portability ohne `isIdentityVerified()`. Const `DataSubjectRequest::IDENTITY_REQUIRED_TYPES`. Template-Button gesperrt.
- H-2 (Art. 35(2)/36): `DataProtectionImpactAssessmentService::approve()` hard-fordert `dpoConsultationDate`; bei Restrisiko high/critical zusätzlich `supervisoryConsultationDate`.

**W2 — GEMERGED:** #861 (M-1 Consent-Single-Path via `recordWithdrawal()` + M-3 Audit-Log entity-aware PII-Redaction) · #863 (M-2 `PersonalDataExportService` Art.15/20 JSON-Export, identity-gated) · #864 (M-4 `RetentionEnforcementService` + `app:enforce-retention` + EnforceRetentionMessage wöchentlich, schließt Audit-Cleanup-Scheduling).

**W3 — GEMERGED:** #865 (M-6 Art.-10 `criminalDataLegalBasis`-Gate + M-8 recipientCategories-Pflicht, ProcessingActivity-Validatoren) · #866 (M-5 Tenant-Anschrift+Art.27 · M-7 Supplier `thirdCountryTransfer`/`transferSafeguards`).

**W4 — GEMERGED #868:** N-2 §38-DSB-Bestellregister (Tenant) · N-3 DSR `extensionNotifiedAt`.

**Backlog — KOMPLETT ERLEDIGT (2026-06-04):** N-1 #870 (Consent-Proof-Lock: PRE_SET_DATA disabled consentText+purposes bei verifiziert) · N-4 #871 (`retentionPerCategory` JSON via JsonStructuredType) · N-5 **war schon #867** (TransferImpactAssessment-Entity vom Parallel-Agent — NICHT neu bauen) · N-6 #869 (`PurposeLegalBasisMismatchRule` Alva-Hint, marketing ohne consent/legit-interest) · N-7 #872 (`isProcessor`+`processorClientController` Art-30(2), mirror joint-controller). **Damit ist der gesamte DSB-Audit abgearbeitet — nichts offen.**

Plan-Doc war geschrieben, aber `/docs/` ist auf main gitignored → Plan lebt in PR-#860-Beschreibung. Siehe [[feedback_ci_gate_on_success]] (Line-Baseline-Shift: Gate 46 setter_nullability musste nach Const-Insert rebaselined werden).

**DSB-Abnahme-Verifikation (2026-06-05) fand + fixte 2 echte Bugs die CI nicht fing:**
- **#875** PA-Edit komplett kaputt: `#[Assert\Choice(choices: LifecycleRegistry::STANDARD_5_STAGE)]` — STANDARD_5_STAGE ist eine MAP (`'draft'=>['transitions'..,'tone'..]`), Choice vergleicht gegen die Array-Werte → JEDER Status (inkl. draft) ungültig → `data.status: ungültiger Wert`. Fix: `Assert\Choice(callback: [LifecycleRegistry::class, 'standardStageKeys'])` (= array_keys). **Lehre: Assert\Choice(choices: CONST) nur gegen flache Listen, nie gegen Maps.** Audit aller 20 Entity-Choice-Constraints: nur PA war Map.
- **#876** Validator-Messages Raw-Keys: Symfony übersetzt Constraint-Violation-Messages in der **`validators`**-Domain, NICHT im Form-`translation_domain`. Meine 5 PA-Validator-Keys lagen in `privacy` → lösten nicht auf. Fix: nach `validators.{de,en}.yaml`. **Lehre: Custom-Validator-Messages IMMER in validators-Domain.**
- **#879** DSR-Flash-Keys: Controller nutzte `data_subject_request.flash.*`, YAML nestet unter `dsr:` → Raw-Keys. Auf `dsr.flash.*`. + RetentionEnforcementService-Doku: DSR/Consent/Person bewusst NICHT auto-gelöscht (Nachweis-Records Art.7(1)/5(2), Löschung via Art-17-Workflow). OwnerPicker-Keys (`common.field.owner_*`) bleiben offen (Trait-weit, viele Forms).

**PHPStan im Symlink-Vendor-Worktree UNBRAUCHBAR:** lokale `vendor/bin/phpstan` löst `App\` über das symlinkte Vendor zum STALE main-Working-Tree + anderer Symfony-Version auf → Phantom-Fehler („undefined method recordWithdrawal/setIsProcessor" obwohl da, „extends @final Security"). NICHT lokal diagnostizieren. Echten CI-PHPStan-Fehler holen: `gh api repos/<owner>/<repo>/actions/jobs/<jobId>/logs` (funktioniert sobald JOB fertig, auch wenn RUN noch läuft; `gh run --log-failed` blockt bis RUN komplett). Bsp-Fund: `nullCoalesce.offset` weil `@param array{...}`-Shape einen Config-Key nicht listete den der Code via `$config['x'] ?? ...` liest → Key in die Shape aufnehmen.

**Worktree-Stolpersteine:** symlinked-vendor-Worktree bricht `bin/console` (fremder .env-Pfad) + PHPStan (Custom-Rule-Class nicht autoloadbar) — daher Source-Inspection-Tests (`file_get_contents` statt Runtime) wie `ProcessingActivityValidationTest`; YAML via python validieren. Pro PR Baseline-Files (translation_nesting/setter_nullability/em_writes) nach Key-/Feld-Inserts rebaselinen (reiner Line-Shift). PHPStan: `setStatus()` auf lifecycle-Entities (Consent/Tenant) verboten → `LifecycleService::transition()` oder gar nicht im Repair-Command.
