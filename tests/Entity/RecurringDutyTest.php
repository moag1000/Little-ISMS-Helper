<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Entity\RecurringDuty;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecurringDutyTest extends TestCase
{
    #[Test]
    public function doneMovesTheDueDateByTheIntervalAndKeepsTheEvidence(): void
    {
        $duty = new RecurringDuty(new Tenant(), 'bia_review', 12, new DateTimeImmutable('2026-12-07'));
        $user = new User();
        $evidence = new Document();

        $duty->markDone(new DateTimeImmutable('2026-11-07 15:30'), $user, $evidence, '  Protokoll im DMS  ');

        self::assertSame('2026-11-07', $duty->getLastDoneAt()?->format('Y-m-d'));
        self::assertSame('2027-11-07', $duty->getNextDueAt()->format('Y-m-d'));
        self::assertSame($user, $duty->getLastDoneBy());
        self::assertSame($evidence, $duty->getLastEvidence());
        self::assertSame('Protokoll im DMS', $duty->getLastNote());
    }

    #[Test]
    public function monthEndsAreCountedTheLegalWay(): void
    {
        $duty = new RecurringDuty(new Tenant(), 'access_review_campaign', 1, new DateTimeImmutable('2026-02-01'));

        $duty->markDone(new DateTimeImmutable('2026-01-31'), null);

        self::assertSame('2026-02-28', $duty->getNextDueAt()->format('Y-m-d'));
    }

    #[Test]
    public function aNewIntervalCountsFromTheLastCompletion(): void
    {
        $duty = new RecurringDuty(new Tenant(), 'soa_review', 12, new DateTimeImmutable('2026-12-07'));
        $duty->setIntervalMonths(6);
        self::assertSame('2026-12-07', $duty->getNextDueAt()->format('Y-m-d'), 'never done: due date unchanged');

        $duty->markDone(new DateTimeImmutable('2026-03-01'), null);
        $duty->setIntervalMonths(3);

        self::assertSame('2026-06-01', $duty->getNextDueAt()->format('Y-m-d'));
    }
}
