<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\DocumentControlLink;
use App\Entity\DocumentSection;
use App\Entity\EntityTag;
use App\Entity\PolicyTemplate;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Exception\Tenant\TenantOrphanException;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\PolicyTemplateRepository;
use App\Repository\TagRepository;
use App\Service\TenantSettingResolver\PolicySettingProvider;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Policy-Wizard W3 — real DocumentGenerator.
 *
 * Implements the §8.1-§8.7 spec from
 * `docs/plans/policy-wizard/05-architecture.md`. For every standard
 * + topic in a {@see WizardRun}:
 *   1. Resolve the matching {@see PolicyTemplate}.
 *   2. Collect substitution variables via {@see VariableCollector}
 *      (tenant data + WizardRun.inputs).
 *   3. Render the body via the `policy.<standard>.<topic>.v<n>.body`
 *      translation key.
 *   4. Create or version-bump the {@see Document} (§10 immutability).
 *   5. Link to every Annex A / Baustein / DORA-Article control via
 *      {@see DocumentControlLink} (§8.1).
 *   6. Update the SoA on the matching {@see Control} entity:
 *      max-comparator on implementation_status (NEVER downgrade),
 *      attach evidence-document, snapshot justification (§8.2).
 *   7. Apply tags per §8.5: `policy-wizard-generated`,
 *      `standard:<code>`, `topic:<key>`, `version:<n>`,
 *      `wizard-run:<id>`, plus `dora-validity:2025-01-17` for DORA
 *      templates only.
 *   8. W3-I Task 2: when the template carries `dpoSectionRequired=true`,
 *      auto-create a `privacy_addendum` DocumentSection (status=draft)
 *      so the §0.A privacy-section-gate can fire without a manual seed.
 *
 * Defensive checks:
 *  - W3-I Task 3 (architecture §6 Step 2): ISO 27001 top-level body
 *    renders MUST contain the climate-change wording (Amd. 1:2024).
 *    Missing wording aborts the run with RuntimeException so the
 *    omission surfaces in tests and never ships to production.
 *
 * Atomic transaction: every persist runs inside a single
 * {@see EntityManagerInterface::wrapInTransaction} block. On any
 * exception the entire run rolls back and {@see complete}-style
 * callers see an empty document_ids list (the orchestrator marks the
 * run as `failed`).
 *
 * Sandbox mode (`WizardRun.mode='sandbox'`): renders previews into
 * `WizardRun.inputs['sandbox_preview']` and does NOT persist anything
 * — no Documents, no DocumentControlLinks, no Tags, no SoA mutation.
 *
 * Re-generation (§10): when an approved Document already exists for
 * the (tenant, template, topic) tuple and the substitution-variable
 * hash matches, the existing approved Document is reused as-is. When
 * the hash differs, a NEW Document is created with the old one as
 * `supersedes` source; the old stays approved + immutable (already).
 */
final class DocumentGenerator implements DocumentGeneratorInterface
{
    /**
     * Implementation-status rank used by the §8.2 max-comparator.
     * Higher = more advanced; we only ever bump UP. The wizard's own
     * level is `partial_documented`. Existing tenant labels follow
     * Control entity's enum (`not_started`/`planned`/`in_progress`/
     * `implemented`/`verified`); we coerce via {@see STATUS_RANK}.
     */
    private const STATUS_RANK = [
        'not_started' => 0,
        'not_implemented' => 0,
        'planned' => 1,
        'partial_documented' => 2,
        'in_progress' => 3,
        'implemented' => 4,
        'fully_implemented' => 4,
        'verified' => 5,
    ];

