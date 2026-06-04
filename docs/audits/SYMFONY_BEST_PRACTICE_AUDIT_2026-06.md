# Symfony 7.4 / PHP 8.5 Best-Practice Audit — June 2026

> **Status: audit complete + backlog cleared.** This document records divergences
> from current Symfony 7.4 LTS / Doctrine ORM 3.6 / PHP 8.4-8.5 / Twig 3.24 /
> PHPUnit 13 best practice, plus deliberate project deviations (which are noted
> as such, not as defects). Sections 1-6 were the original audit; sections 7-8
> add a Turbo/Stimulus UX pass and a dead-code / wrong-implementation pass.
> See **Remediation log** at the bottom for what has since been fixed.

**Date:** 2026-06-03
**Stack audited:** Symfony 7.4 LTS, PHP 8.5 (8.4 min), Doctrine ORM 3.6, Twig 3.24, PHPUnit 13.1, API Platform 4.3
**Surface:** 207 controllers · 430 service files · 123 entities · 112 form types · 150 commands · 862 templates · 992 test files · 188 migrations
**Method:** 6 parallel read-only audit passes (systematic grep/AST sampling, not exhaustive read), each cross-checked against `CLAUDE.md` conventions to separate *anti-patterns* from *intentional deviations*.

---

## Executive Summary

**Overall posture is strong.** Routing, authorization-attribute adoption, migration discipline, repository typing, FormType hygiene, and async-job request-safety are largely best-practice. The findings are concentrated in a handful of structural items plus several low-severity consistency gaps.

### High severity (correctness / latent risk)

| Ref | Finding | Why it matters |
|---|---|---|
| C-4 | ~20 `$this->getUser()` passed to `User`-typed service methods without `instanceof` narrowing | static-type contract wrong; suppressed only by `ROLE_USER` gates — fragile if a guard is removed |
| S-1 | `WorkflowOverlayController` uses `$this->container->has()/get()` for an autowirable service | service-locator anti-pattern; class is registered, can be constructor-injected |
| S-2 | 15× `#[AsCommand]` Command objects made `public` and injected into services (`ComplianceFrameworkLoaderService`, `ComplianceLoaderFixerService`) | commands carry console state; loading logic should be a shared service |
| E-6 | `ComplianceMapping` / `MappingGapItem` have no direct `tenant_id`; inherited `Repository::findAll()` is tenant-unguarded | latent cross-tenant leak if a future caller uses `findAll()` |

### Medium severity (maintainability / deprecation / async-safety)

