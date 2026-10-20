<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\RiskStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RiskStatus::isHandled() is the single source for "handled vs. still open"
 * used by BusinessProcess (active / unmitigated risks) and the
 * RiskAggregationService portfolio trend.
 */
final class RiskStatusTest extends TestCase
{
    #[Test]
    public function handledCasesAreClosedAcceptedMitigatedTreatedMonitored(): void
    {
        $handled = array_values(array_filter(RiskStatus::cases(), static fn(RiskStatus $s): bool => $s->isHandled()));

        self::assertSame(
            [RiskStatus::Treated, RiskStatus::Mitigated, RiskStatus::Monitored, RiskStatus::Closed, RiskStatus::Accepted],
            $handled,
        );
    }

    #[Test]
    public function inTreatmentAndEarlierStagesAreStillOpen(): void
    {
        foreach ([RiskStatus::Identified, RiskStatus::Assessed, RiskStatus::InTreatment, RiskStatus::Open] as $status) {
            self::assertFalse($status->isHandled(), $status->value);
        }
    }
}
