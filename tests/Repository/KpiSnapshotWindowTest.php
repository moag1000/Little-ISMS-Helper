<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\KpiSnapshotRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the month-window arithmetic behind the analytics trend endpoint.
 *
 * "-6 months" from 2026-08-31 is 2026-02-31, which PHP normalises to
 * 2026-03-03 — so a snapshot dated 2026-03-01 dropped out of the window and
 * /analytics/api/trends returned five months instead of six. The defect only
 * appears on the 29th to 31st of a month following a shorter one, so it stayed
 * invisible until a CI run happened to land on 31 August and turned main red.
 *
 * Testing the boundary directly rather than through the endpoint means it no
 * longer depends on which day the suite runs.
 */
final class KpiSnapshotWindowTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: int, 2: string}>
     */
    public static function boundaries(): array
    {
        return [
            // The day that broke main.
            ['2026-08-31', 6, '2026-03-01'],
            // Other end-of-month days that overflow into the next month.
            ['2026-01-31', 6, '2025-08-01'],
            ['2026-05-30', 6, '2025-12-01'],
            ['2026-03-31', 6, '2025-10-01'],
            // Mid-month, where the naive version happened to be harmless.
            ['2026-06-15', 6, '2026-01-01'],
            // Leap-year end of February.
            ['2028-02-29', 12, '2027-03-01'],
            // Degenerate inputs must not run away.
            ['2026-08-31', 1, '2026-08-01'],
            ['2026-08-31', 0, '2026-08-01'],
        ];
    }

    #[Test]
    #[DataProvider('boundaries')]
    public function windowStartsOnTheFirstOfTheOldestMonth(string $today, int $months, string $expected): void
    {
        $start = KpiSnapshotRepository::windowStart($months, new \DateTimeImmutable($today));

        self::assertSame($expected, $start->format('Y-m-d'));
        self::assertSame('00:00:00', $start->format('H:i:s'), 'the window must start at midnight');
    }

    #[Test]
    public function theWindowSpansExactlyTheRequestedNumberOfMonths(): void
    {
        foreach (['2026-08-31', '2026-01-31', '2026-06-15'] as $today) {
            $now = new \DateTimeImmutable($today);
            $start = KpiSnapshotRepository::windowStart(6, $now);

            $months = ((int) $now->format('Y') * 12 + (int) $now->format('n'))
                - ((int) $start->format('Y') * 12 + (int) $start->format('n'));

            self::assertSame(5, $months, "expected a six-calendar-month window on {$today}");
        }
    }
}
