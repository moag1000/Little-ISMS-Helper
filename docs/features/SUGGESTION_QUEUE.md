# Suggestion queue (Paket 5)

One generic mechanism for everything the tool proposes and a person decides:
derivations from the organisation's data, inherited fulfilment, import matches and
language-model output. User guide: `docs/user-guide/VORSCHLAEGE.md`.

## Pieces

| Piece | Where |
|---|---|
| Entity `Suggestion` (table `suggestion`, unique `tenant_id, kind, dedup_key`) | `src/Entity/Suggestion.php`, `SuggestionStatus` (pending/accepted/rejected/superseded), `SuggestionOrigin` (rule/inheritance/import/ai) |
| Kind contract | `src/Service/Suggestion/SuggestionKind.php` (tag `app.suggestion_kind`, autoconfigured), defaults in `AbstractSuggestionKind` |
| Queue | `SuggestionQueue`: `offer()` (dedup, reopen superseded twin, supersede older pending of the same kind and record), `accept()`, `reject()`, `supersede()`, `rememberRejection()`, `canDecide()`, `pendingFor()`, `pendingForSubject()` |
| Generator | `SuggestionGenerator::run($tenant, ?$kinds)` — sets the tenant context, runs each active kind, supersedes non-AI entries that were not offered again (`condition_gone`) |
| Schedule | `app:suggestions:generate [--kind=a,b]` at 05:30 in `Schedule::COMMAND_TASKS`; "Jetzt prüfen" → `RefreshSuggestionsJob` |
| UI | `SuggestionController` (`/suggestions`, list skeleton, `decision_modal()`, bulk bar `approve`/`reject` → `bulk-status-change`), detail hint in `_fa_entity_links.html.twig` (`pending_suggestions(entity)`), My Day via `SuggestionDeadlineSource` (bucket `suggestions`), SoA "Passt nicht" (`app_suggestion_mapping_reject`) |
| AI | `src/Service/Ai/`: `AiSettings` (instance + tenant settings, keys encrypted), `OpenAiCompatibleClient` (`/chat/completions`, JSON mode, external calls through `NoPrivateNetworkHttpClient`), `PromptRedactor`, `AiAssistant`; admin page `AiSettingsController` |
| Assistant (MCP) | `draft_options` / `propose_draft` (`src/Mcp/Tool/`): the person's own assistant drafts in place of the configured model — `RecordDrafts::kindsFor($record, byAssistant: true)`, `AbstractAiKind::assistantBrief()` (instruction + facts) and `draftFromAssistant()` (same `toDraft()` mapping, model `MCP: <token label>`), queued by `RecordDrafts::offer()`. Works while the organisation's AI is off; MCP opt-in per organisation |
| Responsibility | `SuggestionResponsibility::of()` = `Responsibility::ofRecord(subject)->orRoles(kind roles)` (import kinds and kinds whose UI write needs a role above ROLE_USER: roles only); `SuggestionQueue::canDecide()` = the kind's UI write right (`targetAccess()`, S30) **and** (kind role **or** `ResponsibilityResolver::isResponsible()`) |
| Visibility (S30) | `SuggestionQueue::isVisible()` / `visible()`: who decides; else a reading role (`ROLE_AUDITOR` line incl. managers), but suggestions about whole-class `#[PersonalDataResource]` records (breach, data subject request) only with `ROLE_DPO`. List (open + decided) use it; MCP `pending_suggestions` / `get_context` filter through `PersonalDataPolicy::suggestions()` |
| Concurrency (S30) | `Suggestion` has `#[ORM\Version]`; `accept()` runs in a transaction and re-reads the row with a write lock, so a second accept finds it decided. AI kinds: `AbstractAiKind::stillApplies()` (= `isCandidate()` on `candidateOf()`) is asked before `apply()`; a closed record makes the suggestion stale |
| Deadline source | `SuggestionDeadlineSource` (slug/bucket `suggestions`, decision, due `createdAt + 14 days`) — My Day and the digest list open suggestions for the responsible people |
| Texts | `translations/suggestions.{de,en}.yaml`; titles and rationales are rendered in the tenant language when the suggestion is generated (`SuggestionText`) |

