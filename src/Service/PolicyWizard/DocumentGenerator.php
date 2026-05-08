<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\DocumentControlLink;
use App\Entity\EntityTag;
use App\Entity\PolicyTemplate;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\PolicyTemplateRepository;
use App\Repository\TagRepository;
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
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array{document_ids: list<int>, sandbox_preview: array<string, mixed>|null}
     */
    public function generate(WizardRun $run): array
    {
        $tenant = $run->getTenant();
        if ($tenant === null) {
            throw new RuntimeException('WizardRun must have a tenant.');
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

        foreach ($templates as $template) {
            $body = $this->renderBody($template, $variables);
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

            $this->entityManager->flush();
            $documentIds[] = (int) $document->getId();
        }

        return $documentIds;
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
            $previews[] = [
                'template_key' => $template->getKey(),
                'standard' => $template->getStandard(),
                'topic' => $template->getTopic(),
                'title' => $this->translator->trans($template->getTitleTranslationKey() ?? ''),
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
     * @return list<PolicyTemplate>
     */
    private function collectTemplatesFor(WizardRun $run): array
    {
        $standards = $run->getStandardsAdopted() ?? [];
        $targeted = $run->getTargetedTopics();

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
                $hits[] = $row;
            }
        }
        return $hits;
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
            return '';
        }
        $rawBody = $this->translator->trans($bodyKey);
        return $this->substitute($rawBody, $variables);
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
        $title = $this->translator->trans($template->getTitleTranslationKey() ?? '');

        if ($existing !== null) {
            $existingHash = $this->hashOf($existing);
            $isApproved = $existing->getStatus() === 'approved' || $existing->isImmutable();

            if ($isApproved && $existingHash === $hash) {
                // Identical content → reuse.
                return $existing;
            }

            if ($isApproved && $existingHash !== $hash) {
                // Create new version, supersedes the old.
                $next = $this->makeFreshDocument($run, $tenant, $template, $title, $body, $variables);
                $next->setSupersedes($existing);
                return $next;
            }

            // Draft path — overwrite in place.
            $existing->setDescription($this->firstParagraph($body));
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
        $doc->setStatus('draft');
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
}
