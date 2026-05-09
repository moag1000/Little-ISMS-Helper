<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Diff;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Repository\DocumentSectionRepository;

/**
 * Policy-Wizard W7-C — Re-generation diff service.
 *
 * Computes a doc-level + variable-level diff between two Document
 * versions linked by `Document.supersedes`. Strictly **NOT** a
 * character-level diff: per the ISB practitioner review
 * (`docs/plans/policy-wizard/persona-reviews/05-isb-practitioner-review.md`
 * lines 198-204) sentence-/character-diffs were explicitly waived in
 * favour of "Section-level + Variable-level".
 *
 * Diff surfaces:
 *  1. Metadata delta — title (originalFilename), category, status,
 *     entityType, isImmutable, isInheritable, overrideAllowed,
 *     tisaxInformationClassification, plus the policy-wizard-specific
 *     `_template_version` snapshotted in `substitutionVariables`.
 *  2. Variable changes — flattened dot-notation diff via
 *     {@see SubstitutionVariableDiff}, drops system `_*` keys.
 *  3. Section changes — per-`sectionKey` add/remove/modified set. The
 *     "modified" branch reports the previous + current snapshot hash
 *     ONLY (sha256 over content_snapshot), never the content itself.
 *  4. Body hash — top-level `Document.sha256Hash` change indicator so
 *     a re-render with identical metadata still surfaces as "body
 *     changed" even when no section rows exist.
 *
 * Severity heuristic (see {@see PolicyDiff} class doc-block):
 *  - minor    → metadata only OR ≤2 variable changes
 *  - moderate → 3+ variable changes OR 1-2 section adds/removes
 *  - major    → standard or topic changed OR 3+ section adds/removes
 */
final class PolicyDiffService
{
    /**
     * Metadata fields we surface in the doc-level delta. Each entry is a
     * tuple of (field-label, accessor-callable). The label is the i18n
     * key that the diff template looks up in the `policy_wizard` domain.
     *
     * @var array<string, callable(Document): mixed>
     */
    private array $metadataAccessors;

    public function __construct(
        private readonly ?DocumentSectionRepository $documentSectionRepository = null,
    ) {
        $this->metadataAccessors = [
            'title' => static fn (Document $d): ?string => $d->getOriginalFilename(),
            'category' => static fn (Document $d): ?string => $d->getCategory(),
            'status' => static fn (Document $d): string => $d->getStatus(),
            'entityType' => static fn (Document $d): ?string => $d->getEntityType(),
            'isImmutable' => static fn (Document $d): bool => $d->isImmutable(),
            'isInheritable' => static fn (Document $d): bool => $d->isInheritable(),
            'overrideAllowed' => static fn (Document $d): bool => $d->isOverrideAllowed(),
            'tisaxInformationClassification' => static fn (Document $d): ?string => $d->getTisaxInformationClassification(),
            'templateVersion' => static fn (Document $d): mixed => self::extractInternalVar($d, '_template_version'),
            'standard' => static fn (Document $d): ?string => self::extractStandard($d),
            'topic' => static fn (Document $d): ?string => self::extractTopic($d),
        ];
    }

    /**
     * Diff two Documents. The previous + current order is significant:
     * `previous` is the older version (the one being superseded), `current`
     * is the newer version (the one with `setSupersedes(previous)`).
     */
    public function diffDocuments(Document $previous, Document $current): PolicyDiff
    {
        $metadataDelta = $this->computeMetadataDelta($previous, $current);
        $variableChanges = SubstitutionVariableDiff::diff(
            $previous->getSubstitutionVariables(),
            $current->getSubstitutionVariables(),
        );

        $previousSections = $this->loadSections($previous);
        $currentSections = $this->loadSections($current);

        $sectionsAdded = [];
        $sectionsRemoved = [];
        $sectionsModified = [];

        $previousByKey = $this->indexByKey($previousSections);
        $currentByKey = $this->indexByKey($currentSections);

        foreach ($currentByKey as $key => $section) {
            if (!isset($previousByKey[$key])) {
                $sectionsAdded[] = $section;
                continue;
            }
            $oldHash = $this->hashSection($previousByKey[$key]);
            $newHash = $this->hashSection($section);
            if ($oldHash !== $newHash) {
                $sectionsModified[] = [
                    'section' => $section,
                    'oldHash' => $oldHash,
                    'newHash' => $newHash,
                ];
            }
        }
        foreach ($previousByKey as $key => $section) {
            if (!isset($currentByKey[$key])) {
                $sectionsRemoved[] = $section;
            }
        }

        $bodyHashChanged = ($previous->getSha256Hash() ?? '') !== ($current->getSha256Hash() ?? '');

        $summary = $this->summariseSeverity(
            metadataDelta: $metadataDelta,
            variableChanges: $variableChanges,
            sectionsAdded: $sectionsAdded,
            sectionsRemoved: $sectionsRemoved,
            sectionsModified: $sectionsModified,
            bodyHashChanged: $bodyHashChanged,
        );

        return new PolicyDiff(
            previous: $previous,
            current: $current,
            metadataChanged: $metadataDelta !== [],
            metadataDelta: $metadataDelta,
            variableChanges: $variableChanges,
            sectionsAdded: $sectionsAdded,
            sectionsRemoved: $sectionsRemoved,
            sectionsModified: $sectionsModified,
            bodyHashChanged: $bodyHashChanged,
            summaryStats: $summary,
        );
    }

