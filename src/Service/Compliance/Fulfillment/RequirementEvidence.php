<?php

declare(strict_types=1);

namespace App\Service\Compliance\Fulfillment;

use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\DocumentControlLinkRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The organisation's evidence behind one requirement, from every place it is
 * attached: the requirement itself, the organisation's fulfilment row, and the
 * controls the rule reads (control evidence and document–control links). One
 * document attached to control A.5.1 thereby serves ISO 27001, NIS-2, TISAX
 * and every other requirement A.5.1 stands behind.
 *
 * Tenant-safe: a global requirement's own evidence list and its mapped
 * controls may hold every organisation's rows; only the given organisation's
 * documents come back.
 */
final class RequirementEvidence implements ResetInterface
{
    public const SOURCE_REQUIREMENT = 'requirement';
    public const SOURCE_FULFILLMENT = 'fulfillment';
    public const SOURCE_CONTROL = 'control';

    /** @var array<int, list<Document>> control id → its documents (one control serves many requirements) */
    private array $controlDocuments = [];

    public function __construct(
        private readonly FulfillmentRuleEngine $engine,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly ?DocumentControlLinkRepository $linkRepository = null,
    ) {
    }

    /**
     * @return list<array{document: Document, sources: list<array{type: string, ref: ?string, id: ?int}>}>
     */
    public function for(ComplianceRequirement $requirement, Tenant $tenant, ?ComplianceRequirementFulfillment $fulfillment = null): array
    {
        /** @var array<int, array{document: Document, sources: list<array{type: string, ref: ?string, id: ?int}>}> $found */
        $found = [];
        $add = static function (Document $document, string $type, ?string $ref, ?int $id) use (&$found, $tenant): void {
            if ($document->getTenant()?->getId() !== $tenant->getId() || $document->getId() === null) {
                return;
            }
            $key = $document->getId();
            $found[$key] ??= ['document' => $document, 'sources' => []];
            foreach ($found[$key]['sources'] as $source) {
                if ($source['type'] === $type && $source['id'] === $id) {
                    return;
                }
            }
            $found[$key]['sources'][] = ['type' => $type, 'ref' => $ref, 'id' => $id];
        };

        foreach ($requirement->getEvidenceDocuments() as $document) {
            $add($document, self::SOURCE_REQUIREMENT, null, null);
        }

        $fulfillment ??= $this->fulfillmentRepository->findOneBy(['tenant' => $tenant, 'requirement' => $requirement]);
        if ($fulfillment instanceof ComplianceRequirementFulfillment && $fulfillment->getTenant()?->getId() === $tenant->getId()) {
            foreach ($fulfillment->getEvidenceDocuments() as $document) {
                $add($document, self::SOURCE_FULFILLMENT, null, null);
            }
        }

        foreach ($this->engine->controlsFor($requirement, $tenant) as $control) {
            foreach ($this->controlDocuments($control) as $document) {
                $add($document, self::SOURCE_CONTROL, $control->getControlId(), $control->getId());
            }
        }

        return array_values($found);
    }

    /**
     * Documents only, deduplicated — for exports and counts.
     *
     * @return list<Document>
     */
    public function documentsFor(ComplianceRequirement $requirement, Tenant $tenant): array
    {
        return array_map(static fn (array $row): Document => $row['document'], $this->for($requirement, $tenant));
    }

    public function reset(): void
    {
        $this->controlDocuments = [];
    }

    /**
     * @return list<Document>
     */
    private function controlDocuments(Control $control): array
    {
        $id = (int) $control->getId();
        if (isset($this->controlDocuments[$id])) {
            return $this->controlDocuments[$id];
        }
        $documents = $control->getEvidenceDocuments()->toArray();
        foreach ($this->linkRepository?->findByControl($control) ?? [] as $link) {
            $document = $link->getDocument();
            if ($document instanceof Document) {
                $documents[] = $document;
            }
        }

        return $this->controlDocuments[$id] = array_values($documents);
    }
}
