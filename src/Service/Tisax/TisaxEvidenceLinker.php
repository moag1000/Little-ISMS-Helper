<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Entity\ComplianceRequirement;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;

/**
 * Resolves the free-text "Referenz Dokumentation" citation carried by an
 * imported TISAX ComplianceRequirement into real {@see Document} evidence
 * (B3, spec §9.1 / §9.4).
 *
 * Before this, the VDA-ISA import stored the assessor's cited documents only as
 * a string in `dataSourceMapping.referenceDocumentation`, leaving the entity's
 * `evidenceDocuments` M2M (ISO 27001 Cl. 7.5.3, control "M-05") empty — so an
 * auditor could not click through from control 1.1.1 to the document that
 * proves it.
 *
 * Matching is deliberately CONSERVATIVE (spec: "exact/normalised title only,
 * do NOT auto-link on a weak/fuzzy match"):
 *  - the citation is split into individual candidates on common separators,
 *  - each candidate is normalised (trim, collapse whitespace, casefold, strip a
 *    trailing file extension) and compared for EQUALITY against the normalised
 *    filename / originalFilename of every Document the tenant can see,
 *  - on an exact normalised match the Document is linked via
 *    {@see ComplianceRequirement::addEvidenceDocument()},
 *  - every candidate that does NOT resolve is recorded in
 *    `dataSourceMapping.unlinked_citations` (a typed review list) with a
 *    `unlinked_citations_count` — never silently dropped (no-silent-cap rule).
 *
 * Pure linkage service: it mutates the passed requirement (collection + JSON)
 * but does NOT flush — the caller owns the unit of work.
 */
final class TisaxEvidenceLinker
{
    /** Separators an assessor commonly uses between several cited documents. */
    private const SEPARATORS = ["\n", "\r", ';', '|', ',', '•'];

    public function __construct(
        private readonly DocumentRepository $documentRepository,
    ) {}

    /**
     * Link evidence for a single requirement.
     *
     * @return array{linked: int, unlinked: int, unlinked_citations: list<string>}
     */
    public function linkRequirement(ComplianceRequirement $requirement, Tenant $tenant): array
    {
        $mapping  = $requirement->getDataSourceMapping() ?? [];
        $citation = isset($mapping['referenceDocumentation'])
            ? (string) $mapping['referenceDocumentation']
            : '';

        $candidates = $this->splitCitations($citation);
        if ($candidates === []) {
            return ['linked' => 0, 'unlinked' => 0, 'unlinked_citations' => []];
        }

        $index = $this->buildDocumentIndex($tenant);

        $linked    = 0;
        $unlinked  = [];
        foreach ($candidates as $candidate) {
            $normalised = $this->normalise($candidate);
            if ($normalised === '') {
                continue;
            }

            $document = $index[$normalised] ?? null;
            if ($document instanceof Document) {
                $requirement->addEvidenceDocument($document);
                $linked++;
            } else {
                // Preserve the assessor's verbatim citation for the review queue.
                $unlinked[] = $candidate;
            }
        }

        // Record the typed review list. Always (re)write the keys so a re-run
        // that has since matched a document clears the stale unmatched entry.
        if ($unlinked !== []) {
            $mapping['unlinked_citations']       = array_values($unlinked);
            $mapping['unlinked_citations_count'] = count($unlinked);
        } else {
            unset($mapping['unlinked_citations'], $mapping['unlinked_citations_count']);
        }
        $requirement->setDataSourceMapping($mapping ?: null);

        return [
            'linked'             => $linked,
            'unlinked'           => count($unlinked),
            'unlinked_citations' => array_values($unlinked),
        ];
    }

    /**
     * Link evidence across a batch of requirements (e.g. one import run).
     *
     * @param iterable<ComplianceRequirement> $requirements
     * @return array{linked: int, unlinked: int, requirements_with_unlinked: int}
     */
    public function linkBatch(iterable $requirements, Tenant $tenant): array
    {
        $linked  = 0;
        $unlinked = 0;
        $reqsWithUnlinked = 0;
        foreach ($requirements as $requirement) {
            $result = $this->linkRequirement($requirement, $tenant);
            $linked   += $result['linked'];
            $unlinked += $result['unlinked'];
            if ($result['unlinked'] > 0) {
                $reqsWithUnlinked++;
            }
        }

        return [
            'linked'                       => $linked,
            'unlinked'                     => $unlinked,
            'requirements_with_unlinked'   => $reqsWithUnlinked,
        ];
    }

    /**
     * Split the free-text citation into individual document references.
     *
     * @return list<string>
     */
    private function splitCitations(string $citation): array
    {
        $citation = trim($citation);
        if ($citation === '') {
            return [];
        }

        $normalisedSeparators = str_replace(self::SEPARATORS, "\n", $citation);
        $parts = array_map('trim', explode("\n", $normalisedSeparators));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * Build a normalised-name → Document lookup for the tenant (own + inherited).
     * Last write wins on a collision, which is acceptable for the conservative
     * exact-match contract (a duplicate normalised name is itself a data-quality
     * signal, not something we should fuzzily guess between).
     *
     * @return array<string, Document>
     */
    private function buildDocumentIndex(Tenant $tenant): array
    {
        $index = [];
        foreach ($this->documentRepository->findByTenantIncludingParent($tenant) as $document) {
            foreach ([$document->getOriginalFilename(), $document->getFilename()] as $name) {
                $key = $this->normalise((string) $name);
                if ($key !== '') {
                    $index[$key] = $document;
                }
            }
        }

        return $index;
    }

    /**
     * Normalise a candidate / filename for exact comparison: casefold, collapse
     * whitespace, drop a single trailing file extension. Intentionally NOT a
     * fuzzy / similarity transform.
     */
    private function normalise(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Strip a single trailing extension (".pdf", ".docx", …) so a citation
        // "Informationssicherheitsleitlinie" matches a file
        // "Informationssicherheitsleitlinie.pdf" and vice versa.
        $value = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $value);

        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return mb_strtolower(trim($value));
    }
}
