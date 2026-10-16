<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\EntityTag;
use App\Entity\PolicyTemplate;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\DocumentSectionRepository;
use App\Repository\TagRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Injects the DPO addon's GDPR sections into generated ISO 27001 host
 * policies. Extracted from {@see DocumentGenerator} (god-class ratchet,
 * Gate 25); the generator calls {@see inject()} for every persisted
 * Document of a wizard run.
 */
class GdprSectionInjector
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagRepository $tagRepository,
        private readonly GdprSectionCatalogue $gdprSectionCatalogue,
        private readonly ?DocumentSectionRepository $documentSectionRepository = null,
    ) {
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
    public function inject(Document $document, WizardRun $run): void
    {
        if (!self::isGdprScopeActive($run)) {
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
     * Whether the wizard run has GDPR scope active. Accepts both
     * `gdpr` and `iso27701` in `standardsAdopted` because PIMS is the
     * formal GDPR overlay and tenants commonly adopt it as the GDPR
     * marker in ISO-aligned shops.
     */
    public static function isGdprScopeActive(WizardRun $run): bool
    {
        $standards = $run->getStandardsAdopted() ?? [];
        return in_array('gdpr', $standards, true)
            || in_array('iso27701', $standards, true);
    }
}
