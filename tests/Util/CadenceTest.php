<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\Cadence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CadenceTest extends TestCase
{
    #[Test]
    public function storedCadenceWordsBecomeMonths(): void
    {
        self::assertSame(1, Cadence::months('monthly'));
        self::assertSame(3, Cadence::months('quarterly'));
        self::assertSame(6, Cadence::months('biannually'));
        self::assertSame(12, Cadence::months('annual'));
        self::assertSame(12, Cadence::months('annually'));
        self::assertSame(24, Cadence::months('biennial'));
    }

    #[Test]
    public function cadencesWithoutAMonthIntervalAreNoCalendarDuty(): void
    {
        foreach (['daily', 'weekly', 'as_needed', 'on_event', '', null] as $cadence) {
            self::assertNull(Cadence::months($cadence));
        }
    }
}