## Adding a kind

1. Class in `src/Service/Suggestion/Kind/` extending `AbstractSuggestionKind` (or
   `AbstractAiKind` for a language-model use case).
2. `key()` (stable, e.g. `risk.residual`), `origin()`, `decisionRoles()` (roles from
   `security.yaml`), `requiredModule()`, `targetAccess()`: the UI action that does the same
   write (`TargetAccess::action(RiskController::class, 'edit')` — its `#[IsGranted]` is read
   live with the suggestion's record as subject), or `TargetAccess::role()` with a reason
   where no single action fits. `SuggestionKindAccessContractTest` checks every kind.
3. `generate(Tenant)` yields `SuggestionDraft`s: subject record, title, rationale (via
   `SuggestionText`), `payload` with everything `apply()` needs, optional `editableText`,
   `confidence`, and a `dedupKey` that encodes the state the proposal was made for
   (from-values), so a changed state produces a new proposal.
4. `apply()` re-reads the record, throws `StaleSuggestionException` when the state no
   longer fits, writes through the owning service (lifecycle via `LifecycleService`,
   fulfilment via the inheritance service), returns the changed or created record.
   Do not flush partial state before a possible stale exception.
5. Optional `onReject()` (e.g. close the mirrored inheritance log), `allowsBulk()`
   (false for kinds whose result needs reading).
6. Labels: `suggestions.kind.<key>` and `suggestions.kind_help.<key>` (DE + EN).

7. Optional "Entwurf vorschlagen" on the record's detail page: implement
   `RecordDraftKind` (`subjectClasses()`, `supportsRecord()`, `needsModel()`,
   `draftFor()`). AI kinds get it from `AbstractAiKind`: implement `subjectClasses()` and
   `isCandidate()` (the same test the nightly `candidates()` use); override `recordFor()`
   when the page's record is not the one the model reads (supplier page → questionnaire)
   and `alreadyAsked()` when the nightly "ask once" check is not by subject id. Button
   label: `suggestions.draft.button.<key>`.

Nothing else to register: the tag wires the kind into the queue, the generator, the
list filters and (for AI kinds) the admin use-case list.

One mechanism for many kinds: `Kind/Register/AbstractRegisterProposalKind` turns every
proposal of `RegisterProposalProvider` into a suggestion; each register type with
proposals is a three-line subclass (`register.<type>`, decided by the register's
maintaining persona). The register page and the queue write through the same
`RegisterProposalAdopter` and deduplicate by `origin`.

## Drafts on request (P6)

`_fa_entity_links.html.twig` shows one button per kind from `draft_kinds(entity)`
(`RecordDrafts::kindsFor()`): kind is a `RecordDraftKind`, module active, model available
(AI kinds), the user may decide it (UI write right and decision role or responsible for the record), no
pending suggestion of that kind for the record, `supportsRecord()`. `POST
/suggestions/draft` (`SuggestionDraftController`, CSRF `suggestion_draft`) resolves the
record only for a class in `subjectClasses()` and in the current organisation, runs rule
drafts at once and AI drafts as `DraftSuggestionJob` (shared progress page), then opens
the queue filtered to the record. Asking again after a decision gets its own dedup key
(`…:asked:<time>`), so the person's explicit request is never swallowed by the
"rejected stays rejected" rule.

Drafts that fill several fields use `SectionedText` (one editable text, a block per
field headed "Label:"; a removed heading keeps the drafted value), e.g.
`AiBreachNotificationKind` for GDPR Art. 33 (3) a, c, d.

| Kind | Record | Writes on accept |
|---|---|---|
| `soa.justification` (rule) | Control | justification from linked risks + requirements of other active frameworks + implementation state; nightly only while `ai.soa_justification` is unavailable |
| `ai.incident_report` | Incident | `reportSummary` (NIS-2 Art. 23 (4) / DORA Art. 19), sent by the MUS export as short description |
| `ai.breach_notification` | DataBreach | empty `breachNature`, `likelyConsequences`, `measuresTaken` |
| `ai.dsr_response` | DataSubjectRequest | `responseDescription` (identity verified where Art. 12 (6) requires it) |
| `ai.board_summary` | ManagementReview | `summary` (≥ 3 inputs of 9.3.2) |
| `ai.nonconformity_statement` | AuditFinding (major/minor NC) | `description` (unchanged notes only), `evidence` if empty |
| `ai.questionnaire_evaluation` | Supplier (latest answered questionnaire) | appended to `assessmentFindings` |
| `ai.bc_plan_from_bia` | BusinessProcess (BIA, no plan) | creates a `BusinessContinuityPlan` in draft: activation criteria, recovery procedures, communication; RTO/RPO via `BusinessContinuityPlanType::takeTargetsFromBia()`. ROLE_BCM_OFFICER, `bcm` |
| `ai.bc_exercise_evaluation` | BCExercise (held, completed/in progress) | empty `results`, `whatWentWell`, `lessonsLearned` only. ROLE_BCM_OFFICER, `bcm` |
| `ai.legal_requirement_from_party` | InterestedParty (free-text requirements, no `legalRequirements`, no register entry) | one `IsmsRegisterEntry` (LEGAL_REQUIREMENT) via `RegisterProposalAdopter`, origin `ai.party.<id>`, linked to the party. ROLE_COMPLIANCE_MANAGER/ISB, `interested_parties` |
| `ai.requirement_mapping` | ComplianceRequirement of an active framework without own control (nightly only: a catalogue row has no tenant, so no draft button) | links ≤ 5 own controls (model sees ≤ 40) through `ControlRequirementLinkKind::link()`; never a global `ComplianceMapping` row. `compliance` |
| `ai.vvt_from_supplier` | Supplier (`hasDPA`, no ProcessingActivity names it) | creates a `ProcessingActivity` in draft, supplier as processor; purpose, data subjects/categories from the form's value lists, retention; legal basis defaults to contract. ROLE_DPO, `privacy` |
| `ai.evidence_fit` | Control with evidence documents | a `Comment` ("Control") with verdict + audit note; stale when the evidence list changed. ROLE_ISB/CISO, `audits` |
| `ai.audit_sample` | InternalAudit (planned / in progress) | appends the sample (controls and scoped assets with reason) to `observations`. ROLE_ISB/CISO, `audits` |
| `ai.risk_acceptance_explained` | Risk (strategy accept, justification given, not formally accepted) | a `Comment` ("Risk") with the plain-language explanation; never accepts the risk. ROLE_RISK_MANAGER/CISO (+ risk owner), `risks` |
| `ai.decision_rationale` | ManagementDecision (pending, empty `proposal`) | fills `proposal` only while empty; status stays with the decision workflow. ROLE_ISB/CISO, `core` |

Auditors are read-only (`ReadOnlyRoleSubscriber` does not allow `app_suggestion_*`), so audit drafts are decided
by ISB/CISO. Accepted notes become comments through `SuggestionNote`.

## Rules

- Generation never changes records; only `apply()` does, and only after a person
  accepted.
- Accept and reject write `suggestion.accepted` / `suggestion.rejected` to the audit log
  (kind, origin, model, subject, result, edited). `ai.request` logs use case, model,
  host, external, redaction, ok — never prompt or answer text.
- AI kinds ask once per record (any status), at most 10 per run and use case, and do
  not take part in the `condition_gone` sweep.
- External AI endpoints: https, public address (`NoInternalIp`), no credentials in the
  URL; only the instance endpoint (SUPER_ADMIN) may be local.
- Personal data is redacted before external calls unless the organisation switched
  `allow_personal_data` on.

## Not in the queue

Bulk import wizard previews, QuickFix repairs and pure approval steps (workflow,
four-eyes) keep their own screens: they are decisions on a whole run or already a
decision record, not proposals about one record.
