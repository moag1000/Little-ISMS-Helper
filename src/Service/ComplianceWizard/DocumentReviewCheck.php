<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;

/**
 * The `document_review` compliance-wizard check.
 *
 * Score = share of the tenant's (non-archived) documents in the configured
 * `document_categories` that are approved or published and not past their
 * review date. No matching document scores 0 ("no data recorded" is no evidence).
 */
final class DocumentReviewCheck
{
    private const array OK_STATUSES = ['approved', 'published'];

    public function __construct(
        private readonly DocumentRepository $documentRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $check config key `document_categories` (list<string>, optional)
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function check(array $check, ?Tenant $tenant, ?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable('today');
        $categories = array_values(array_filter((array) ($check['document_categories'] ?? []), 'is_string'));

        /** @var list<Document> $documents */
        $documents = $tenant !== null
            ? $this->documentRepository->findByTenant($tenant)
            : $this->documentRepository->findBy(['isArchived' => false]);

        if ($categories !== []) {
            $documents = array_values(array_filter(
                $documents,
                static fn (Document $d): bool => $d->getCategory() !== null && in_array($d->getCategory(), $categories, true),
            ));
        }

        $total = count($documents);
        if ($total === 0) {
            return NoDataResult::build('wizard.gap.no_documents', 'app_document_index', $check, [
                'type' => 'document_review',
                'total' => 0,
                'categories' => $categories,
            ]);
        }

        $current = 0;
        $overdue = 0;
        foreach ($documents as $document) {
            $next = $document->getNextReviewDate();
            $isOverdue = $next !== null && $next < $now;
            if ($isOverdue) {
                ++$overdue;
            }
            if (!$isOverdue && in_array($document->getStatus(), self::OK_STATUSES, true)) {
                ++$current;
            }
        }

        $score = round(($current / $total) * 100, 1);
        $gap = null;
        if ($score < 100.0) {
            $gap = [
                'title' => 'wizard.gap.documents_review_pending',
                'description' => 'wizard.gap.documents_review_pending_desc',
                'description_params' => ['%current%' => $current, '%total%' => $total],
                'priority' => $check['priority'] ?? 'medium',
                'route' => $check['route'] ?? 'app_document_index',
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'type' => 'document_review',
                'total' => $total,
                'current' => $current,
                'overdue' => $overdue,
                'categories' => $categories,
            ],
            'gap' => $gap,
        ];
    }
}
