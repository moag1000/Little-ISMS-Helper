<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Effectiveness-Review extension on {@see Document} (Auditor MINOR-NC
 * reply, 2026-05-10).
 *
 * The tests lock in the four-axis contract:
 *  1. round-trip — getter/setter parity for the three new columns
 *  2. getEffectivenessAge — NULL when never reviewed, \DateInterval
 *     otherwise
 *  3. isEffectivenessOverdue — NULL review treated as worst possible
 *     age (always overdue); reviewed-within-window NOT overdue;
 *     reviewed-past-window overdue
 *  4. defensive — non-positive cadence yields NOT-overdue (no
 *     "0-month interval ⇒ everything overdue" footgun)
 */
final class DocumentEffectivenessTest extends TestCase
{
    #[Test]
    public function testRoundTripGettersAndSetters(): void
    {
        $doc = new Document();
        $reviewedAt = new DateTimeImmutable('2026-04-15 10:00:00');
        $user = new User();
        $user->setEmail('isb@example.com');
        $user->setFirstName('Isabel');
        $user->setLastName('ISB');
        $notes = "Q1/2026 sample audit: 3 of 5 controls verified, 1 control needs rework.";

        $doc->setLastEffectivenessReviewAt($reviewedAt);
        $doc->setLastEffectivenessReviewBy($user);
        $doc->setEffectivenessReviewNotes($notes);

        self::assertSame($reviewedAt, $doc->getLastEffectivenessReviewAt());
        self::assertSame($user, $doc->getLastEffectivenessReviewBy());
        self::assertSame($notes, $doc->getEffectivenessReviewNotes());
    }

    #[Test]
    public function testEffectivenessAgeNullWhenNeverReviewed(): void
    {
        $doc = new Document();
        self::assertNull($doc->getEffectivenessAge());
    }

    #[Test]
    public function testEffectivenessAgeReturnsInterval(): void
    {
        $doc = new Document();
        $doc->setLastEffectivenessReviewAt(new DateTimeImmutable('-3 months'));

        $age = $doc->getEffectivenessAge();
        self::assertInstanceOf(\DateInterval::class, $age);
        // 3 months = 0y 3m or 0y 2m 30d depending on calendar — assert
        // months >= 2 to keep the test calendar-agnostic.
        self::assertGreaterThanOrEqual(2, $age->y * 12 + $age->m);
    }

    #[Test]
    public function testIsOverdueWhenNeverReviewed(): void
    {
        $doc = new Document();
        // Never reviewed — overdue at any positive cadence.
        self::assertTrue($doc->isEffectivenessOverdue(12));
        self::assertTrue($doc->isEffectivenessOverdue(1));
    }

    #[Test]
    public function testIsNotOverdueWhenWithinWindow(): void
    {
        $doc = new Document();
        $doc->setLastEffectivenessReviewAt(new DateTimeImmutable('-3 months'));

        // 12-month cadence, last review 3 months ago — not overdue.
        self::assertFalse($doc->isEffectivenessOverdue(12));
    }

    #[Test]
    public function testIsOverdueWhenPastWindow(): void
    {
        $doc = new Document();
        $doc->setLastEffectivenessReviewAt(new DateTimeImmutable('-13 months'));

        // 12-month cadence, last review 13 months ago — overdue.
        self::assertTrue($doc->isEffectivenessOverdue(12));
    }

    #[Test]
    public function testIsNotOverdueOnNonPositiveCadence(): void
    {
        // Defensive: a zero or negative cadence is meaningless and
        // must not flip every document into "overdue" by accident.
        $doc = new Document();
        $doc->setLastEffectivenessReviewAt(new DateTimeImmutable('-5 years'));
        self::assertFalse($doc->isEffectivenessOverdue(0));
        self::assertFalse($doc->isEffectivenessOverdue(-1));
    }

    #[Test]
    public function testNotesCanBeClearedToNull(): void
    {
        $doc = new Document();
        $doc->setEffectivenessReviewNotes('something');
        $doc->setEffectivenessReviewNotes(null);

        self::assertNull($doc->getEffectivenessReviewNotes());
    }
}
