<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for Document periodic review (ISO 27001 Cl. 7.5.3 /
 * document-lifecycle review obligation).
 *
 * Deadline field : nextReviewDate (set by DocumentApprovalListener when a
 *                  document reaches `approved` or `published` status)
 *
 * Terminal-status rationale: document review is a *recurring* obligation —
 * completing one cycle does not retire the document, it schedules the next
 * review. isCompleted() therefore always returns false so the conversion
 * service keeps emitting ActionItems on every cycle. The ActionItem itself
 * is closed by the planner when the review is done, at which point the
 * document's nextReviewDate will be updated and a fresh ActionItem will be
 * emitted on the next conversion run (idempotency via refId provenance
 * prevents duplicates within the same cycle).
 */
final class DocumentReviewAdapter implements SourceAdapter
{
    public function __construct(
        private readonly DocumentRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'document_review';
    }

    public function label(): string
    {
        return 'Dokumenten-Review';
    }

    public function requiredModule(): ?string
    {
        return 'documents';
    }

    /** @return iterable<Document> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy(['tenant' => $tenant]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Document);
        return $item->getNextReviewDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Document);
        return $item->getOriginalFilename() ?? '#' . $item->getId();
    }

    /**
     * Document review never reaches a terminal "done forever" state —
     * reviews recur on every review-cycle. Always return false so the
     * pipeline keeps emitting the ActionItem until the ActionItem itself
     * is explicitly closed by the planner (at which point nextReviewDate
     * will have been bumped and a new provenance ref will be created).
     */
    public function isCompleted(object $item): bool
    {
        return false;
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof Document);
        return (int) $item->getId();
    }
}
