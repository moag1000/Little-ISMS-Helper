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
| Responsibility | `SuggestionResponsibility::of()` = `Responsibility::ofRecord(subject)->orRoles(kind roles)` (import kinds: roles only); `SuggestionQueue::canDecide()` = kind role **or** `ResponsibilityResolver::isResponsible()` |
| Deadline source | `SuggestionDeadlineSource` (slug/bucket `suggestions`, decision, due `createdAt + 14 days`) — My Day and the digest list open suggestions for the responsible people |
| Texts | `translations/suggestions.{de,en}.yaml`; titles and rationales are rendered in the tenant language when the suggestion is generated (`SuggestionText`) |

## Adding a kind

1. Class in `src/Service/Suggestion/Kind/` extending `AbstractSuggestionKind` (or
   `AbstractAiKind` for a language-model use case).
2. `key()` (stable, e.g. `risk.residual`), `origin()`, `decisionRoles()` (roles from
   `security.yaml`), `requiredModule()`.
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
(AI kinds), the user may decide it (decision role or responsible for the record), no
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
