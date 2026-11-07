<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\IsmsRegisterEntry;
use App\Enum\RegisterType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IsmsRegisterEntryTest extends TestCase
{
    #[Test]
    public function replaceLinksKeepsOtherFieldsAndDropsDuplicates(): void
    {
        $entry = new IsmsRegisterEntry(RegisterType::LEGAL_REQUIREMENT);
        $entry->replaceLinks('supplier', [1, 2, 2]);
        $entry->replaceLinks('document', [7]);
        $entry->replaceLinks('supplier', [2, 3]);

        $supplier = $entry->linkedIds('supplier');
        sort($supplier);
        self::assertSame([2, 3], $supplier);
        self::assertSame([7], $entry->linkedIds('document'));
        self::assertCount(3, $entry->getLinks());
    }

    #[Test]
    public function reviewMovesTheNextDateByTheRegisterInterval(): void
    {
        $entry = new IsmsRegisterEntry(RegisterType::COMMUNICATION);
        $entry->setNextReviewDate(new DateTimeImmutable('2026-01-01'));
        self::assertTrue($entry->isReviewOverdue(new DateTimeImmutable('2026-02-01')));

        $entry->markReviewed(new DateTimeImmutable('2026-02-01 10:00'));
        self::assertSame('2026-05-01', $entry->getNextReviewDate()?->format('Y-m-d'));
        self::assertFalse($entry->isReviewOverdue(new DateTimeImmutable('2026-02-01')));
    }

    #[Test]
    public function archivedEntriesAreNeverOverdue(): void
    {
        $entry = new IsmsRegisterEntry(RegisterType::ROLE);
        $entry->setNextReviewDate(new DateTimeImmutable('2020-01-01'));
        $entry->archive(new DateTimeImmutable());
        self::assertFalse($entry->isReviewOverdue());

        $entry->restore();
        self::assertTrue($entry->isReviewOverdue());
    }
}
