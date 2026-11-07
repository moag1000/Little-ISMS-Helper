<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\LegalPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LegalPeriodTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function periods(): iterable
    {
        yield 'mid-month' => ['2026-03-15 08:00', 1, '2026-04-15 08:00'];
        yield 'end of January' => ['2026-01-31 10:15', 1, '2026-02-28 10:15'];
        yield 'leap year' => ['2028-01-31 00:00', 1, '2028-02-29 00:00'];
        yield 'year change' => ['2026-12-31 00:00', 1, '2027-01-31 00:00'];
        yield 'into a 30-day month' => ['2026-08-31 00:00', 1, '2026-09-30 00:00'];
        yield 'backwards' => ['2026-08-31 00:00', -6, '2026-02-28 00:00'];
        yield 'zero' => ['2026-05-31 12:00', 0, '2026-05-31 12:00'];
    }

    #[Test]
    #[DataProvider('periods')]
    public function addsCalendarMonthsWithoutRollingOver(string $from, int $months, string $expected): void
    {
        self::assertSame(
            $expected,
            LegalPeriod::addMonths(new DateTimeImmutable($from), $months)->format('Y-m-d H:i'),
        );
    }
}
