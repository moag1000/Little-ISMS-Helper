<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline\Source;

use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Repository\ManagementReviewRepository;
use App\Service\Deadline\Source\ManagementReviewFollowUpSource;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ISO 27001 9.3: after a completed management review the next one is a duty
 * — at its next-review date, else twelve months later — until it is planned.
 */
final class ManagementReviewFollowUpSourceTest extends TestCase
{
    #[Test]
    public function aCompletedNewestReviewMakesTheNextOneDue(): void
    {
        $review = (new ManagementReview())->setTitle('MR 2026')->setReviewDate(new DateTime('2026-03-01'))->setStatus('completed');
        $source = $this->source($review);

        $open = [...$source->findOpen(new Tenant())];
        self::assertSame([$review], $open);
        self::assertEquals(new DateTimeImmutable('2027-03-01'), $source->dueDateOf($review), 'twelve months without a date');

        $review->setNextReviewDate(new DateTimeImmutable('2027-01-15'));
        self::assertEquals(new DateTimeImmutable('2027-01-15'), $source->dueDateOf($review));
        self::assertTrue($source->supports($review));
    }

    #[Test]
    public function aPlannedReviewEndsTheFollowUp(): void
    {
        $planned = (new ManagementReview())->setTitle('MR 2027')->setReviewDate(new DateTime('2027-02-01'))->setStatus('planned');
        $source = $this->source($planned);

        self::assertSame([], [...$source->findOpen(new Tenant())], 'the planned review is the ManagementReviewAdapter\'s');
        self::assertFalse($source->supports($planned));
        self::assertSame([], [...$this->source(null)->findOpen(new Tenant())]);
    }

    private function source(?ManagementReview $latest): ManagementReviewFollowUpSource
    {
        $repository = $this->createStub(ManagementReviewRepository::class);
        $repository->method('findLatestForTenant')->willReturn($latest);

        return new ManagementReviewFollowUpSource($repository);
    }
}
