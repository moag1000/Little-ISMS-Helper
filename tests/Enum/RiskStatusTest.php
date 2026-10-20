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

    /**
     * Every risk but a closed one is re-assessed at planned intervals —
     * accepted (handled) risks included (ISO 27001 8.2, ISO 27005).
     */
    #[Test]
    public function everyStatusButClosedRequiresPeriodicReview(): void
    {
        foreach (RiskStatus::cases() as $status) {
            self::assertSame($status !== RiskStatus::Closed, $status->requiresPeriodicReview(), $status->value);
        }
    }

    #[Test]
    public function periodicReviewValuesAreDerivedFromTheRule(): void
    {
        $expected = array_values(array_map(
            static fn (RiskStatus $s): string => $s->value,
            array_filter(RiskStatus::cases(), static fn (RiskStatus $s): bool => $s->requiresPeriodicReview()),
        ));

        self::assertSame($expected, RiskStatus::periodicReviewValues());
        self::assertNotContains('closed', RiskStatus::periodicReviewValues());
    }
}
