<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\DocumentReviewCheck;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DocumentReviewCheckTest extends TestCase
{
    private function doc(string $category, string $status, ?\DateTimeInterface $nextReview = null): Document
    {
        $doc = $this->createMock(Document::class);
        $doc->method('getCategory')->willReturn($category);
        $doc->method('getStatus')->willReturn($status);
        $doc->method('getNextReviewDate')->willReturn($nextReview);

        return $doc;
    }

    /**
     * @param list<Document> $documents
     */
    private function buildCheck(array $documents): DocumentReviewCheck
    {
        $repo = $this->createMock(DocumentRepository::class);
        $repo->method('findByTenant')->willReturn($documents);

        return new DocumentReviewCheck($repo);
    }

    #[Test]
    public function noDocumentsScoresZeroNoData(): void
    {
        $result = $this->buildCheck([])->check(['document_categories' => ['policy']], $this->createMock(Tenant::class));

        self::assertSame(0.0, $result['score']);
        self::assertSame('no_data', $result['details']['reason']);
        self::assertNotNull($result['gap']);
    }

    #[Test]
    public function onlyDocumentsOfConfiguredCategoriesCount(): void
    {
        $result = $this->buildCheck([$this->doc('invoice', 'approved')])
            ->check(['document_categories' => ['policy']], $this->createMock(Tenant::class));

        self::assertSame(0.0, $result['score']);
        self::assertSame('no_data', $result['details']['reason']);
    }

    #[Test]
    public function scoreIsShareOfApprovedAndNotOverdueDocuments(): void
    {
        $now = new \DateTimeImmutable('2026-06-01');
        $check = $this->buildCheck([
            $this->doc('policy', 'approved', new \DateTimeImmutable('2026-12-01')),
            $this->doc('policy', 'published'),
            $this->doc('policy', 'approved', new \DateTimeImmutable('2026-01-01')), // review overdue
            $this->doc('policy', 'draft'),
            $this->doc('other', 'approved'), // other category, ignored
        ]);

        $result = $check->check(['document_categories' => ['policy']], $this->createMock(Tenant::class), $now);

        self::assertSame(50.0, $result['score']);
        self::assertSame(4, $result['details']['total']);
        self::assertSame(2, $result['details']['current']);
        self::assertSame(1, $result['details']['overdue']);
        self::assertNotNull($result['gap']);
    }

    #[Test]
    public function allCurrentDocumentsScoreHundredWithoutGap(): void
    {
        $result = $this->buildCheck([$this->doc('policy', 'published')])
            ->check(['document_categories' => ['policy']], $this->createMock(Tenant::class));

        self::assertSame(100.0, $result['score']);
        self::assertNull($result['gap']);
    }
}