    /**
     * @return list<array{field: string, oldValue: mixed, newValue: mixed}>
     */
    private function computeMetadataDelta(Document $previous, Document $current): array
    {
        $out = [];
        foreach ($this->metadataAccessors as $field => $accessor) {
            $old = $accessor($previous);
            $new = $accessor($current);
            if ($old === $new) {
                continue;
            }
            if (is_numeric($old) && is_numeric($new) && (float) $old === (float) $new) {
                continue;
            }
            $out[] = [
                'field' => $field,
                'oldValue' => $old,
                'newValue' => $new,
            ];
        }
        return $out;
    }

    /**
     * @return list<DocumentSection>
     */
    private function loadSections(Document $document): array
    {
        if ($this->documentSectionRepository === null) {
            return [];
        }
        return $this->documentSectionRepository->findByDocument($document);
    }

    /**
     * @param list<DocumentSection> $sections
     * @return array<string, DocumentSection>
     */
    private function indexByKey(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            $key = $section->getSectionKey();
            if ($key === null || $key === '') {
                continue;
            }
            $out[$key] = $section;
        }
        return $out;
    }

    /**
     * Hash-only section comparison. Per §15 spec we never expose the
     * content body in the diff surface — auditors get a "this changed"
     * indicator and re-open the section in the editor for context.
     */
    private function hashSection(DocumentSection $section): ?string
    {
        $snapshot = $section->getContentSnapshot();
        if ($snapshot === null || $snapshot === '') {
            return null;
        }
        return hash('sha256', $snapshot);
    }

    /**
     * @param list<array{field: string, oldValue: mixed, newValue: mixed}> $metadataDelta
     * @param list<array{key: string, change_type: string, oldValue: mixed, newValue: mixed}> $variableChanges
     * @param list<DocumentSection> $sectionsAdded
     * @param list<DocumentSection> $sectionsRemoved
     * @param list<array{section: DocumentSection, oldHash: ?string, newHash: ?string}> $sectionsModified
     * @return array{totalChanges: int, severity: string}
     */
    private function summariseSeverity(
        array $metadataDelta,
        array $variableChanges,
        array $sectionsAdded,
        array $sectionsRemoved,
        array $sectionsModified,
        bool $bodyHashChanged,
    ): array {
        $sectionAddRemoves = count($sectionsAdded) + count($sectionsRemoved);
        $variableChangeCount = count($variableChanges);

        $totalChanges = count($metadataDelta)
            + $variableChangeCount
            + $sectionAddRemoves
            + count($sectionsModified)
            + ($bodyHashChanged ? 1 : 0);

        // Major: standard / topic flipped, OR ≥3 section adds/removes.
        $standardOrTopicChanged = false;
        foreach ($metadataDelta as $row) {
            if (in_array($row['field'], ['standard', 'topic'], true)) {
                $standardOrTopicChanged = true;
                break;
            }
        }
        if ($standardOrTopicChanged || $sectionAddRemoves >= 3) {
            return ['totalChanges' => $totalChanges, 'severity' => PolicyDiff::SEVERITY_MAJOR];
        }

        // Moderate: 3+ variable changes OR 1-2 section adds/removes.
        if ($variableChangeCount >= 3 || $sectionAddRemoves >= 1) {
            return ['totalChanges' => $totalChanges, 'severity' => PolicyDiff::SEVERITY_MODERATE];
        }

        // Default minor — covers metadata-only, ≤2 variables, hash-only re-render.
        return ['totalChanges' => $totalChanges, 'severity' => PolicyDiff::SEVERITY_MINOR];
    }

    /**
     * Pull a system-internal `_*` marker out of substitutionVariables.
     * Used for snapshotted template-version comparisons in the metadata
     * delta — these are explicitly NOT exposed via the variable-diff
     * surface (which strips `_*` keys).
     */
    private static function extractInternalVar(Document $document, string $key): mixed
    {
        $vars = $document->getSubstitutionVariables();
        if (!is_array($vars)) {
            return null;
        }
        return $vars[$key] ?? null;
    }

    /**
     * Resolve the standard label for a Document. We prefer the
     * generatedFromTemplate relation (auditor-stable), fall back to the
     * `_standard` key in substitutionVariables (set by older runs), then
     * to null. The fallback chain matters for documents generated before
     * the W3 supersession path was wired.
     */
    private static function extractStandard(Document $document): ?string
    {
        $template = $document->getGeneratedFromTemplate();
        if ($template !== null) {
            return $template->getStandard();
        }
        $vars = $document->getSubstitutionVariables();
        if (is_array($vars) && isset($vars['_standard']) && is_string($vars['_standard'])) {
            return $vars['_standard'];
        }
        return null;
    }

    private static function extractTopic(Document $document): ?string
    {
        $template = $document->getGeneratedFromTemplate();
        if ($template !== null) {
            return $template->getTopic();
        }
        $vars = $document->getSubstitutionVariables();
        if (is_array($vars) && isset($vars['_topic']) && is_string($vars['_topic'])) {
            return $vars['_topic'];
        }
        return null;
    }
}
