<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\ManagementReview;

use App\AlvaHint\Rule\ManagementReview\ReviewIntervalOverdueRule;
use App\Entity\ManagementReview;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ReviewIntervalOverdueRuleTest extends TestCase
{
    private ReviewIntervalOverdueRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new ReviewIntervalOverdueRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenLastReviewWasMoreThanTwelveMonthsAgo(): void
    {
        $review = $this->createMock(ManagementReview::class);
        $review->method('getReviewDate')->willReturn(new DateTimeImmutable('-14 months'));
        $review->method('getDaysSinceReview')->willReturn(420);

        $this->assertTrue($this->rule->appliesTo($review, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenReviewIsRecent(): void
    {
        $review = $this->createMock(ManagementReview::class);
        $review->method('getReviewDate')->willReturn(new DateTimeImmutable('-6 months'));

        $this->assertFalse($this->rule->appliesTo($review, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenReviewDateIsNull(): void
    {
        $review = $this->createMock(ManagementReview::class);
        $review->method('getReviewDate')->willReturn(null);

        $this->assertFalse($this->rule->appliesTo($review, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonManagementReviewEntity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsHintWithCorrectKey(): void
    {
        $review = $this->createMock(ManagementReview::class);
        $review->method('getId')->willReturn(11);
        $review->method('getDaysSinceReview')->willReturn(420);

        $hint = $this->rule->build($review, $this->user);
        $this->assertSame('management_review.interval_overdue', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertTrue($hint->dismissible);
    }

    #[Test]
    public function moduleGateIsReviews(): void
    {
        $this->assertSame(['reviews'], $this->rule->requiredModules());
    }
}
