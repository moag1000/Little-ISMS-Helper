<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Document\ReviewOverdueRule;
use App\Entity\Document;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DocumentReviewOverdueRuleTest extends TestCase
{
    private ReviewOverdueRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new ReviewOverdueRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForApprovedDocumentWithStaleUpdateAndNoNextReview(): void
    {
        $doc = $this->createMock(Document::class);
        $doc->method('getStatusEnum')->willReturn(\App\Enum\DocumentStatus::Approved);
        $doc->method('getNextReviewDate')->willReturn(null);
        $doc->method('getUpdatedAt')->willReturn(new DateTimeImmutable('-8 months'));

        $this->assertTrue($this->rule->appliesTo($doc, $this->user));
    }

    #[Test]
    public function appliesWhenNextReviewIsOverdue(): void
    {
        $doc = $this->createMock(Document::class);
        $doc->method('getStatusEnum')->willReturn(\App\Enum\DocumentStatus::Published);
        $doc->method('getNextReviewDate')->willReturn(new DateTimeImmutable('-1 month'));
        $doc->method('getUpdatedAt')->willReturn(new DateTimeImmutable('-8 months'));

        $this->assertTrue($this->rule->appliesTo($doc, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenNextReviewIsFuture(): void
    {
        $doc = $this->createMock(Document::class);
        $doc->method('getStatusEnum')->willReturn(\App\Enum\DocumentStatus::Approved);
        $doc->method('getNextReviewDate')->willReturn(new DateTimeImmutable('+3 months'));

        $this->assertFalse($this->rule->appliesTo($doc, $this->user));
    }

    #[Test]
    public function doesNotApplyForDraftDocument(): void
    {
        $doc = $this->createMock(Document::class);
        $doc->method('getStatusEnum')->willReturn(\App\Enum\DocumentStatus::Draft);
        $doc->method('getNextReviewDate')->willReturn(null);

        $this->assertFalse($this->rule->appliesTo($doc, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenRecentlyUpdated(): void
    {
        $doc = $this->createMock(Document::class);
        $doc->method('getStatusEnum')->willReturn(\App\Enum\DocumentStatus::Approved);
        $doc->method('getNextReviewDate')->willReturn(null);
        $doc->method('getUpdatedAt')->willReturn(new DateTimeImmutable('-2 months'));

        $this->assertFalse($this->rule->appliesTo($doc, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonDocumentEntity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsHintWithCorrectKey(): void
    {
        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn(1);
        $doc->method('getStatusEnum')->willReturn(\App\Enum\DocumentStatus::Approved);

        $hint = $this->rule->build($doc, $this->user);
        $this->assertSame('document.review_overdue', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
    }

    #[Test]
    public function moduleGateIsDocuments(): void
    {
        $this->assertSame(['documents'], $this->rule->requiredModules());
    }
}