    /**
     * Status the wizard wants to write when bumping (§8.2 — "Policy
     * generation = documented but not yet operating"). Mapped to the
     * Control entity's existing enum so the column constraint holds.
     */
    private const WIZARD_STATUS_LABEL = 'in_progress';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PolicyTemplateRepository $templateRepository,
        private readonly ControlRepository $controlRepository,
        private readonly DocumentControlLinkRepository $documentControlLinkRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly TagRepository $tagRepository,
        private readonly VariableCollector $variableCollector,
        private readonly TranslatorInterface $translator,
        private readonly ?DocumentSectionRepository $documentSectionRepository = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?DoraExtensionCatalogue $doraExtensionCatalogue = null,
        private readonly ?PolicySettingProvider $policySettingProvider = null,
        private readonly ?GdprSectionCatalogue $gdprSectionCatalogue = null,
        private readonly ?SoaAutoUpdateService $soaAutoUpdateService = null,
    ) {
    }

    /**
     * Per-template flag indicating the DORA extension was appended for
     * this run. Drives §8.5 tag emission (`dora-extension:applied`,
     * `dora-validity:2025-01-17` on the underlying ISO Document).
     *
     * @var array<int, bool> keyed on `spl_object_id($template)`
     */
    private array $doraExtensionApplied = [];

    /**
     * @return array{document_ids: list<int>, sandbox_preview: array<string, mixed>|null}
     */
    public function generate(WizardRun $run): array
    {
        $tenant = $run->getTenant();
        if ($tenant === null) {
            throw new TenantOrphanException(null, 'WizardRun must have a tenant.');
        }

        $standards = $run->getStandardsAdopted() ?? [];
        if ($standards === []) {
            $this->logger->info('PolicyWizard generate: no standards adopted', [
                'wizard_run_id' => $run->getId(),
            ]);
            return ['document_ids' => [], 'sandbox_preview' => null];
        }

        $templates = $this->collectTemplatesFor($run);
        if ($templates === []) {
            return ['document_ids' => [], 'sandbox_preview' => null];
        }

        $variables = $this->variableCollector->collectFor($run);
        $isSandbox = $run->getMode() === WizardStepKeys::MODE_SANDBOX;

        if ($isSandbox) {
            return $this->runSandbox($run, $templates, $variables);
        }

        // Atomic transaction wrapping every persistence step (§8 atomic
        // contract). EM rolls back on any exception.
        try {
            /** @var list<int> $documentIds */
            $documentIds = $this->entityManager->wrapInTransaction(
                fn (): array => $this->runPersistent($run, $tenant, $templates, $variables),
            );
        } catch (Throwable $error) {
            $this->logger->error('PolicyWizard DocumentGenerator failed; rolled back', [
                'wizard_run_id' => $run->getId(),
                'error' => $error->getMessage(),
            ]);
            throw $error;
        }

        return ['document_ids' => $documentIds, 'sandbox_preview' => null];
    }

    /**
     * @param list<PolicyTemplate> $templates
     * @return list<int>
     */
    private function runPersistent(
        WizardRun $run,
        Tenant $tenant,
        array $templates,
        array $variables,
    ): array {
        $documentIds = [];
        $tagCache = [];

        // W4-C — Step-0 Bestandsaufnahme decisions. Map every legacy
        // document to a topic-scoped action so we can branch per template
        // (replace = supersedes link; keep = skip topic; merge = append
        // section; split = warn).
        $bestandsaufnahmeByTopic = $this->buildBestandsaufnahmeIndex($run);

        foreach ($templates as $template) {
            $topic = $template->getTopic();
            $bestandsDecision = $topic !== null && isset($bestandsaufnahmeByTopic[$topic])
                ? $bestandsaufnahmeByTopic[$topic]
                : null;

            // W4-C — action='keep': user wants the legacy document to
            // remain authoritative; the wizard does NOT generate a
            // replacement for this topic.
            if ($bestandsDecision !== null && $bestandsDecision['action'] === 'keep') {
                $this->logger->info('PolicyWizard W4-C: skipping template (keep)', [
                    'wizard_run_id' => $run->getId(),
                    'topic' => $topic,
                    'legacy_document_id' => $bestandsDecision['document_id'],
                ]);
                continue;
            }

            $body = $this->renderBody($template, $variables);
            $body = $this->appendDoraExtensionIfApplicable($template, $body, $run);
            $body = $this->appendNis2LexSpecialisFooterIfApplicable($template, $body, $run);

            // Compliance-Manager / Auditor-External Wish — prominent
            // norm-anchor header at the very top of every generated
            // policy body. Lists every linked Annex A control / BSI
            // Baustein / DORA article + the DORA validity stand
            // (when applicable) + a Climate-Change marker (Amd. 1:2024
            // — ISO top-level only). Header is generator-controlled
            // text (no tenant variables) so it is leak-safe by
            // construction.
            $body = $this->prependNormAnkerHeader($template, $body);

            // W1 audit-defang gap #3 — Variable-substitution leakage
            // detector. Pre-persist scan for raw `{{ … }}`, `{% … %}`
            // and `{# … #}` markers. Auditors will not accept generator
            // transparency in published policies (persona-review
            // `06-external-auditor-review.md` lines 180-185), so we
            // abort the run rather than persist a corrupted body.
            $this->assertNoSubstitutionLeaks($run, $template, $body);

            $hash = $this->hashSubstitution($variables, $body);

            $existing = $this->findExistingForTemplate($tenant, $template);
            $document = $this->createOrVersionDocument(
                $run,
                $tenant,
                $template,
                $body,
                $variables,
                $hash,
                $existing,
            );

            // W4-C — action='replace': new wizard output supersedes the
            // legacy Document the user explicitly marked for replacement.
            if ($bestandsDecision !== null && $bestandsDecision['action'] === 'replace') {
                $legacy = $this->documentRepository->find($bestandsDecision['document_id']);
                if ($legacy instanceof Document) {
                    $document->setSupersedes($legacy);
                    $legacy->setIsArchived(true);
                }
            }

            // Persist + flush the Document FIRST so its auto-generated
            // id is available for the EntityTag.entityId / DocumentControlLink
            // foreign keys. Re-used existing rows already have an id.
            if ($document->getId() === null) {
                $this->entityManager->persist($document);
                $this->entityManager->flush();
            }

            // §8.1 — link controls (Annex A, Bausteine, DORA articles).
            $this->linkControls($document, $template, $tenant);

            // §8.2 — SoA update with max-comparator + evidence.
            $this->updateSoa($document, $template, $tenant, $run);

            // §8.5 — tags.
            $this->applyTags($document, $template, $run, $tagCache);

            // W3-I Task 2 — DPO privacy section auto-creation.
            // Idempotent: if a privacy_addendum section already exists
            // for this Document we skip; superseding documents get a
            // fresh section bound to the new row (the old stays linked
            // to the old Document for audit-trail integrity).
            $this->ensurePrivacyAddendumSection($document, $template);

            // W6-C — GDPR section injector. After DORA extension append
            // (body-level) we run the GDPR catalogue (section-level) so
            // ISO host policies grow per-§0-v2 privacy sections when the
            // tenant adopted GDPR scope. Each section carries an
            // `approval_role` per W6-A so the DPO can sign off / reject
            // independently of the host CISO content.
            $this->appendGdprSectionsIfApplicable($document, $run);

            // W4-C — action='merge_into_topic': append the legacy
            // document's content as a `legacy_merge` section.
            if ($bestandsDecision !== null && $bestandsDecision['action'] === 'merge_into_topic') {
                $this->mergeLegacySection($document, $bestandsDecision['document_id'], $tenant);
            }

            // W4-C — action='split_to_topics': not auto-handled; emit a
            // warning so an Alva-Hint can pick up the manual-split TODO.
            if ($bestandsDecision !== null && $bestandsDecision['action'] === 'split_to_topics') {
                $this->logger->warning('PolicyWizard W4-C: split_to_topics requires manual handling', [
                    'wizard_run_id' => $run->getId(),
                    'topic' => $topic,
                    'legacy_document_id' => $bestandsDecision['document_id'],
                    'target_topics' => $bestandsDecision['target_topics'],
                ]);
            }

            $this->entityManager->flush();
            $documentIds[] = (int) $document->getId();
        }

        // W6-C — emit the thin A.5.34 cross-reference host when the
        // tenant adopted both ISO 27001 and GDPR. Per §0 Decision Matrix
        // v2 row 18, ISO "shall maintain a topic-specific policy"
        // demands a document at A.5.34 even when content is satisfied
        // by §2.1 + 5 standalone privacy docs; v2 keeps a thin 1-2 page
        // cross-reference host instead of suppressing A.5.34 entirely.
        $thinHostId = $this->emitThinA534HostIfApplicable($run, $tenant, $tagCache);
        if ($thinHostId !== null) {
            $documentIds[] = $thinHostId;
        }

        return $documentIds;
    }

    /**
     * W4-C — flatten Step-0 decisions into a topic-keyed lookup. Decisions
     * carrying an explicit `target_topic` win; replace/keep decisions fall
     * back to a category-based topic (top_level / continuity / etc.) so
     * the most common case (replace ISMS-Leitlinie → top_level) just works.
     *
     * @return array<string, array{action: string, document_id: int, target_topics: list<string>|null}>
     */
    private function buildBestandsaufnahmeIndex(WizardRun $run): array
    {
        $inputs = $run->getInputs() ?? [];
        $slot = $inputs['bestandsaufnahme'] ?? [];
        if (!is_array($slot)) {
            return [];
        }
        $decisions = $slot['decisions'] ?? [];
        if (!is_array($decisions) || $decisions === []) {
            return [];
        }

        $byTopic = [];
        foreach ($decisions as $documentIdRaw => $payload) {
            if (!is_array($payload)) {
                continue;
            }
            $documentId = (int) $documentIdRaw;
            $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';
            if ($action === '' || $documentId <= 0) {
                continue;
            }
            $targetTopic = is_string($payload['target_topic'] ?? null) && $payload['target_topic'] !== ''
                ? $payload['target_topic']
                : null;
            $targetTopics = is_array($payload['target_topics'] ?? null) ? $payload['target_topics'] : null;

            if ($targetTopic !== null) {
                $byTopic[$targetTopic] = [
                    'action' => $action,
                    'document_id' => $documentId,
                    'target_topics' => $targetTopics,
                ];
                continue;
            }
            $legacy = $this->documentRepository->find($documentId);
            if (!$legacy instanceof Document) {
                continue;
            }
            $fallbackTopic = match ($legacy->getCategory()) {
                'policy', 'programme' => 'top_level',
                'plan' => 'continuity',
                'methodology' => 'risk_classification',
                default => null,
            };
            if ($fallbackTopic !== null) {
                $byTopic[$fallbackTopic] = [
                    'action' => $action,
                    'document_id' => $documentId,
                    'target_topics' => $targetTopics,
                ];
            }
        }

        return $byTopic;
    }

    /**
     * W4-C — append the legacy document's content as a `legacy_merge`
     * DocumentSection on the new wizard output. Idempotent on re-runs.
     */
    private function mergeLegacySection(Document $document, int $legacyDocumentId, Tenant $tenant): void
    {
        if ($this->documentSectionRepository === null) {
            return;
        }
        $existing = $this->documentSectionRepository->findOneByDocumentAndKey($document, 'legacy_merge');
        if ($existing instanceof DocumentSection) {
            return;
        }
        $legacy = $this->documentRepository->find($legacyDocumentId);
        if (!$legacy instanceof Document) {
            return;
        }

        $snapshot = sprintf(
            "Merged from legacy document #%d (%s).\n\n%s",
            $legacyDocumentId,
            (string) ($legacy->getOriginalFilename() ?? 'unknown'),
            (string) ($legacy->getDescription() ?? ''),
        );

        $section = new DocumentSection();
        $section->setDocument($document);
        $section->setSectionKey('legacy_merge');
        $section->setStatus(DocumentSection::STATUS_DRAFT);
        $section->setTenant($tenant);
        $section->setContentSnapshot($snapshot);
        $this->entityManager->persist($section);
    }

    /**
     * @param list<PolicyTemplate> $templates
     * @return array{document_ids: list<int>, sandbox_preview: array<string, mixed>|null}
     */
    private function runSandbox(WizardRun $run, array $templates, array $variables): array
    {
        $previews = [];
        foreach ($templates as $template) {
            $body = $this->renderBody($template, $variables);
            $body = $this->appendDoraExtensionIfApplicable($template, $body, $run);
            $body = $this->appendNis2LexSpecialisFooterIfApplicable($template, $body, $run);
            $body = $this->prependNormAnkerHeader($template, $body);
            $previews[] = [
                'template_key' => $template->getKey(),
                'standard' => $template->getStandard(),
                'topic' => $template->getTopic(),
                'title' => $this->resolvePolicyTranslation($template->getTitleTranslationKey() ?? '', $template->getStandard()),
                'body' => $body,
                'document_type' => $template->getDocumentType(),
            ];
        }

        // Persist preview snapshot into WizardRun.inputs so the result
        // page can render the would-be docs (§6.4 sandbox flow).
        $inputs = $run->getInputs() ?? [];
        $inputs['sandbox_preview'] = $previews;
        $run->setInputs($inputs);

        return [
            'document_ids' => [],
            'sandbox_preview' => ['policies' => $previews],
        ];
    }

    /**
     * Topic + standard resolution. Honours Mode 2 (targeted re-run)
     * by intersecting `WizardRun.targetedTopics` against the catalogue.
     *
     * W5-A — also applies the `bsi.tier_filter` tenant-setting:
     *   • basis_only      → drop every template with bsi_tier in {standard, kern}
     *   • up_to_standard  → drop every template with bsi_tier=kern
     *   • kern_full       → no drops
     * Templates with bsi_tier=NULL (non-BSI standards) are always kept.
     *
     * @return list<PolicyTemplate>
     */
    private function collectTemplatesFor(WizardRun $run): array
    {
        $standards = $run->getStandardsAdopted() ?? [];
        $targeted = $run->getTargetedTopics();

        $tierFilter = $this->resolveBsiTierFilter($run);

        $hits = [];
        foreach ($standards as $standard) {
            if (!is_string($standard) || $standard === '') {
                continue;
            }
            $rows = $this->templateRepository->findActiveByStandard($standard);
            foreach ($rows as $row) {
                if ($targeted !== null && $targeted !== [] && !in_array($row->getTopic(), $targeted, true)) {
                    continue;
                }
                if (!$this->isTemplateAllowedUnderTierFilter($row, $tierFilter)) {
                    $this->logger->info('PolicyWizard W5-A: skipping template due to bsi.tier_filter', [
                        'wizard_run_id' => $run->getId(),
                        'template_key' => $row->getKey(),
                        'bsi_tier' => $row->getBsiTier(),
                        'filter' => $tierFilter,
                    ]);
                    continue;
                }
                $hits[] = $row;
            }
        }
        return $hits;
    }

    /**
     * Resolve the tenant's `bsi.tier_filter` value. Defensive: returns
     * the safe `basis_only` default whenever the provider is missing
     * (legacy DI graphs / unit tests) or the resolution fails.
     */
    private function resolveBsiTierFilter(WizardRun $run): string
    {
        if ($this->policySettingProvider === null) {
            return PolicySettingProvider::TIER_FILTER_DEFAULT;
        }
        return $this->policySettingProvider->resolveBsiTierFilter($run->getTenant());
    }

    /**
     * Whether a template's `bsi_tier` is allowed under the resolved
     * filter. NULL-tiered templates ship in every mode.
     */
    private function isTemplateAllowedUnderTierFilter(PolicyTemplate $template, string $tierFilter): bool
    {
        if ($this->policySettingProvider === null) {
            // Conservative: when the provider is not wired we keep the
            // legacy behaviour (everything ships) rather than silently
            // dropping rows downstream.
            return true;
        }
        return $this->policySettingProvider->tierAllowedUnderFilter(
            $template->getBsiTier(),
            $tierFilter,
        );
    }

    /**
     * §11.2: variable substitution markers HIDDEN by default. We
     * replace `{{ name }}` with `$variables['name']` if present, else
     * empty string. The substitution-manifest survives in
     * `Document.substitutionVariables` so audit trail is preserved.
     */
    private function renderBody(PolicyTemplate $template, array $variables): string
    {
        $bodyKey = $template->getBodyTranslationKey() ?? '';
        if ($bodyKey === '') {
            return $this->renderUnauthoredPlaceholder($template, 'no_body_translation_key');
        }
        $rawBody = $this->resolvePolicyTranslation($bodyKey, $template->getStandard());

        // Translation-key fall-through guard — when no domain has the
        // body authored, the resolver returns the key string itself
        // ("policy.bsi.logging_policy.v1.body"). Persisting that as
        // the policy body produces useless 30-character "documents"
        // shown to auditors. Replace with an explicit, audit-visible
        // placeholder that lists the norm-anker references and a
        // loud "Inhalt nicht verfasst" warning so reviewers know the
        // template still needs authoring.
        if ($rawBody === $bodyKey) {
            return $this->renderUnauthoredPlaceholder($template, 'translation_missing:' . $bodyKey);
        }

        $body = $this->substitute($rawBody, $variables);

        // W3-I Task 3 — defensive ISO 27001 climate-change wording check.
        // Architecture §6 Step 2 hardcodes climate-wording ON for ISO
        // (Amd. 1:2024 in force since Feb 2024). If the rendered body
        // is missing the phrase we throw early so the omission is loud
        // in tests / pre-prod and does not silently ship to auditors.
        $this->assertClimateWordingPresent($template, $body);

        return $body;
    }

    /**
     * Build an explicit placeholder body for templates whose translation
     * was never authored. Lists the topic, version, norm-anker references
     * and a loud warning so the resulting Document is recognisable as
     * "skeleton awaiting content authoring" instead of a stub-string.
     */
    private function renderUnauthoredPlaceholder(PolicyTemplate $template, string $reason): string
    {
        $topic = $template->getTopic() ?? 'unknown';
        $standard = $template->getStandard() ?? 'unknown';
        $version = $template->getVersion();

        $annexA = (array) ($template->getLinkedAnnexAControls() ?? []);
        $bsi = (array) ($template->getLinkedBausteine() ?? []);
        $dora = (array) ($template->getLinkedDoraArticles() ?? []);

        $body = "# {$topic} (Vorlage v{$version})\n\n";
        $body .= "> **Inhalt noch nicht verfasst.** Diese Vorlage ist im PolicyTemplate-Register "
            . "geseedet, aber die Body-Translation `" . ($template->getBodyTranslationKey() ?? '∅')
            . "` wurde noch nicht in den `policy_{$standard}_*.{de,en}.yaml` Domains autorisiert. "
            . "Bitte ISB / Compliance-Manager konsultieren, bevor diese Richtlinie auditrelevant verwendet wird.\n\n";
        $body .= "## Norm-Anker (aus Template-Definition)\n\n";
        if ($annexA !== []) {
            $body .= "- ISO 27001:2022 Annex A: " . implode(', ', $annexA) . "\n";
        }
        if ($bsi !== []) {
            $body .= "- BSI 200-2 Bausteine: " . implode(', ', $bsi) . "\n";
        }
        if ($dora !== []) {
            $body .= "- DORA Artikel: " . implode(', ', $dora) . "\n";
        }
        if ($annexA === [] && $bsi === [] && $dora === []) {
            $body .= "_(Keine Norm-Anker im Template hinterlegt.)_\n";
        }
        $body .= "\n## Pflicht-Inhalte (Junior-ISB-Checkliste)\n\n";
        $body .= "1. Zweck und Geltungsbereich der Richtlinie\n";
        $body .= "2. Verantwortlichkeiten (RACI)\n";
        $body .= "3. Verbindliche Regelungen / Kontrollen\n";
        $body .= "4. Mess- und Wirksamkeitskriterien\n";
        $body .= "5. Eskalations- und Ausnahmeverfahren\n";
        $body .= "6. Pruef- und Aenderungs-Zyklus\n";
        $body .= "\n_Diagnostik: reason=`{$reason}`_\n";

        // Surface in audit log so authoring gap is tracked centrally.
        $this->logger->warning('PolicyWizard: rendering unauthored-placeholder body', [
            'standard' => $standard,
            'topic' => $topic,
            'body_translation_key' => $template->getBodyTranslationKey(),
            'reason' => $reason,
        ]);

        return $body;
    }

    /**
     * Public backfill helper for legacy wizard-generated docs that
     * predate the `policy_body` column (added in migration
     * `20260510010000_document_policy_body`). Re-renders the body
     * from the persisted PolicyTemplate + substitutionVariables and
     * returns it without touching the Document. Caller persists.
     *
     * Returns null when the doc cannot be backfilled (no template,
     * empty body-key, or rendered body comes back empty).
     */
    public function renderBodyForBackfill(Document $doc): ?string
    {
        $template = $doc->getGeneratedFromTemplate();
        if ($template === null) {
            return null;
        }
        $vars = $doc->getSubstitutionVariables() ?? [];
        try {
            $body = $this->renderBody($template, $vars);
        } catch (\Throwable) {
            return null;
        }
        return $body !== '' ? $body : null;
    }

    /**
     * Per-standard candidate-domain map used by `resolvePolicyTranslation`
     * — mirrors `App\Twig\PolicyTranslationExtension::DOMAINS_BY_STANDARD`.
     * Translation keys like `policy.bcm.bcms_top_level.v1.title` live in
     * domain `policy_bcm_batch1`, not `messages` — a bare trans() call
     * returns the raw key. Walk the candidate domains for the standard
     * and return the first match.
     *
     * @var array<string, list<string>>
     */
    private const POLICY_DOMAINS_BY_STANDARD = [
        'gdpr'      => ['policy_privacy_batch1', 'policy_privacy_sections'],
        'iso27001'  => ['policy_iso27001', 'policy_iso27001_batch2', 'policy_iso27001_batch3', 'policy_iso27001_batch4', 'policy_iso27001_batch5'],
        'iso27701'  => ['policy_iso27701'],
        'bsi'       => ['policy_bsi_batch1', 'policy_bsi_batch2', 'policy_bsi_batch3', 'policy_bsi_batch4', 'policy_bsi_batch5'],
        'bcm'       => ['policy_bcm_batch1', 'policy_bcm_batch2'],
        'dora'      => ['policy_dora'],
        'soc2'      => ['policy_soc2'],
        'tisax'     => ['policy_tisax'],
        'nis2'      => ['policy_nis2'],
        'c5'        => ['policy_c5'],
        'kritis'    => ['policy_kritis'],
    ];

    private function resolvePolicyTranslation(string $key, ?string $standard): string
    {
        if ($key === '') {
            return '';
        }
        $candidates = self::POLICY_DOMAINS_BY_STANDARD[$standard] ?? [];
        foreach ($candidates as $domain) {
            $resolved = $this->translator->trans($key, [], $domain);
            if ($resolved !== $key) {
                return $resolved;
            }
        }
        // Fallback: try messages domain (legacy) so behaviour stays the
        // same when no candidate matches.
        return $this->translator->trans($key);
    }

    /**
     * W4-A Task 2 — append the DORA-Erweiterung section to ISO 27001
     * topic policies when the tenant adopted DORA scope.
     *
     * Per architecture §3 the DORA addon contributes 18 EXTENDS
     * mappings + 1 REPLACES (network_security) on top of the ISO
     * baseline (see {@see DoraExtensionCatalogue}). For every ISO
     * topic with a DORA extension defined the rendered body grows a
     * `## DORA-Erweiterung (Art. X)` section, translated via the
     * `policy.iso27001.<topic>.v1.dora_extension.body` key (authored
     * in W4-E). DORA-only templates (`standard='dora'`) are NOT
     * extended — those are emitted as standalone Documents by the
     * 6 NEW seed and the catalogue does not target them.
     *
     * Skip rules:
     *  - catalogue not wired (legacy tests / dev environments).
     *  - Run.standardsAdopted does NOT include 'dora'.
     *  - Template standard is not 'iso27001'.
     *  - Catalogue has no entry for the template's topic.
     *
     * Side effects:
     *  - Marks {@see $doraExtensionApplied} so {@see applyTags} can
     *    emit `dora-extension:applied` + `dora-validity:2025-01-17`
     *    on the underlying ISO Document.
     */
    public function appendDoraExtensionIfApplicable(
        PolicyTemplate $template,
        string $body,
        WizardRun $run,
    ): string {
        if ($this->doraExtensionCatalogue === null) {
            return $body;
        }
        if ($template->getStandard() !== 'iso27001') {
            return $body;
        }
        $standards = $run->getStandardsAdopted() ?? [];
        if (!in_array('dora', $standards, true)) {
            return $body;
        }
        $topic = $template->getTopic();
        if (!is_string($topic) || $topic === '') {
            return $body;
        }
        $articles = $this->doraExtensionCatalogue->getExtensionFor($topic);
        if ($articles === null || $articles === []) {
            return $body;
        }

        $extensionKey = sprintf(
            'policy.iso27001.%s.v%d.dora_extension.body',
            $topic,
            $template->getVersion(),
        );
        $extensionBody = $this->resolvePolicyTranslation($extensionKey, 'iso27001');
        // Translator returns the key verbatim when no translation is
        // registered; in that case we still mark the extension applied
        // so the audit-trail tag fires, but emit a stub heading rather
        // than the raw key (keeps generated docs auditor-presentable).
        if ($extensionBody === $extensionKey) {
            $extensionBody = '_(translation pending: ' . $extensionKey . ')_';
        }

        $heading = sprintf(
            '## DORA-Erweiterung (%s)',
            implode(' / ', $articles),
        );

        // Mark for tag emission downstream.
        $this->doraExtensionApplied[spl_object_id($template)] = true;

        return rtrim($body) . "\n\n" . $heading . "\n\n" . $extensionBody . "\n\n";
    }

    /**
     * Junior-ISB Wish #2 — append the NIS-2 / DORA lex specialis footer
     * to DORA-standard bodies.
     *
     * DORA Art. 1(2) + NIS-2 Erwägungsgrund 16 establish DORA as the
     * lex specialis for the financial sector. Where DORA fully covers
     * NIS-2 Art. 21 (risk management) + Art. 23 (incident reporting),
     * DORA-compliant policies satisfy the corresponding NIS-2 duties.
     * Auditors expect the footer in every DORA policy so the cross-
     * regulation evidence chain is explicit.
     *
     * Skip rules:
     *  - Run.standardsAdopted does NOT include 'dora'.
     *  - Template.standard !== 'dora' (ISO/BSI/etc. carry their own
     *    DORA section via {@see appendDoraExtensionIfApplicable}; we
     *    only footer the standalone DORA documents here).
     */
    public function appendNis2LexSpecialisFooterIfApplicable(
        PolicyTemplate $template,
        string $body,
        WizardRun $run,
    ): string {
        if ($template->getStandard() !== 'dora') {
            return $body;
        }
        $standards = $run->getStandardsAdopted() ?? [];
        if (!in_array('dora', $standards, true)) {
            return $body;
        }

        $footerKey = 'policy_wizard.step.welcome.dora_nis2_lex_specialis.footer_in_doc';
        $footer = $this->translator->trans($footerKey, [], 'policy_wizard');
        if ($footer === $footerKey) {
            // Translation missing — render a stub so the audit-trail
            // still shows the intent without leaking the translation key.
            $footer = 'This DORA policy satisfies NIS-2 Art. 21 + Art. 23 via DORA Art. 6 + Art. 17-23 (lex specialis per DORA Art. 1(2) and NIS-2 recital 16).';
        }

        return rtrim($body) . "\n\n---\n\n" . $footer . "\n";
    }

    /**
     * Compliance-Manager / Auditor-External Wish — render a prominent
     * "Norm-Anker" header at the very top of every generated policy
     * body. The header makes the regulatory linkage explicit so
     * auditors do not have to expand the EntityTag list to verify
     * which Annex A controls / BSI Bausteine / DORA articles the
     * document covers.
     *
     * Format (Markdown):
     *   ```
     *   > **Norm-Anker:** ISO 27001:2022 A.5.23, A.8.13 | BSI 200-2 OPS.1.2.4 | DORA Art. 6, 9
     *   > _DORA-Stand: 2025-01-17 · Climate-Change Amd. 1:2024 angewandt_
     *   ```
     *
     * Skip rules:
     *  - Templates with no linked refs at all → no header (nothing to
     *    anchor against; injecting an empty header would be noise).
     *
     * The header text is fully generator-controlled — no tenant
     * substitution variables — so it is leak-safe. ISO climate-wording
     * remains in the body proper (the assertion already fired in
     * {@see renderBody}); the header just surfaces a Climate-Change
     * marker line for visual prominence.
     */
    public function prependNormAnkerHeader(PolicyTemplate $template, string $body): string
    {
        $segments = [];

        $annexA = array_values(array_filter(
            $template->getLinkedAnnexAControls() ?? [],
            static fn ($ref): bool => is_string($ref) && $ref !== '',
        ));
        if ($annexA !== []) {
            $segments[] = 'ISO 27001:2022 ' . implode(', ', $annexA);
        }

        $bausteine = array_values(array_filter(
            $template->getLinkedBausteine() ?? [],
            static fn ($ref): bool => is_string($ref) && $ref !== '',
        ));
        if ($bausteine !== []) {
            $segments[] = 'BSI 200-2 ' . implode(', ', $bausteine);
        }

        $doraArticles = array_values(array_filter(
            $template->getLinkedDoraArticles() ?? [],
            static fn ($ref): bool => is_string($ref) && $ref !== '',
        ));
        if ($doraArticles !== []) {
            $segments[] = 'DORA ' . implode(', ', $doraArticles);
        }

        // No linkage → suppress the header entirely. Empty headers add
        // visual noise without auditor value.
        if ($segments === []) {
            return $body;
        }

        $header = '> **Norm-Anker:** ' . implode(' | ', $segments);

        $supplements = [];
        // DORA-Stand line — emit when the template itself is DORA OR
        // when the DORA extension was appended to an ISO body.
        $isDoraTemplate = $template->getStandard() === 'dora';
        $isDoraExtension = ($this->doraExtensionApplied[spl_object_id($template)] ?? false) === true;
        if ($isDoraTemplate || $isDoraExtension) {
            $supplements[] = 'DORA-Stand: 2025-01-17';
        }

        // Climate-Change line — only on ISO 27001 top-level renders that
        // carry the Amd. 1:2024 wording (mirrors the existing assertion
        // in renderBody).
        if (
            $template->getStandard() === 'iso27001'
            && $template->getTopic() === 'top_level'
            && $template->isClimateChangeWording()
        ) {
            $supplements[] = 'Climate-Change Amd. 1:2024 angewandt';
        }

        if ($supplements !== []) {
            $header .= "\n> _" . implode(' · ', $supplements) . '_';
        }

        return $header . "\n\n" . ltrim($body);
    }

    /**
     * W3-I Task 3 — climate-change wording assertion for ISO 27001
     * top-level Information Security Policy renders.
     *
     * Skipped (silently) for:
     *  - non-iso27001 standards (BSI / DORA / BCM / etc.)
     *  - non-top-level topics (climate is a Cl. 5.2 top-level concern)
     *  - templates with `climateChangeWording=false` (template still
     *    has the hard-coded ON default but tests can opt-out)
     *  - emergency override via env `ISO_27001_CLIMATE_ASSERT_DISABLED=1`
     *
     * Phrase rules (per §6 Step 2):
     *  - DE: at least one literal "Klimawandel" occurrence
     *  - EN: at least one case-insensitive "climate change" occurrence
     */
    private function assertClimateWordingPresent(PolicyTemplate $template, string $body): void
    {
        if ($template->getStandard() !== 'iso27001') {
            return;
        }
        if ($template->getTopic() !== 'top_level') {
            return;
        }
        if (!$template->isClimateChangeWording()) {
            return;
        }
        if (($_ENV['ISO_27001_CLIMATE_ASSERT_DISABLED'] ?? '') === '1') {
            return;
        }

        $hasDe = str_contains($body, 'Klimawandel');
        $hasEn = stripos($body, 'climate change') !== false;
        if ($hasDe || $hasEn) {
            return;
        }

        throw new RuntimeException(
            'iso27001 body must contain climate-change wording per Amd. 1:2024 '
            . '(template "' . ($template->getKey() ?? 'unknown') . '")',
        );
    }

    /**
     * W1 audit-defang gap #3 — wrap the static {@see SubstitutionLeakageDetector}
     * call so the detector failure surfaces as a structured audit-log
     * entry before the exception bubbles out of {@see runPersistent}.
     * The transactional rollback in {@see generate} ensures NOTHING
     * persists when this fires, but the audit trail of "we noticed and
     * refused to ship a leaky body" is itself the auditor-defensible
     * artefact.
     *
     * @throws SubstitutionLeakageException re-thrown after logging.
     */
    private function assertNoSubstitutionLeaks(
        WizardRun $run,
        PolicyTemplate $template,
        string $body,
    ): void {
        try {
            SubstitutionLeakageDetector::assertNoLeaks($body);
        } catch (SubstitutionLeakageException $leak) {
            $this->logger->critical(
                'PolicyWizard W1 defang: substitution leakage detected; aborting generation',
                [
                    'wizard_run_id' => $run->getId(),
                    'template_key'  => $template->getKey(),
                    'standard'      => $template->getStandard(),
                    'topic'         => $template->getTopic(),
                    'leak_count'    => count($leak->leaks),
                    'first_leak'    => $leak->leaks[0] ?? null,
                ],
            );
            throw $leak;
        }
    }

    /**
     * Walk `{{ varName }}` markers — case-insensitive whitespace.
     */
    private function substitute(string $body, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/u',
            static function (array $match) use ($variables): string {
                $key = $match[1];
                if (!array_key_exists($key, $variables)) {
                    return '';
                }
                $value = $variables[$key];
                if ($value === null) {
                    return '';
                }
                return (string) $value;
            },
            $body,
        );
    }

    /**
     * Stable hash of the substitution map + rendered body. Drives
     * §10 re-generation detection.
     */
    private function hashSubstitution(array $variables, string $body): string
    {
        $sorted = $variables;
        ksort($sorted);
        $payload = json_encode([
            'variables' => $sorted,
            'body' => $body,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return hash('sha256', $payload);
    }

    private function findExistingForTemplate(Tenant $tenant, PolicyTemplate $template): ?Document
    {
        // Look for an active (non-archived) Document tied to this
        // template + tenant. The DocumentRepository default
        // `findBy(['tenant' => …])` is too broad; we use plain
        // criteria here because the link is via `generatedFromTemplate`.
        return $this->documentRepository->findOneBy([
            'tenant' => $tenant,
            'generatedFromTemplate' => $template,
            'isArchived' => false,
        ]);
    }

    /**
     * §10 re-generation rule:
     *   - existing approved + same hash  → reuse, no new row.
     *   - existing approved + new hash   → create NEW Document with
     *                                       supersedes link to the old.
     *   - existing draft (not approved)  → replace its content (bump).
     *   - no existing                    → create fresh draft.
     */
    private function createOrVersionDocument(
        WizardRun $run,
        Tenant $tenant,
        PolicyTemplate $template,
        string $body,
        array $variables,
        string $hash,
        ?Document $existing,
    ): Document {
        $title = $this->resolvePolicyTranslation($template->getTitleTranslationKey() ?? '', $template->getStandard());

        if ($existing !== null) {
            $existingHash = $this->hashOf($existing);
            $isApproved = $existing->getStatus() === 'approved' || $existing->isImmutable();

            if ($isApproved && $existingHash === $hash) {
                // Identical content → reuse.
                return $existing;
            }

            if ($isApproved && $existingHash !== $hash) {
                // Create new version, supersedes the old. The
                // post-generation editable-bodies feature: if the
                // superseded doc carries tenant-specific edits
                // (`hasPostGenerationEdits()`), the edited body is
                // PRESERVED on the existing doc — the new wizard
                // baseline forks via `supersedes` so the W7-C diff
                // surfaces the conflict for manual reconciliation
                // (drift indicator + audit-trail entry).
                $next = $this->makeFreshDocument($run, $tenant, $template, $title, $body, $variables);
                $next->setSupersedes($existing);
                if ($existing->hasPostGenerationEdits()) {
                    $this->logger->info('PolicyWizard: re-generation conflict — preserving edited policy body', [
                        'wizard_run_id' => $run->getId(),
                        'tenant_id' => $tenant->getId(),
                        'existing_document_id' => $existing->getId(),
                        'existing_edited_at' => $existing->getPolicyBodyEditedAt()?->format(DATE_ATOM),
                        'existing_edited_by_id' => $existing->getPolicyBodyEditedBy()?->getId(),
                        'template_key' => $template->getKey(),
                    ]);
                }
                return $next;
            }

            // Draft path — overwrite in place. Only overwrite
            // `policyBody` when no post-generation edits exist; the
            // wizard never silently destroys tenant work.
            $existing->setDescription($this->firstParagraph($body));
            if (!$existing->hasPostGenerationEdits()) {
                $existing->setPolicyBody($body);
            } else {
                $this->logger->info('PolicyWizard: draft re-render — preserving edited policy body', [
                    'wizard_run_id' => $run->getId(),
                    'tenant_id' => $tenant->getId(),
                    'existing_document_id' => $existing->getId(),
                    'template_key' => $template->getKey(),
                ]);
            }
            $existing->setSubstitutionVariables(array_merge($variables, ['_hash' => $hash]));
            $existing->setGeneratedFromWizardRun($run);
            $existing->setUpdatedAt(new DateTimeImmutable());
            return $existing;
        }

        return $this->makeFreshDocument($run, $tenant, $template, $title, $body, $variables);
    }

    private function makeFreshDocument(
        WizardRun $run,
        Tenant $tenant,
        PolicyTemplate $template,
        string $title,
        string $body,
        array $variables,
    ): Document {
        $now = new DateTimeImmutable();
        $hash = $this->hashSubstitution($variables, $body);

        // Keep a synthetic filename / mime so the Document NOT-NULL
        // columns stay populated. Generated-policies live as content
        // text; the Document.filePath stays virtual.
        $key = $template->getKey() ?? 'policy';
        $version = $template->getVersion();
        $filename = sprintf('policy-%s-v%d-run%d.md', $key, $version, $run->getId() ?? 0);

        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setFilename($filename);
        $doc->setOriginalFilename($filename);
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(strlen($body));
        $doc->setFilePath('virtual:policy-wizard/' . $filename);
        $doc->setCategory($template->getDocumentType() ?? 'policy');
        $doc->setDescription($this->firstParagraph($body));
        // Persist the full rendered body so post-generation tenant
        // customisation has a baseline to start from and the PDF
        // exporter does not have to re-render from translation on
        // every export. `policyBodyEditedAt` stays NULL until a user
        // manually edits the body — the wizard-baseline state.
        $doc->setPolicyBody($body);
        $doc->setStatus('draft'); // @phpstan-ignore lifecycle.directSetStatus (initial state on new Document generated by PolicyWizard; 'draft' is the document_lifecycle initial_marking)
        $doc->setUploadedAt($now);
        $doc->setUploadedBy($run->getStartedByUser());
        $doc->setSha256Hash($hash);
        $doc->setEntityType('PolicyTemplate');
        $doc->setEntityId($template->getId());
        $doc->setGeneratedFromTemplate($template);
        $doc->setGeneratedFromWizardRun($run);
        $doc->setSubstitutionVariables(array_merge($variables, [
            '_hash' => $hash,
            '_template_version' => $version,
            '_title' => $title,
        ]));
        $doc->setIsImmutable(false);
        return $doc;
    }

    private function hashOf(Document $document): ?string
    {
        $vars = $document->getSubstitutionVariables();
        if (is_array($vars) && isset($vars['_hash']) && is_string($vars['_hash'])) {
            return $vars['_hash'];
        }
        return $document->getSha256Hash();
    }

    private function firstParagraph(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        $parts = preg_split('/\R{2,}/u', $body, 2);
        return is_array($parts) && $parts !== [] ? trim($parts[0]) : $body;
    }

    /**
     * §8.1 — link controls. Annex A → controlId match against the
     * tenant's Control catalogue. BSI Bausteine + DORA Articles use
     * the same Control table (single canonical SoA in this codebase),
     * looked up via `controlId`. If no Control row exists we silently
     * skip — wizards can run before a tenant has loaded the catalogue.
     */
    private function linkControls(Document $document, PolicyTemplate $template, Tenant $tenant): void
    {
        $refs = array_merge(
            $template->getLinkedAnnexAControls() ?? [],
            $template->getLinkedBausteine() ?? [],
            $template->getLinkedDoraArticles() ?? [],
        );

        foreach ($refs as $ref) {
            if (!is_string($ref) || $ref === '') {
                continue;
            }
            $control = $this->resolveControl($tenant, $ref);
            if ($control === null) {
                continue;
            }
            $existingLink = $this->documentControlLinkRepository->findOneByDocumentAndControl(
                $document,
                $control,
            );
            if ($existingLink !== null) {
                continue;
            }
            $link = new DocumentControlLink(
                $document,
                $control,
                DocumentControlLink::SOURCE_POLICY_WIZARD,
                DocumentControlLink::EVIDENCE_POLICY,
            );
            $this->entityManager->persist($link);
        }
    }

    private function resolveControl(Tenant $tenant, string $ref): ?Control
    {
        // Strip leading "A." for ISO references (templates use "A.5.15",
        // catalogue stores "5.15"). DORA "Art. 9.4" stays as is.
        $candidates = [$ref];
        if (str_starts_with($ref, 'A.')) {
            $candidates[] = substr($ref, 2);
        }
        foreach ($candidates as $candidate) {
            $hit = $this->controlRepository->findOneBy([
                'tenant' => $tenant,
                'controlId' => $candidate,
            ]);
            if ($hit instanceof Control) {
                return $hit;
            }
        }
        return null;
    }

    /**
     * §8.2 — SoA update. The Control entity *is* the SoA row in this
     * codebase (it carries `applicable`, `implementationStatus`,
     * `justification`, `evidenceDocuments`). We:
     *   - flip `applicable=true` if not already (policy presence implies in-scope)
     *   - bump `implementationStatus` to `in_progress` ONLY if currently lower
     *   - attach `document` to `evidenceDocuments` ManyToMany
     *   - write a justification snapshot describing the wizard run
     */
    private function updateSoa(Document $document, PolicyTemplate $template, Tenant $tenant, WizardRun $run): void
    {
        $refs = array_merge(
            $template->getLinkedAnnexAControls() ?? [],
            $template->getLinkedBausteine() ?? [],
            $template->getLinkedDoraArticles() ?? [],
        );

        // User-mandate (2026-05-08): delegate the implementation-status
        // bump + audit-trail emission to {@see SoaAutoUpdateService} so
        // a `policy_wizard.soa_auto_updated` event lands in the trail
        // for every actually-changed row + a `soa_self_assessment` event
        // is added on single-user tenants. The service mirrors the same
        // STATUS_RANK matrix as this class, so semantics stay identical.
        // Falls back to the inline max-comparator when the service is
        // not wired (legacy unit tests instantiate DocumentGenerator
        // without it).
        if ($this->soaAutoUpdateService !== null) {
            $this->soaAutoUpdateService->propagateForDocument($document, $run);
        }

        $wizardRank = self::STATUS_RANK[self::WIZARD_STATUS_LABEL];

        foreach ($refs as $ref) {
            if (!is_string($ref) || $ref === '') {
                continue;
            }
            $control = $this->resolveControl($tenant, $ref);
            if ($control === null) {
                continue;
            }

            // Applicability — never downgrade from `false` to `false`,
            // but the guarantee here is: policy implies applicable.
            if ($control->isApplicable() !== true) {
                $control->setApplicable(true);
            }

            // Implementation-status max-comparator (NEVER downgrade).
            // Kept inline as belt-and-suspenders fallback when
            // SoaAutoUpdateService is not wired; idempotent when the
            // service already bumped the row (same target + comparator).
            $current = $control->getImplementationStatus() ?? 'not_started';
            $currentRank = self::STATUS_RANK[$current] ?? 0;
            if ($currentRank < $wizardRank) {
                $control->setImplementationStatus(self::WIZARD_STATUS_LABEL);
            }

            // Evidence link.
            $control->addEvidenceDocument($document);

            // Justification snapshot — only set when empty so we never
            // clobber tenant-authored text. Re-runs append per §8.2.
            $existingJustification = $control->getJustification();
            $stamp = sprintf(
                'Policy "%s" generated by Policy-Wizard run #%d on %s',
                $document->getOriginalFilename() ?? 'policy',
                $run->getId() ?? 0,
                (new DateTimeImmutable())->format('Y-m-d'),
            );
            if ($existingJustification === null || $existingJustification === '') {
                $control->setJustification($stamp);
            }

            $this->entityManager->persist($control);
        }
    }

    /**
     * §8.5 — six tag families. We persist {@see EntityTag} rows
     * pointing at the Document.
     *
     * @param array<string, Tag> $tagCache
     */
    private function applyTags(Document $document, PolicyTemplate $template, WizardRun $run, array &$tagCache): void
    {
        $tenant = $run->getTenant();
        $tagsToApply = [
            'policy-wizard-generated',
            'standard:' . ($template->getStandard() ?? 'unknown'),
            'topic:' . ($template->getTopic() ?? 'unknown'),
            'version:' . $template->getVersion(),
            'wizard-run:' . ($run->getId() ?? 0),
        ];

        if (($template->getStandard()) === 'dora') {
            $tagsToApply[] = 'dora-validity:2025-01-17';
        }

        // W4-A Task 2 — when the DORA extension has been appended to
        // an ISO body, mirror the DORA validity tag on the underlying
        // ISO Document plus an explicit `dora-extension:applied`
        // marker so the audit-export view can pivot on it.
        if (($this->doraExtensionApplied[spl_object_id($template)] ?? false) === true) {
            $tagsToApply[] = 'dora-extension:applied';
            if (!in_array('dora-validity:2025-01-17', $tagsToApply, true)) {
                $tagsToApply[] = 'dora-validity:2025-01-17';
            }
        }

        // Compliance-Manager / Auditor-External Wish — `climate-change:amended`
        // marker on top-level ISO 27001 Information-Security-Policy
        // renders. Mirrors the in-body Amd. 1:2024 wording assertion
        // and drives the prominent header chip on the document show
        // view + PDF cover. Skipped for non-iso27001 / non-top_level
        // templates where the wording is not applicable.
        if (
            $template->getStandard() === 'iso27001'
            && $template->getTopic() === 'top_level'
            && $template->isClimateChangeWording()
        ) {
            $tagsToApply[] = 'climate-change:amended';
        }

        $documentId = $document->getId();
        if ($documentId === null) {
            // Should not happen — runPersistent persists + flushes
            // before calling applyTags. Skip tags rather than emit
            // EntityTag rows with no FK target.
            return;
        }

        foreach ($tagsToApply as $name) {
            $tag = $this->resolveOrCreateTag($name, $tenant, $tagCache);
            $link = new EntityTag();
            $link->setTag($tag);
            $link->setEntityClass(Document::class);
            $link->setEntityId($documentId);
            $link->setTaggedFrom(new DateTimeImmutable());
            $link->setTaggedBy($run->getStartedByUser());
            $this->entityManager->persist($link);
        }
    }

    /**
     * @param array<string, Tag> $tagCache
     */
    private function resolveOrCreateTag(string $name, ?Tenant $tenant, array &$tagCache): Tag
    {
        $cacheKey = ($tenant?->getId() ?? 0) . ':' . $name;
        if (isset($tagCache[$cacheKey])) {
            return $tagCache[$cacheKey];
        }
        $tag = $this->tagRepository->findOneByName($tenant, $name);
        if ($tag === null) {
            $tag = new Tag();
            $tag->setName($name);
            $tag->setType(Tag::TYPE_CUSTOM);
            $tag->setTenant($tenant);
            $this->entityManager->persist($tag);
        }
        $tagCache[$cacheKey] = $tag;
        return $tag;
    }

    /**
     * W3-I Task 2 + W6-A §0.A.1 — when the PolicyTemplate flags
     * `dpoSectionRequired=true` we auto-create one DocumentSection row
     * per gated key (`privacy_addendum` by default; or every entry of
     * {@see PolicyTemplate::getDpoGatedSectionKeys()} when set).
     * Idempotent on re-runs against the same Document. A NEW Document
     * version (supersedes path) gets its own fresh section; the old
     * section stays bound to the old Document.
     *
     * W6-A §0.A.2 — every gated section is created with `approvalRole`
     * pre-set (default `dpo`). The role MAY be overridden per-key via
     * the template's `requiredVariables` block under the
     * `dpo_section_role_overrides` namespace; the resolver in
     * {@see PolicySectionApprovalService::resolveApprovalRole} can
     * additionally degrade `dpo` → `ciso` for BSI-pure tenants at
     * approval time.
     */
    private function ensurePrivacyAddendumSection(Document $document, PolicyTemplate $template): void
    {
        if (!$template->isDpoSectionRequired()) {
            return;
        }
        $tenant = $document->getTenant();
        if ($tenant === null) {
            return;
        }

        // W6-A §0.A.1 — explicit gated-key list wins over the legacy
        // single-key default. Empty / null → fall back to the W3-I
        // single-key behaviour for backwards compatibility.
        $gatedKeys = $template->getDpoGatedSectionKeys();
        if (!is_array($gatedKeys) || $gatedKeys === []) {
            $gatedKeys = ['privacy_addendum'];
        }

        // W6-A §0.A.2 — per-key approval-role override map authored on
        // the template. Default per spec is `dpo` so the
        // privacy_section_gate fires; tests/seeds may set role=`joint`
        // or `ciso` per section.
        $roleOverrides = $this->resolveSectionRoleOverrides($template);

        foreach ($gatedKeys as $sectionKey) {
            if (!is_string($sectionKey) || $sectionKey === '') {
                continue;
            }
            $this->ensureGatedSection(
                $document,
                $tenant,
                $sectionKey,
                $roleOverrides[$sectionKey] ?? DocumentSection::APPROVAL_ROLE_DPO,
            );
        }
    }

    /**
     * W6-A §0.A.2 — pull per-key approvalRole overrides out of the
     * template's `requiredVariables` block (when authored). Authors can
     * declare e.g.
     *   {"key": "dpo_section_role_overrides",
     *    "type": "map",
     *    "value": {"privacy_addendum": "joint"}}
     * to mark a single host section as a joint CISO+DPO sign-off
     * without changing the global default.
     *
     * @return array<string, string>
     */
    private function resolveSectionRoleOverrides(PolicyTemplate $template): array
    {
        $required = $template->getRequiredVariables() ?? [];
        foreach ($required as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['key'] ?? null) !== 'dpo_section_role_overrides') {
                continue;
            }
            $value = $entry['value'] ?? null;
            if (!is_array($value)) {
                continue;
            }
            $clean = [];
            foreach ($value as $sectionKey => $role) {
                if (!is_string($sectionKey) || !is_string($role)) {
                    continue;
                }
                if (!in_array($role, DocumentSection::ALLOWED_APPROVAL_ROLES, true)) {
                    continue;
                }
                $clean[$sectionKey] = $role;
            }
            return $clean;
        }
        return [];
    }

    /**
     * W6-A §0.A.2 + §0.A.6 — create one gated section row for the host
     * Document. Idempotent: when a row with the same (document, key)
     * already exists we skip and leave the existing approval state as
     * is. Defaults to `approval_role=dpo` so the privacy_section_gate
     * fires; legacy ROLE_CISO sections may set role=`ciso` explicitly.
     */
    private function ensureGatedSection(
        Document $document,
        Tenant $tenant,
        string $sectionKey,
        string $approvalRole,
    ): void {
        if ($this->documentSectionRepository !== null) {
            $existing = $this->documentSectionRepository->findOneByDocumentAndKey(
                $document,
                $sectionKey,
            );
            if ($existing instanceof DocumentSection) {
                return;
            }
        }

        $section = new DocumentSection();
        $section->setDocument($document);
        $section->setSectionKey($sectionKey);
        $section->setStatus(DocumentSection::STATUS_DRAFT);
        $section->setTenant($tenant);
        $section->setApprovalRole($approvalRole);
        $this->entityManager->persist($section);
    }

    /**
     * W6-C — GDPR section injector.
     *
     * Per `06-dpo-input.md` §0 Decision Matrix v2, the DPO addon
     * contributes 10 privacy sections that MERGE into existing ISO 27001
     * host policies (rather than spawning standalone docs). When the
     * tenant adopted GDPR scope (`gdpr` in `Run.standardsAdopted`) we
     * walk the {@see GdprSectionCatalogue} and emit one DocumentSection
     * row per matching ISO topic.
     *
     * Each row carries the catalogue's `approval_role` so the W6-A
     * split-state approval gate fires correctly:
     *  - `dpo`   → DPO-only sign-off (CISO locked out at edit time)
     *  - `joint` → both DPO + CISO must approve
     *  - `ciso`  → CISO-only (no DPO involvement, e.g. premises security)
     *
     * Idempotent on re-generation: if a section with the same key
     * already exists for the Document we skip. Superseding Documents
     * get fresh sections; the old ones stay bound to the old Document
     * for audit-trail integrity (mirrors the §10 immutability contract).
     *
     * Skip rules:
     *  - catalogue not wired (legacy DI graphs / unit tests).
     *  - Document has no parent template / template is not ISO 27001.
     *  - Run.standardsAdopted does NOT include 'gdpr'.
     *  - Catalogue has no entry for the template's topic.
     *
     * Side effects:
     *  - One {@see DocumentSection} row per matching catalogue entry.
     *  - One `gdpr-section:<key>:applied` {@see EntityTag} row per
     *    section so the audit-export view can pivot on which GDPR
     *    sections were injected on a given host Document.
     */
    public function appendGdprSectionsIfApplicable(Document $document, WizardRun $run): void
    {
        if ($this->gdprSectionCatalogue === null) {
            return;
        }
        if (!$this->isGdprScopeActive($run)) {
            return;
        }

        $template = $document->getGeneratedFromTemplate();
        if (!$template instanceof PolicyTemplate) {
            return;
        }
        if ($template->getStandard() !== 'iso27001') {
            return;
        }
        $topic = $template->getTopic();
        if (!is_string($topic) || $topic === '') {
            return;
        }

        $sections = $this->gdprSectionCatalogue->getSectionsFor($topic);
        if ($sections === []) {
            return;
        }

        $tenant = $document->getTenant();
        if ($tenant === null) {
            return;
        }

        foreach ($sections as $row) {
            $this->ensureGdprSection($document, $tenant, $row, $run);
        }
    }

    /**
     * Persist (idempotently) one GDPR section per catalogue row + emit
     * the matching `gdpr-section:<key>:applied` tag on the host Document.
     *
     * @param array{
     *   iso_topic: string,
     *   section_key: string,
     *   gdpr_articles: list<string>,
     *   approval_role: string,
     * } $row
     */
    private function ensureGdprSection(
        Document $document,
        Tenant $tenant,
        array $row,
        WizardRun $run,
    ): void {
        $sectionKey = $row['section_key'];

        if ($this->documentSectionRepository !== null) {
            $existing = $this->documentSectionRepository->findOneByDocumentAndKey(
                $document,
                $sectionKey,
            );
            if ($existing instanceof DocumentSection) {
                // Idempotent: re-runs on the same Document do not
                // duplicate the section. The existing approval_role
                // stays untouched so a DPO sign-off in flight is not
                // accidentally reset.
                return;
            }
        }

        $section = new DocumentSection();
        $section->setDocument($document);
        $section->setSectionKey($sectionKey);
        $section->setStatus(DocumentSection::STATUS_DRAFT);
        $section->setTenant($tenant);
        $section->setApprovalRole($row['approval_role']);

        // Capture a stub content snapshot pointing at the translation
        // key so a draft export render shows "[GDPR Section: <key>
        // (Art. X / Y)]" rather than an empty body. The full prose
        // ships from W6-F (translations) — until then the snapshot
        // labels the section so DPO reviewers can locate it.
        $articles = implode(', ', $row['gdpr_articles']);
        $section->setContentSnapshot(sprintf(
            "[GDPR Section: %s — %s]\n\n_(Authoring pending: policy_wizard.gdpr_section.%s.body)_",
            $sectionKey,
            $articles,
            $sectionKey,
        ));

        $this->entityManager->persist($section);

        // §8.5 — emit one `gdpr-section:<key>:applied` tag per injected
        // section so the audit-export view can pivot on which GDPR
        // sections grew from which run. Mirrors the W4-A
        // `dora-extension:applied` pattern.
        $this->tagGdprSectionApplied($document, $sectionKey, $run);
    }

    /**
     * Resolve-or-create the `gdpr-section:<key>:applied` tag and link
     * it to the Document via {@see EntityTag}. No-op when the Document
     * has no id (defensive — runPersistent always flushes before this
     * point).
     */
    private function tagGdprSectionApplied(Document $document, string $sectionKey, WizardRun $run): void
    {
        $documentId = $document->getId();
        if ($documentId === null) {
            return;
        }
        $tenant = $document->getTenant();
        $tagName = sprintf('gdpr-section:%s:applied', $sectionKey);

        $tag = $this->tagRepository->findOneByName($tenant, $tagName);
        if ($tag === null) {
            $tag = new Tag();
            $tag->setName($tagName);
            $tag->setType(Tag::TYPE_CUSTOM);
            $tag->setTenant($tenant);
            $this->entityManager->persist($tag);
        }

        $link = new EntityTag();
        $link->setTag($tag);
        $link->setEntityClass(Document::class);
        $link->setEntityId($documentId);
        $link->setTaggedFrom(new DateTimeImmutable());
        $link->setTaggedBy($run->getStartedByUser());
        $this->entityManager->persist($link);
    }

    /**
     * W6-C — thin A.5.34 cross-reference host emission.
     *
     * Per §0 Decision Matrix v2 row 18, ISO 27001 A.5.34 demands a
     * topic-specific policy at A.5.34 even when the privacy content
     * itself is satisfied by §2.1 (Cl. 5.2 section) plus the 5
     * standalone privacy artefacts. The v2 compromise is to keep a
     * THIN 1-2 page cross-reference document at A.5.34 that lists
     * where every privacy concern lives instead of either suppressing
     * the row or duplicating content.
     *
     * Emission rules:
     *  - tenant must have BOTH `iso27001` AND `gdpr` in `standardsAdopted`.
     *  - sandbox runs do NOT emit (handled upstream — runPersistent is
     *    only entered from the persistent path).
     *  - re-generation is idempotent: a single thin host per tenant.
     *  - Document is tagged `iso27001:A.5.34` + `policy-wizard:thin-host`
     *    + `wizard-run:<id>` so the audit-export view can pivot on it.
     *
     * Body comes from the `policy.iso.iso_a534_thin_host.v1.body`
     * translation key (W6-F authors the full text). Until then the
     * translator returns the key verbatim and we wrap it in a
     * "_(authoring pending)_" stub so the generated docs stay
     * auditor-presentable.
     *
     * @param array<string, Tag> $tagCache
     * @return int|null id of the emitted Document, or null when the
     *                  emission was skipped (no GDPR scope) or reused
     *                  an existing host.
     */
    private function emitThinA534HostIfApplicable(
        WizardRun $run,
        Tenant $tenant,
        array &$tagCache,
    ): ?int {
        if (!$this->isGdprScopeActive($run)) {
            return null;
        }
        $standards = $run->getStandardsAdopted() ?? [];
        if (!in_array('iso27001', $standards, true)) {
            return null;
        }

        // Idempotent: one thin host per tenant. We pivot on entityType
        // so a re-run finds the existing one and skips emission. The
        // re-emission case is rare in practice (GDPR scope rarely
        // toggles on/off) but the safety check costs us nothing.
        $existing = $this->documentRepository->findOneBy([
            'tenant' => $tenant,
            'entityType' => 'iso_a534_thin_host',
            'isArchived' => false,
        ]);
        if ($existing instanceof Document) {
            return $existing->getId();
        }

        $bodyKey = 'policy.iso.iso_a534_thin_host.v1.body';
        $body = $this->resolvePolicyTranslation($bodyKey, 'iso27001');
        if ($body === $bodyKey) {
            // Translation not yet authored (W6-F deliverable). Emit a
            // stub body listing the 5 standalone privacy docs as
            // cross-references so the host is still meaningful.
            $body = $this->buildThinA534HostStub($bodyKey);
        }

        $now = new DateTimeImmutable();
        $hash = $this->hashSubstitution([], $body);
        $filename = sprintf('policy-iso-a534-thin-host-run%d.md', $run->getId() ?? 0);

        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setFilename($filename);
        $doc->setOriginalFilename($filename);
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(strlen($body));
        $doc->setFilePath('virtual:policy-wizard/' . $filename);
        $doc->setCategory('policy');
        $doc->setDescription($this->firstParagraph($body));
        // Persist body so the exporter does not have to re-render
        // and so a tenant can later append cross-references.
        $doc->setPolicyBody($body);
        $doc->setStatus('draft'); // @phpstan-ignore lifecycle.directSetStatus (initial state on new Document generated by PolicyWizard; 'draft' is the document_lifecycle initial_marking)
        $doc->setUploadedAt($now);
        $doc->setUploadedBy($run->getStartedByUser());
        $doc->setSha256Hash($hash);
        $doc->setEntityType('iso_a534_thin_host');
        $doc->setEntityId($run->getId());
        $doc->setGeneratedFromWizardRun($run);
        $doc->setSubstitutionVariables([
            '_hash' => $hash,
            '_thin_host' => true,
            '_iso_control' => 'A.5.34',
            '_cross_references' => [
                'dpo_charter',
                'ropa_methodology',
                'dpia_methodology',
                'dsr_procedure',
                'retention_schedule',
            ],
        ]);
        $doc->setIsImmutable(false);

        $this->entityManager->persist($doc);
        $this->entityManager->flush();

        $documentId = (int) $doc->getId();

        // §8.5-style tagging — emit ISO + thin-host markers so the
        // audit-export view can pivot on the A.5.34 row.
        $tags = [
            'policy-wizard-generated',
            'policy-wizard:thin-host',
            'iso27001:A.5.34',
            'standard:iso27001',
            'topic:iso_a534_thin_host',
            'version:1',
            'wizard-run:' . ($run->getId() ?? 0),
        ];

        foreach ($tags as $tagName) {
            $tag = $this->resolveOrCreateTag($tagName, $tenant, $tagCache);
            $link = new EntityTag();
            $link->setTag($tag);
            $link->setEntityClass(Document::class);
            $link->setEntityId($documentId);
            $link->setTaggedFrom(new DateTimeImmutable());
            $link->setTaggedBy($run->getStartedByUser());
            $this->entityManager->persist($link);
        }

        $this->entityManager->flush();

        $this->logger->info('PolicyWizard W6-C: emitted thin A.5.34 host', [
            'wizard_run_id' => $run->getId(),
            'tenant_id' => $tenant->getId(),
            'document_id' => $documentId,
        ]);

        return $documentId;
    }

    /**
     * Stub body for the thin A.5.34 host when the translation key is
     * not yet authored. Lists the 5 standalone privacy artefacts as
     * cross-references plus a header tagging the document as a thin
     * host (1-2 page only) per §0 v2 row 18.
     */
    private function buildThinA534HostStub(string $bodyKey): string
    {
        return <<<MARKDOWN
            # ISO 27001 A.5.34 — Privacy / PII Handling

            > Thin host (1-2 page cross-reference). Per ISO 27001 A.5.34
            > "the organization shall maintain a topic-specific policy".
            > This document satisfies that requirement by referencing the
            > full set of standalone privacy artefacts maintained under
            > the GDPR addon.

            ## Cross-references

            The privacy aspects of the ISMS are documented in:

            1. **DPO Charter** (§2.13) — Data Protection Officer mandate, independence and tasks per GDPR Art. 37-39.
            2. **RoPA Methodology** (§2.2) — Records of Processing Activities + Lawful-Basis + Consent Management per GDPR Art. 30 / 6 / 7.
            3. **DPIA Methodology** (§2.3) — Data Protection Impact Assessment process per GDPR Art. 35-36.
            4. **DSR Procedure** (§2.4) — Data Subject Rights handling per GDPR Art. 12-22 (1-month SLA).
            5. **Retention Schedule** (§2.11) — Retention durations per data category, single-source-of-truth.

            Operational privacy controls additionally live as embedded sections inside the ISO host policies (Cl. 5.2, A.5.24-28, A.5.19-22, A.5.14, A.8.13/15, A.5.8/A.8.27/28, A.6.3) — see the GDPR section catalogue for the per-topic injection map.

            _(Authoring pending: $bodyKey — the W6-F translation deliverable replaces this stub with the full cross-reference matrix.)_
            MARKDOWN;
    }

    /**
     * Whether the wizard run has GDPR scope active. Accepts both
     * `gdpr` and `iso27701` in `standardsAdopted` because PIMS is the
     * formal GDPR overlay and tenants commonly adopt it as the GDPR
     * marker in ISO-aligned shops.
     */
    private function isGdprScopeActive(WizardRun $run): bool
    {
        $standards = $run->getStandardsAdopted() ?? [];
        return in_array('gdpr', $standards, true)
            || in_array('iso27701', $standards, true);
    }
}