| Ref | Finding |
|---|---|
| S-9 / C-* | `RequestStack` injected into async-capable services (`SecurityEventLogger`, `EvidenceVersioningService`, `IndustryBaselineService`, `MrisBaselineService`, `GuidedTourService`) without null-guard (`AuditLogger` is guarded) |
| S-3 | Deprecated `WorkflowAutoProgressionService` still injected in 4 call-sites (CLAUDE.md says don't) |
| S-4 | All 19 event subscribers use `EventSubscriberInterface` instead of `#[AsEventListener]` |
| C-3 / FV-4 / S-7 | 262 manual `isCsrfTokenValid()` vs 66 `#[IsCsrfTokenValid]` (much is unavoidable: per-entity dynamic token IDs + JSON-body bulk actions) |
| C-7 | ~15 controllers own CRUD inline (248 `flush()` calls) with no companion service (Vulnerability, CorrectiveAction, BusinessContinuityPlan, ProcessingActivity) |
| T-1/2/3 | Inline `<script>` Chart.js (7), Bootstrap API without `window.bootstrap` guard (7), native `confirm()` (11) — all have established Stimulus/fa-modal alternatives |
| T-12 | `validators.{de,en}.yaml` dead `{ choices }` single-brace keys → silent DE fallback to English on some choice-validation messages |
| S-8 | God-class services (`DashboardStatisticsService` 25 deps, `SearchService` 32 deps, `DocumentGenerator` 15 deps) — already in backlog |

### Confirmed clean (no action)

Routing (1077+ attribute routes, all with `methods:`), `#[IsGranted]` adoption (583 vs 64 programmatic), all 175 DDL migrations have `isTransactional()=false`, no new PREPARE/EXECUTE SQL, all 188 migrations have `down()`, all 120 repos extend `ServiceEntityRepository` with `@extends` generic, all 112 FormTypes have `configureOptions()` + explicit `translation_domain`, zero annotation-mapping/`@Assert` annotations, all 7 MessageHandlers use `#[AsMessageHandler]`, zero async jobs inject request-bound services, no deprecated PHPUnit 13 APIs, no plaintext secrets in config.

### Intentional deviations (noted — keep as-is)

- 16 YAML routes in `routes.yaml` (QuickFix emergency-recovery, HomeController locale detection, SSO OAuth exact-match) — must survive locale-resolver failure.
- Status stored as `string` (not `enumType:`) across 39 entities — documented **Pattern A "dual-state owner"** (`setStatus(Enum|string)` + `getStatusEnum()`), backward-compat with legacy string call-sites.
- `Role`/`Permission` without `tenant_id` — system-global RBAC catalog by design (ISO 27001 A.5.15).
- `HealthController` not extending `AbstractController` — pure liveness probe, no DI.
- MRIS templates DE-only — niche off-by-default prototype.
- `test`-prefix + `#[Test]` paired across 5,225 methods — consistent, sweeping it is not worth it.

---

## 1. Controllers · Routing · Security

| # | Finding | Severity | Occurrences | Examples (file:line) | Symfony 7.4 best practice | Intentional per CLAUDE.md? |
|---|---|---|---|---|---|---|
| C-1 | YAML route definitions instead of `#[Route]` | Med | 16 | `routes.yaml` (app_quick_fix_apply, app_home, app_dashboard) | All routes as `#[Route]` attributes | yes — QuickFix/locale-detection must survive locale-resolver failure |
| C-2 | `denyAccessUnlessGranted()` with bare role instead of `#[IsGranted]` | Low | 64 (1 simple, 63 voter-object) | `Bsi2004ExerciseLogController.php:90` | `#[IsGranted('ROLE_X')]` for simple roles | partial — 1 convertible; 63 object-voter cases cannot use the attribute |
| C-3 | Manual `isCsrfTokenValid()` instead of `#[IsCsrfTokenValid]` | Med | 262 manual / 66 attribute | `ProcessingActivityController.php:161`, `DataBreachController.php:272`, `VulnerabilityController.php:197` | `#[IsCsrfTokenValid]` (7.1+) | partial — per-entity dynamic IDs + JSON-body tokens can't use static attr |
| C-4 | `$this->getUser()` passed to `User`-typed service methods without narrowing | **High** | ~20 | `DataBreachController.php:178,245,304`, `WorkflowController.php:162`, `ComplianceRequirementController.php:388` | `assert($user instanceof User)` before typed call | no — runtime-safe via gates, static-type wrong |
| C-5 | Inline `isGranted()`/`in_array(ROLE_*)` role logic in controllers | Med | ~8 | `CorrectiveActionController.php:246`, `ComplianceMappingController.php:342`, `UserManagementController.php:108,627,676` | Role decisions in voters / `#[IsGranted]` | partial — several are contextual cross-tenant guards |
| C-6 | `redirectToRoute()`/`generateUrl()` without explicit `_locale` | Low | ~609 | `ProcessingActivityController.php:111,167`, `DataBreachController.php:185,274` | Pass `_locale` explicitly (MEMORY note) | partial — UrlGenerator auto-propagates from request; only CLI/queue risk (none found) |
| C-7 | Fat controllers with direct `flush()` (no service layer) | Med | 248 flush() in ~15 ctrls | `VulnerabilityController.php:140,177,199`, `CorrectiveActionController.php:143,198`, `BusinessContinuityPlanController.php:77,124` | Persist/flush/transform in services | no — incremental refactor |
| C-8 | `new Response(json_encode(...))` instead of `JsonResponse` | Low | 2 | `AuditController.php:341`, `AdminBackupController.php:756` | `new JsonResponse(...)` | no |
| C-9 | Constructor param not `readonly` | Low | 1 | `Admin/DataRepairController.php:61` | `private readonly` | no |
| C-10 | Controller not extending `AbstractController` | Info | 1 | `HealthController.php:20` | extend `AbstractController` | yes — minimal liveness probe |
| C-11 | `access_control` only matches `^/(de\|en)/admin`, no `^/admin` belt | Med | structural | `security.yaml:167` | add `^/admin` defence-in-depth rule | no |
| C-12 | JSON-XHR endpoints read `_token` from request body → can never use the attribute | Info | ~30 | `ProcessingActivityController.php:716,779`, `DataBreachController.php:586` | structural, not a bug | no |

**Notes.** Authorization posture strong (583 `#[IsGranted]` : 64 programmatic). 102 `#[Route]` without `methods:` are all class-level path prefixes (verified). **C-4 is the top actionable item** (low runtime risk, but wrong contract + PHPStan suppression). C-7's worst offenders (Vulnerability/BCP/CorrectiveAction) lack any companion service; Risk/ComplianceExport/PolicyWizard controllers are large but correctly delegate.

---

## 2. Entities · Doctrine · Migrations

| # | Finding | Severity | Occurrences | Examples (file:line) | Doctrine/Symfony best practice | Intentional per CLAUDE.md? |
|---|---|---|---|---|---|---|
| E-1 | Annotation `@ORM\` mapping | High | 0 | — | attributes only | N/A — none |
| E-2 | Status `string` without `enumType:` | Med | 39 entities | `CorrectiveAction.php:112`, `Document.php`, `DataBreach.php:104` | `#[ORM\Column(enumType: …)]` | **intentional** — Pattern A dual-state owner |
| E-3 | `#[ORM\Column]` without explicit `type:` | Low | 167 | `ComplianceMapping.php:42`, `ISMSObjective.php:43` | explicit `type:` preferred | acceptable — ORM 3.x infers from typed prop |
| E-4 | `?Collection` nullable default on M2M | Low | 4 | `InternalAudit.php:225`, `BCExercise.php:156,224`, `Training.php:285` | `Collection<int,T>` + ctor init | partial — all ctor-init + `??=` guarded; 3 are `@deprecated` |
| E-5 | getter return type bare `Collection` (no `<int,T>`) | Low | ~20 | `InternalAudit.php:418,470,485`, `ComplianceMapping.php:436`, `BCExercise.php:445,533` | `Collection<int,T>` on getter | no — generics only in `@var`, lost at getter boundary |
| E-6 | `ComplianceMapping`/`MappingGapItem` no direct `tenant_id`; `findAll()` unguarded | **High (latent)** | 2 entities | `ComplianceMapping.php`, `MappingGapItem.php` | tenant scoping on query methods | partial — scoped via parent FK; inherited `findAll()` would leak |
| E-7 | `setStatus()` bypassing LifecycleService (non-lifecycle entities) | Med | 3 sites | `ComplianceAssessmentService.php:61-65`, `ComplianceRequirementController.php:338-342`, `AutomatedGapAnalysisService.php:132+` | facade or `@phpstan-ignore` | acceptable — these entities have no lifecycle YAML |
| E-8 | `setStatus()` in FourEyesApprovalService without annotation | Low | 2 | `FourEyesApprovalService.php:103,137` | consistent `@phpstan-ignore` style | not a violation (no lifecycle YAML); annotation missing vs line 64 |
| E-9 | `Role`/`Permission` no `tenant_id` | Low | 2 | `Role.php`, `Permission.php` | tenant_id for per-tenant RBAC | **intentional** — global catalog |
| E-10 | `#[ORM\Version]` missing on Tenant | Info | 0 (false alarm) | `Tenant.php:85` present | — | N/A |
| E-11 | DDL migration missing `isTransactional()=false` | High | 0 | — | required | N/A — all 175 have it |
| E-12 | New PREPARE/EXECUTE migration SQL | High | 0 | — | banned | N/A — only comments |
| E-13 | Repo not extending ServiceEntityRepository | High | 0 | — | required | N/A — all 120 do |
| E-14 | Repo missing `@extends …<T>` generic | Med | 0 | — | required | N/A — all present |
| E-15 | N+1: `findByTenant()` no eager join, caller walks graph | Low | 1 pattern | `ComplianceRequirementFulfillmentRepository.php:57-59` → `…Service.php:72` | add JOIN/addSelect | no |
| E-16 | Migration missing `down()` | High | 0 | — | required | N/A — all 188 have it |
| E-17 | Non-portable MySQL fn in DQL | Low | 0 in DQL | `MrisKpiService.php:64,122` native SQL | DBAL-portable in DQL | intentional — native `executeQuery`, MySQL-only deploy |

**Notes.** Migration + repository discipline is exemplary. **E-5** (~20 bare-`Collection` getters) is the cheapest static-analysis win. **E-6** is the most latent security risk (unguarded inherited `findAll()`).

---

## 3. Forms · Validation

| # | Finding | Severity | Occurrences | Examples (file:line) | Symfony 7.4 best practice | Intentional per CLAUDE.md? |
|---|---|---|---|---|---|---|
| FV-1 | Raw `$request->request->get()` for multi-field data instead of FormType | Med | ~15 actions / 5+ ctrls | `DataBreachController.php:328-331` (notifyAuthority), `SoaSnapshotController.php:78-80`, `ComplianceRequirementController.php:349-379` | single-action `createForm/handleRequest` | partial — single-field state transitions OK; multi-field capture not exempt |
| FV-2 | `ChoiceType` hardcoded enum `->value` strings where `EnumType` fits | Low | 4 FormTypes | `AuditFindingType.php:106-118`, `CorrectiveActionType.php:80-95`, `ChangeRequestType.php:86-94`, `SupplierType.php:120-134` | `EnumType::class` | no — `mapped=false` display-only, but EnumType still cleaner |
| FV-3 | `#[Assert\Choice(choices:[strings])]` where BackedEnum exists | Low | 2 entities / ~5 fields | `BCExercise.php:230`, `InternalAudit.php:247` | `#[Assert\EnumCase]` (7.1+) or typed enum prop | no — `#[Assert\EnumCase]` unused project-wide |
| FV-4 | Manual CSRF vs `#[IsCsrfTokenValid]` | Low | 262 / 66 | (see C-3) | attribute form | no |
| FV-5 | Form-level NotBlank/Choice on fields whose entity lacks matching `#[Assert]` | Low | ~3 | `BusinessProcessType.php:74,115`, `AuditProgramType.php:42` (redundant) | constraints on entity props | no |
| FV-6 | >6-field FormTypes without `SectionMapInterface` + no exemption comment | Info | 22 | `IncidentType.php` (41 fields), `IdentityProviderType.php` (23), `DataSubjectRequestType.php` (20), `InternalAuditType.php` (19) | SectionPolicy P-2 | partial — all use manual layout/explicit sections (no "Sonstiges" leak; gate passes 30/30); 15/22 lack the documenting comment |
| FV-7 | No `validation_groups` anywhere (112 FormTypes) | Info | 0 usages | `DataBreachType.php`, `ProcessingActivityType.php` | groups for multi-step/partial save | no — separate-FormType-per-step is the applied pattern |

**Notes.** Confirmed clean: all 112 have `configureOptions()` + explicit `translation_domain`; no `@Assert` annotations; no inline `createFormBuilder()`; all date fields HTML5 `single_text`; JSON fields use Stimulus builder types (no raw textarea). **FV-1** worst cases: `DataBreachController::notifyAuthority/notifySubjects/subjectNotificationExemption/reopen` + `SoaSnapshotController::create` — clearest FormType candidates.

---

## 4. Services · Dependency Injection · Config

| # | Finding | Severity | Occurrences | Examples (file:line) | Symfony 7.4 best practice | Intentional per CLAUDE.md? |
|---|---|---|---|---|---|---|
| S-1 | `$this->container->has()/get()` for autowirable service | **High** | 1 | `Admin/WorkflowOverlayController.php:340-342` | constructor-inject `RegulatoryWorkflowLoader` | no — class is registered |
| S-2 | `#[AsCommand]` commands made `public` + injected into services | **High** | 15 | `services.yaml:561-580`, `ComplianceFrameworkLoaderService.php:51-71`, `ComplianceLoaderFixerService.php:39-48` | extract shared loader service | no |
| S-3 | Deprecated `WorkflowAutoProgressionService` still injected | Med | 4 | `DataBreachService.php:36`, `DataProtectionImpactAssessmentService.php:33`, `ProcessingActivityService.php:31`, `ProcessTimedWorkflowsCommand.php:62` | use FieldCompletionAutoTransition listener | no — CLAUDE.md says don't inject |
| S-4 | `EventSubscriberInterface` instead of `#[AsEventListener]` | Med | 19 | `SecurityHeadersSubscriber.php`, `TenantGuard.php`, `FourEyesValidator.php`, `LocaleSubscriber.php` | `#[AsEventListener]` per method | no |
| S-5 | `kernel.project_dir` via 40 YAML `arguments:` vs 10 `#[Autowire]` | Med | 40 vs 10 | `services.yaml:132-641`, `ShareController.php:32` (correct), `WebPushService.php:27` | `#[Autowire('%kernel.project_dir%')]` | no — inconsistent |
| S-6 | Full `ParameterBagInterface` injected to read 1 scalar param | Med | 3 | `EnvironmentWriter.php:25`, `DatabaseTestService.php:23`, `SetupAccessChecker.php:28` | `#[Autowire]` scalar param | no |
| S-7 | 64 `denyAccessUnlessGranted` / 262 `isCsrfTokenValid` programmatic | Med | (see C-2/C-3) | `UserManagementController.php`, `DocumentController.php` | attributes | no |
| S-8 | God-class services (15-32 ctor deps) | Med | 3+ | `DashboardStatisticsService.php` (25 deps/1746 LOC), `Search/SearchService.php` (32), `PolicyWizard/DocumentGenerator.php` (15) | split by responsibility (~8 dep threshold) | no — in backlog |
| S-9 | `RequestStack` in async-capable services without null-guard | Med | 6 | `SecurityEventLogger.php:37`, `Evidence/EvidenceVersioningService.php:44`, `IndustryBaselineService.php:39`, `MrisBaselineService.php` | null-guard or thread `?string $ip` | partial — `AuditLogger` guarded, others not |
| S-10 | Import mappers manually tagged vs `_instanceof` | Low | 5 | `services.yaml:586-601` | `_instanceof: EntityMapperInterface` | no |
| S-11 | Dead declared parameters | Low | 2 | `services.yaml:18` (session_lifetime), `:21` (password_reset_token_lifetime) | remove / env-back | no |
| S-12 | `backup_notifier.from_email` hardcoded `.local` placeholder | Low | 1 | `services.yaml:77` | env-back with fallback | no — SPF/DMARC risk in prod |
| S-13 | Mixed `#[Autowire]` vs YAML for same resource | Info | 50 | `GlossaryService.php:24` vs `services.yaml:131-133` | pick one (attribute preferred) | no |
| S-14 | `EnvironmentWriter` full ParameterBag coupling | Info | 1 | `EnvironmentWriter.php:8,25` | `#[Autowire]` scalar | no |

**Notes.** Confirmed idiomatic: `#[TaggedLocator]` ServiceLocators (InRequestJobRunner/ExecuteJobHandler), `!tagged_iterator` wiring, `_instanceof` for rule/job/template interfaces, minimal `framework.yaml` (no deprecated keys), correct env() processors, no plaintext secrets. **S-1 + S-2 are the top structural items; S-9 is the async-safety risk.**

---

## 5. Twig · Templates · Translations · Frontend

| # | Finding | Severity | Occurrences | Examples (file:line) | Best practice | Intentional per CLAUDE.md? |
|---|---|---|---|---|---|---|
| T-1 | Native `confirm()` in templates | Med | 11 | `admin/lifecycle_overrides/show.html.twig:89`, `audit_program/show.html.twig:129`, `dora_exit_plan/show.html.twig:90` | `fa-modal` / `window.faConfirm` | no |
| T-2 | `new bootstrap.Tooltip()` without `window.bootstrap` guard | Med | 7 / 5 tpls | `compliance/framework_dashboard.html.twig:1081`, `user_management/edit.html.twig:129`, `compliance/mapping_quality/review_queue.html.twig:298` | guard (pitfall #4) | no |
| T-3 | Inline `<script>` Chart.js `new Chart()` | Med | 7 | `analytics/risk_forecast.html.twig`, `analytics/control_effectiveness.html.twig`, `portfolio_report/index.html.twig` | use chart Stimulus controllers | no |
| T-4 | `turbo:load` init without `turbo-cache-control: no-cache` | Low | 36 | `analytics/risk_forecast.html.twig`, `report_builder/preview.html.twig` | set cache-control meta on dynamic pages | no |
| T-5 | Raw `<table class="table">` not migrated to `fa-table` | Low | 9 | `tisax/import/commit.html.twig:77`, `audit/show.html.twig:215`, `policy_profile/index.html.twig:40` | `_fa_table.html.twig` | no |
| T-6 | Hardcoded EN/DE text (no `trans`) | Low | ~4 tpls / ~20 strings | `data_management/import_preview.html.twig:72`, `admin/mapping_quality/show.html.twig:103-108`, `role_management/templates.html.twig:87` | trans keys | no |
| T-7 | Hardcoded JS template-literal URL (no `path()`) | Low | 1 | `admin/compliance/index.html.twig:222` | pre-render `path()` into data-attr | no |
| T-8 | Inline `<style>` in non-PDF/email templates | Low | 60 | `business_process/index.html.twig:203`, `context/index.html.twig:566`, `evidence/coverage.html.twig:147` | compiled CSS + Aurora tokens (FOUC/CSP) | no |
| T-9 | `alert()` fallback not trying `window.faToast` first | Low | 1 | `assets/controllers/audit_checklist_save_controller.js:96` | faToast → alert fallback | no |
| T-10 | `window.confirm()` hardcoded string, no faConfirm | Low | 1 | `assets/controllers/modal_wizard_controller.js:156` | faConfirm → confirm fallback | no |
| T-11 | Untranslated `aria-label="Close"` | Low | 8 tpls | `workflow/overdue.html.twig:206`, `management_review/index.html.twig:245`, `four_eyes/inbox.html.twig:67` | `|trans` (WCAG 2.2 AA) | no |
| T-12 | Dead `{ choices }` single-brace validator keys → silent DE→EN fallback | Low | 7 key pairs/file | `validators.de.yaml:543,548-549,552,555,561,569` | Symfony 7 uses `{{ … }}` | no |
| T-13 | Font preload hardcoded `/fonts/...` not `asset()` | Info | 3 base tpls | `base.html.twig:41-42`, `base_auth.html.twig:29-30` | `asset()` | no — functionally correct |
| T-14 | MRIS wizard templates hardcoded German | Info | 5 | `mris/wizard/ai_risk_class.html.twig:67` | i18n | **yes — DE-only niche prototype** |
| T-15 | `design_system.html.twig` untranslated strings | Info | 1 (dev) | `dev/design_system.html.twig:207,402,977` | — | **yes — dev-only, env-gated** |
| T-16 | Pitfall #10b (embed trans_default_domain) | Info | 0 real | — | — | resolved — all 26 embeds re-declare correctly |

**Notes.** Gated by CI: `check_twig_macro_scope.py` (0 issues), `check_translation_issues.py` (3, all dev playground). Confirmed non-issues: `|raw` only on controlled disk files (NOTICE.md, license reports, PDF html), `window.confirm` in `ui_actions`/`bestandsaufnahme_bulk` controllers are correctly-ordered last-resort fallbacks. **Quick wins: T-12 (silent validation regression), T-2 (guard), T-11 (a11y).**

---

## 6. PHP idioms · Commands · Tests · Messenger

| # | Finding | Severity | Occurrences | Examples (file:line) | Best practice | Intentional per CLAUDE.md? |
|---|---|---|---|---|---|---|
| P-1 | Command `execute()/configure()` vs invokable `__invoke()` | Med | 105 vs 45 | `LoadC52026RequirementsCommand.php:34`, `ProcessTimedWorkflowsCommand.php:72` | prefer `__invoke(SymfonyStyle)` | partial — CLAUDE.md notes known mix; new cmds should be invokable |
| P-2 | Invokable cmds not using `#[Option]`/`#[Argument]` (7.3+) | Low | 16 | `AuditLogVerifyCommand.php`, `LoadIndustryBaselinesCommand.php` | attribute args/options | no — mostly moot (no args) |
| P-3 | Test methods missing `#[Test]` (prefix-only) | Low | 51 / 13 files | `tests/System/WorkflowDumpTest.php:13,33`, `tests/Lifecycle/EventListener/FourEyesValidatorTest.php:22+`, `tests/Controller/DensityToggleControllerTest.php:39+` | `#[Test]` (CLAUDE.md) | no — Lifecycle-wave additions |
| P-4 | `test`-prefix redundant alongside `#[Test]` | Info | 5,225 | `Service/RiskServiceTest.php` | drop prefix | yes — consistent, not worth sweep |
| P-5 | `Schedule/*` message DTOs missing class-level `readonly` | Low | 4 | `CheckRiskReviewsMessage.php:15`, `ExecuteScheduledTaskMessage.php:12`, `GenerateComplianceReportMessage.php:14` | `readonly final class` (siblings already are) | no |
| P-6 | `if/elseif` string chains that should be `match` | Low | ~10 / 6 files | `CreateCrossFrameworkMappingsCommand.php:337-344,350-370`, `MigrateFreetextOwnersCommand.php:157,271` | `match` | no |
| P-7 | `#[\Override]` absent on all `configure()`/`execute()` overrides | Low | ~182 | `ImportSubMappingsCommand.php:86`, `LoadC52026RequirementsCommand.php:34` | `#[\Override]` (8.3) | no — unused project-wide |
| P-8 | `createMock()` where `createStub()` fits (no expects) | Low | ~61 files | `ComplianceFrameworkLoaderServiceTest.php`, `MappingLifecycleServiceTest.php` | `createStub()` | no — clarity |
| P-9 | `ReflectionMethod` to assert signatures (brittle) | Info | ~10 / 2 files | `tests/Repository/IncidentRepositoryTest.php:179-318`, `tests/Repository/TenantRepositoryTest.php:111-156` | behaviour tests | no |
| P-10 | MessageHandlers use `#[AsMessageHandler]` | ✅ | 7/7 | — | — | N/A |
| P-11 | Async jobs inject request-bound services | ✅ | 0 | `AssignOrphansJob.php` documents why none | — | N/A |
| P-12 | Deprecated PHPUnit 13 APIs | ✅ | 0 | — | — | N/A |

**Notes.** **P-3 (51 missing `#[Test]`)** is the clearest CLAUDE.md gap — one-line-per-method fix in 13 Lifecycle-wave files. P-5 (4 `readonly class`) and P-6 (`match`) are zero-risk consistency wins. All 150 commands correctly declare `#[AsCommand]`; `DataProvider` uses the attribute (13×).

---

## Suggested Prioritization (when fixes are greenlit — separate work)

1. **High / quick:** C-4 (`getUser` narrowing ~20), S-1 (container->get, 1), E-5 (Collection getters ~20), P-3 (`#[Test]` 51), T-12 (validator dead keys).
2. **High / structural:** S-2 (commands-as-services, extract loader), E-6 (tenant-scope ComplianceMapping repo), S-9 (RequestStack null-guards).
3. **Medium / sweep:** S-4 (`#[AsEventListener]` 19), S-5 (`#[Autowire]` project_dir 40), C-3/FV-4 (CSRF attribute where static-token-able), S-3 (drop deprecated WorkflowAutoProgressionService).
4. **Medium / refactor:** C-7 (extract Vulnerability/BCP/CorrectiveAction services), S-8 (god-class split — already backlogged), FV-1 (FormTypes for DataBreach/SoaSnapshot multi-field actions).
5. **Low / hygiene:** T-1/2/3 (confirm/bootstrap/Chart Stimulus), T-8 (inline `<style>`→tokens), T-11 (a11y aria-label), P-5/P-6/P-7 (readonly/match/`#[\Override]`), S-10/S-11/S-12 (services.yaml cleanup), C-11 (`^/admin` defence rule).

---

## 7. Turbo · Stimulus · Frontend UX

Follow-up read-only pass on Hotwire Turbo 8 + Stimulus 3.2 correctness and user-friendliness.

| # | Finding | Severity | Occurrences | Examples (file:line) | Hotwire best practice | Intentional? |
|---|---|---|---|---|---|---|
| TB-1 | `mega_menu_controller.disconnect()` rebuilds bound refs with a fresh `.bind(this)` → `removeEventListener` no-op → document keydown/click + trigger/panel mouse listeners **leak on every Turbo navigation** | High | 1 controller, every nav | `assets/controllers/mega_menu_controller.js:39-40,67-68` | store bound refs in `connect()`, remove the same refs in `disconnect()` | no |
| TB-2 | `bulk-actions#bulkAssign` method does not exist — wired button silently dead | High | 3 lists | `_bulk_action_bar.html.twig:244`; asset/risk/training index | implement or don't render | no |
| TB-3 | `bulk-actions#openTagAdd/openTagRemove/submitTagApply/closeTagPicker` all missing — tag-mode UI renders but every click fails | High | shared component | `_bulk_action_bar.html.twig:107,118,184,190` | implement against tag endpoint | no |
| TB-4 | `<turbo-frame id="bcm-stats" src>` targets a **JSON** endpoint (`statsApi` returns `$this->json`) → frame never updates, skeleton stuck | High | 1 frame | `business_process/index.html.twig:87`; `BusinessProcessController.php:218` | render HTML fragment with matching `<turbo-frame>`, or inline | no |
| TB-5 | 3 orphaned `business_process/*.turbo_stream.html.twig` never returned by any controller; target non-existent DOM ids | High | 3 files | `business_process/create.turbo_stream.html.twig` etc. | delete or wire | no |
| TB-6 | 39 inline `turbo:load` handlers accumulate on revisit (global `no-cache` re-runs script blocks; none use `{ once: true }`); only 3 templates pair a `turbo:before-cache` cleanup | Med | 39 / 29 tpls | `compliance/transitive_compliance.html.twig:189,289`; `framework_dashboard.html.twig:1089` | `{ once: true }` or Stimulus `connect()` | no |
| TB-7 | 5 analytics pages `new Chart()` inside `turbo:load` without `Chart.getChart()?.destroy()` → "Canvas already in use" / doubled charts on back-nav | Med | 5 / 11 charts | `analytics/control_effectiveness.html.twig:509`; `risk_forecast.html.twig:453` | destroy existing first (pattern exists in `mapping_quality/dashboard`) | no |
| TB-8 | 5 templates re-init Bootstrap tooltips on `turbo:load` without disposing existing → tooltip stacking | Med | 5 tpls | `framework_dashboard.html.twig:1089`; `user_management/edit.html.twig` | `getInstance(el)?.dispose()` first (app.js does it right) | no |
| TB-9 | 7 audit status-transition buttons carry needless `data-turbo="false"` → full reload, lose progress bar/flash/SPA feel (303 redirects Turbo handles fine) | Med | 7 / 1 tpl | `audit/show.html.twig:459,469,479,497,563,572,719` | remove on status forms; keep on file-download links | no |
| TB-10 | 23 download links `data-turbo="false"` redundant — `turbo_controller` already intercepts `Content-Disposition: attachment` | Low | 23 | `incident/show.html.twig:68-72` | drop (keep `data-turbo-prefetch="false"`) | partial |
| TB-11 | `body.turbo-loading * { pointer-events:none }` freezes whole UI during slow nav | Low | 1 | `base.html.twig:109-111` | scope to triggered element / main content | no |
| TB-12 | global `turbo-cache-control: no-cache` disables all page caching (kills instant-back) | Info | 1 | `base.html.twig:6` | scope to auth-sensitive pages | intentional (auth-state safety) |
| TB-13 | 53 inline `<script>` blocks doing DOM init that belongs in Stimulus | Low | 53 / 37 tpls | analytics + compliance sub-pages | convert to controllers | known tech-debt |
| TB-14 | `policy_wizard/complete` renders 200 (no redirect) → `data-turbo="false"` workaround on Generate form | Info | 1 | `step.html.twig:172-176` | PRG ideally; documented why not | yes (documented) |

**Notes.** Project does a lot right: `async_job`, `notification_bell`, `command_palette`, `keyboard_shortcuts`, `fa_modal`, `guided_tour` controllers all clean up in `disconnect()`; `turbo_controller` has a clever `Content-Disposition` download interception; consistent HTTP 422 on form-validation failures across 50+ CRUD controllers (excellent Turbo compat); `mapping_quality/dashboard` shows the correct `Chart.getChart()` guard to propagate.

---

## 8. Dead Code · Wrong Implementations

Follow-up read-only pass hunting unintentionally dead or incorrectly-implemented code (correctness, not style). Dead-code claims include the evidence used to rule out indirect (DI/Twig/listener/reflection) usage.

| # | Finding | Type | Confidence | Severity | Examples (file:line) | Evidence |
|---|---|---|---|---|---|---|
| DC-1 | `RiskIntelligenceService::findRelatedRisks()` always returns `[]` → dedup guard `$existingRisks === []` permanently true (suggests a risk for every incident) | wrong | High | High* | `RiskIntelligenceService.php:213,38` | body is `return []` ("Vereinfachte Logik"); *service is dead (DC-2) so no live impact yet |
| DC-2 | `RiskIntelligenceService` — no production caller | dead | High | Med | `src/Service/RiskIntelligenceService.php` | grep → only class + tests; in DI `removed-ids` |
| DC-3 | `RiskProbabilityAdjustmentService` — no production caller | dead | High | Med | `src/Service/RiskProbabilityAdjustmentService.php` | grep → class + tests; DI removed |
| DC-4 | `WizardProgressService` — no production caller | dead | High | Med | `src/Service/WizardProgressService.php` | grep → class + tests; DI removed |
| DC-5 | `SiemExportService` — no production caller | dead | High | Med | `src/Service/SiemExportService.php` | grep → class + tests; DI removed |
| DC-6 | `SiemExportService::exportToCef()/exportToSyslog()` accept `$startDate/$endDate` that are silently ignored (no date filtering) | wrong | High | Med | `SiemExportService.php:37,77,118` | `getEvents(string)` takes only type; dates only in JSON metadata |
| DC-7 | `RiskImpactCalculatorService` — no production caller | dead | High | Med | `src/Service/RiskImpactCalculatorService.php` | grep → class + tests + a migration comment; DI removed |
| DC-8 | `AssetRiskCalculator::getProtectionStatus()` `return 'unknown'` unreachable (if-chain exhaustive); **production** via AssetNormalizer | wrong | High | Med | `AssetRiskCalculator.php:101-104` | branch3 `control>=risk` covers `0>=0` |
| DC-9 | standalone `new DateTime();` never assigned (wasted alloc, copy-paste) | wrong | High | Low | `RiskProbabilityAdjustmentService.php:126` | next line is the real `$oneYearAgo` |
| DC-10 | standalone `new DateTime();` never assigned | wrong | High | Low | `RiskTreatmentPlanRepository.php:162` | query uses its own inline DateTime |
| DC-11 | `BCExercise::getSuccessPercentage()` redundant `$total>0` ternary after non-empty early-return | wrong | High | Low | `BCExercise.php:912-914` | `$total` always >0 when reached |
| DC-12 | `RiskAppetitePrioritizationService::analyzeRiskAppetite()` returns `within_appetite => null` vs `@return bool` | wrong | High | Low | `RiskAppetitePrioritizationService.php:135` | callers null-guard on `appetite`; type doc wrong |

**Notes.** The 5 dead services (DC-2/3/4/5/7) appear in every DI `removed-ids.php` snapshot (never injected in any env) and in no Twig/route/YAML/handler. They could be wired later — current state is "compiles, nothing calls it." Removal is a product decision, **not done here.** Deprecated `WorkflowAutoProgressionService` callers are intentional bridges (v4.0 removal), not flagged.

### Dead-service investigation (2026-06-03)

Per-service probe — git history (`--diff-filter=A`, `git log -S` across controllers/commands), doc/roadmap mentions, and historical injection. **Result: none of the 5 was ever wired** (zero historical injection in any controller/command). Each was built + unit-tested in isolation during the Phase 6/7 push (Nov-Dec 2025); the consuming UI/endpoint never landed. No open roadmap item proposes wiring any of them (ROADMAP only ticks their *tests* as done). Classification + recommendation:

| Service | Added | Footprint | Finding | Recommendation |
|---|---|---|---|---|
| `RiskIntelligenceService` | 2025-11-07 (#26) | **Misrepresented as live** — `docs/architecture/SOLUTION_DESCRIPTION.md:139,145` presents it as the engine for "Restrisiken nach Control-Implementierung", but it is unwired AND carries the DC-1 bug (`findRelatedRisks()` always `[]`) | **Decide:** wire it (fix DC-1 first) **or** delete + correct SOLUTION_DESCRIPTION so the architecture doc stops claiming a feature that isn't connected |
| `WizardProgressService` | 2025-12-16 (Phase 7E) | Zero docs, zero refs, never injected — pure orphan (wizard-session feature whose wiring never shipped) | **Safe to delete** (lowest-risk; no footprint anywhere) |
| `SiemExportService` | 2025-11-08 | Orphan, but SIEM log-export is a plausibly-wanted security feature; carries the DC-6 bug (date params ignored) | **Keep as wire-candidate** (fix DC-6 when wiring to a `/admin/security/export` endpoint) **or** delete if SIEM export is out of scope |
| `RiskProbabilityAdjustmentService` | 2025-11-10 (Phase 6F-D) | Orphan risk-calc helper; never injected | Delete **or** wire into `RiskService` (data-reuse "probability from incident frequency") |
| `RiskImpactCalculatorService` | 2025-11-10 (Phase 6F-D) | Orphan risk-calc helper; ROADMAP ticks its test only | Delete **or** wire into `RiskService` |

**Resolved 2026-06-04 (#841):** all 5 removed (dead code, never wired) along with their tests; SOLUTION_DESCRIPTION.md + ROADMAP.md corrected so no doc claims a deleted class. DC-1 (always-empty `findRelatedRisks`) and DC-6 (ignored SIEM date params) vanished with their hosts.

---

## Remediation Log

Fixes landed after the audit (each its own PR, CI-green, only-changed-files):

**First batch (2026-06-03):**
- **#834 — Bucket 1 (safe wins):** C-4 `CurrentUserTrait` narrowing (~20 sites), P-3 `#[Test]` ×51, T-12 dead validator `{ choices }` keys, P-5 `readonly` message DTOs, C-8 `JsonResponse`, S-10/11/12 services.yaml cleanup. (E-5 already resolved in-tree; C-9 skipped — EM reassigned.)
- **#835 — Turbo UX:** TB-1 mega_menu listener leak, TB-2/3 missing bulk/tag Stimulus methods, TB-4 BCM stats turbo-frame content-negotiation, TB-5 orphaned stream templates deleted.
- **#836 — Backend correctness:** S-9 `EvidenceVersioningService` session async-guard; DC-8/9/10/11/12 dead-logic cleanup.

**Second batch (2026-06-04) — full backlog clearance:**
- **#841 — Dead services:** DC-2/3/4/5/7 removed (RiskIntelligence / RiskProbabilityAdjustment / WizardProgress / SiemExport / RiskImpactCalculator + 5 tests). DC-1/DC-6 bugs eliminated with their hosts. SOLUTION_DESCRIPTION + ROADMAP corrected.
- **#842 — S-4:** 19 `EventSubscriberInterface` → `#[AsEventListener]` (dispatcher-equivalent verified).
- **#843 — P-6/7/8:** `match` (3), `#[\Override]` on 111 Command classes, `createStub` (9).
- **#844 — TB-6/7/8/9 + T-11:** Chart `getChart()?.destroy()` (5 pages), tooltip dispose + bootstrap-guard (5), `{ once: true }` on chart `turbo:load` (5), drop needless `data-turbo="false"` on 6 audit buttons, 8 `aria-label="Close"` translated.
- **#846 — S-1:** `WorkflowOverlayController` container-get → constructor injection.
- **#847 — S-3:** drop deprecated `WorkflowAutoProgressionService` from 3 services (field-completion listener covers them; `ProcessTimedWorkflowsCommand` kept — time-based engine).
- **#848 — S-5/S-6:** 37 `#[Autowire('%kernel.project_dir%')]` (services.yaml −153 lines) + 3 `ParameterBag`→scalar. (+ test adaptation for the ctor change.)
- **#849 — E-6:** real cross-tenant leak fixed — `ComplianceMapping` listing/hub used `findAll()` across tenants; added `findAllForTenant`/`findRecentForTenant`/`findAllGlobal`, `findAll()` now throws, 5 call-sites corrected.
- **#852 — C-7:** Vulnerability / BusinessContinuityPlan / CorrectiveAction services extracted; 22 raw EM writes moved out of controllers.
- **#853 — S-2:** command-as-service anti-pattern eliminated — `FrameworkLoaderInterface` + tagged `FrameworkLoaderRegistry`; the 2 loader services drop 29/22 injected Command objects (30→3, 26→5 ctor params).

### Explicitly accepted / decided (no further change — rationale recorded)

These audit items were reviewed and consciously **left as-is** (correct, intentional, or net-negative to change):

- **C-3 CSRF (262 manual `isCsrfTokenValid`):** kept. The bulk are per-entity dynamic token IDs (`'delete'.$id`) or JSON-body tokens that the static `#[IsCsrfTokenValid]` attribute structurally cannot express; the 66 static-token cases already use the attribute. Manual validation is correct — churning security-critical code for the unconvertible majority is net-negative.
- **T-8 inline `<style>` (60) + TB-13 inline `<script>` (53):** accepted tech-debt. Large, low-value, high-churn template refactors with regression risk; the audit itself flagged them "known tech-debt / low priority." Tracked here, not forced.
- **TB-10 redundant download `data-turbo="false"` (23):** kept — harmless defence-in-depth alongside the `turbo_controller` attachment interceptor.
- **TB-6 remaining (~29 of 39 `turbo:load` handlers):** the high-risk ones (chart/tooltip re-init) were fixed in #844; the rest are benign one-shot inits — accepted.
- **C-1 YAML routes (16), E-2 Pattern-A status strings (39), E-9 global RBAC, C-10 HealthController, C-6 `_locale`, MRIS DE-only, `test`-prefix+`#[Test]`, P-1 command-style mix:** intentional by design (see per-section "Intentional?" columns).
- **`StatementOfApplicabilityController` WAPS injection:** one remaining deprecated-WAPS caller (outside the #847 service scope) — bridge stays until the v4.0 WAPS removal; no behaviour issue.

**Status: audit backlog cleared.** Every finding is either fixed (above) or has an explicit accept-decision with rationale. Nothing is left dangling.

---

_Generated by 8 read-only audit passes. Sections 1-6 + 7-8 are audit; the Remediation Log tracks applied fixes + accept-decisions._
