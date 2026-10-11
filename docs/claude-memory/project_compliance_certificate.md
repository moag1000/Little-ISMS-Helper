---
name: project_compliance_certificate
description: "Compliance-Zertifikat-Objekt (generisch, bulk-fulfill + capability-gated OCR) — SHIPPED 2026-06-20 (PR #982/#983/#984)."
metadata: 
  node_type: memory
  type: project
  originSessionId: 69475b3e-e0e1-42e0-9a07-b613585f0984
---

Generisches **Compliance-Zertifikat-Objekt** komplett ausgeliefert 2026-06-20, subagenten-getrieben ([[feedback_always_subagent_driven]]). Kunde hinterlegt externes Zert (ISO27001/BSI-C5/SOC2/TISAX/EN50600…) einmal → im Scope/Klasse liegende Controls automatisch „erfüllt – durch Zertifikat", Zert-PDF als geteilte Evidenz, verified, Re-Review bei Ablauf.

**B1 Kern (PR #982):** `ComplianceCertificate` (1 framework/cert, scopeTags JSON, certClass [PHP-reserved→property `certClass`/column `class`], validUntil, certBody, certificateDocument→Document, extractionSource/Confidence) + `CertificateCoverageRule` (DB, class/scopeTags→requirementIds, framework-weiter Fallback) + `CertificateCoverageResolver` (CoverageResult{requirementIds,isFallback}) + `CertificateBulkFulfillmentService::apply(cert,actor)` (idempotent: fulfillmentPercentage=100/status Verified/evidenceDocuments/verifiedAt/By/nextReviewDate=validUntil + logBulk) + Voter (Manager anlegen/apply, Auditor view, Admin delete) + Controller `/{_locale}/compliance/certificates` + Migration. Fulfillment-Entity erweitert: `evidenceDocuments` (ManyToMany→Document) + `verifiedAt`/`verifiedBy`. Ablauf-Re-Review: `EvidenceCascadeInvalidationService::flagExpiredEvidence(tenant)` aus `app:review:send-reminders` (per-aktivem-Tenant, TenantContext im CLI-Loop gesetzt sonst Audit-Tenant falsch) + lapsed-Cert→status='expired'.

**deps-fix (PR #983):** jwt-library 4.1.7 / guzzle 7.12.1 / psr7 2.12.1 / otphp 11.5.0 — Security-Checks-Gate war auf main rot (CVE-Batch 2026-05/06), blockte ALLE PRs. War nicht von B1.

**B2 OCR (PR #984):** capability-gated, server-seitig, optional. `ocr_processing`-Modul + `OcrCapabilityDetector`/`OcrCapabilityInterface` (Modul aktiv AND pdftotext+tesseract da, sonst lautloser Manual-Fallback, KEIN Datenabfluss) + pure `CertificateFieldExtractor` (Regex DE+EN, LLM deferred) + `ProcessCertificateOcrJob` (AsyncJobInterface, `PdfTextExtractor`: pdftotext digital→tesseract scan) + Upload-only-Form wenn OCR an → async `dispatchWithProgress` → editierbares Confirm-Draft (extractionSource='ocr+confirmed', blank-only-fill schützt user-getippte Werte) + Docker bündelt poppler-utils/tesseract-ocr/-deu/ghostscript.

**Lessons (CI/Worktree):**
- **feat/\*-Branches triggern ci.yml NICHT automatisch** zuverlässig — push-Filter ist `[main,develop,claude/**,release-please--**]`; pull_request-Trigger flaky. Bei Bedarf `gh workflow run "CI/CD Pipeline" --ref <branch>` (workflow_dispatch). Tests-Job ~18min.
- **WebTestCase-POSTs: Crawler-`Form`+`submit()` statt hand-gebautem `_token`** — manuelles Token matcht Session nicht in der Full-Suite (isoliert lokal grün, CI rot „Invalid CSRF token"). File-Upload via `$form['file']->upload($path)`. Erweitert [[feedback_csrf_tests_session]].
- **Scratch-Test-DB veraltet wenn main Spalten addiert** (hier `mapping_onboarding_state` aus #981) → `APP_ENV=test doctrine:schema:update --force --complete` vor lokalem Test. CI baut frisch, daher dort kein Problem.
- **translation_nesting-Baseline** bei jedem compliance.yaml-Key-Insert regenerieren ([[feedback_gate26_line_baseline_shift]]); bei Rebase-Konflikt im Baseline-File: --theirs nehmen, DANN frisch `--write-baseline` gegen gemergtes YAML (sonst gate-rot).
- no-generic-throws: interne Invarianten-Throws mit `// @intentional-assertion:` markieren.

Spec `docs/superpowers/specs/2026-06-17-compliance-certificate-design.md`, Plan `docs/superpowers/plans/2026-06-18-compliance-certificate.md`. EN50600-Coverage-Rules brauchen noch lizenzierte Norm bzw. offene Ersatz-Achsen (var/en50600/, [[project_compliance_catalog_remediation]]).
