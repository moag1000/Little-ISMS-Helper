<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Risk;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RiskQuantTest extends TestCase
{
    #[Test]
    public function getAnnualLossExpectancyReturnsNullWhenFieldsMissing(): void
    {
        $risk = new Risk();
        $this->assertNull($risk->getAnnualLossExpectancy());
    }

    #[Test]
    public function getAnnualLossExpectancyReturnsNullWhenOnlySleSet(): void
    {
        $risk = new Risk();
        $risk->setSingleLossExpectancy(50000);
        $this->assertNull($risk->getAnnualLossExpectancy());
    }

    #[Test]
    public function getAnnualLossExpectancyReturnsNullWhenOnlyAroSet(): void
    {
        $risk = new Risk();
        $risk->setAnnualRateOfOccurrence(0.5);
        $this->assertNull($risk->getAnnualLossExpectancy());
    }

    #[Test]
    public function getAnnualLossExpectancyReturnsSleTimesAro(): void
    {
        $risk = new Risk();
        $risk->setSingleLossExpectancy(50000);
        $risk->setAnnualRateOfOccurrence(0.5);
        // ALE = 50000 * 0.5 = 25000
        $this->assertSame(25000, $risk->getAnnualLossExpectancy());
    }

    #[Test]
    public function getAnnualLossExpectancyRoundsResult(): void
    {
        $risk = new Risk();
        $risk->setSingleLossExpectancy(100000);
        $risk->setAnnualRateOfOccurrence(0.333);
        // ALE = 100000 * 0.333 = 33300.0 → 33300
        $this->assertSame(33300, $risk->getAnnualLossExpectancy());
    }

    #[Test]
    public function getAnnualLossExpectancyHandlesLargeValues(): void
    {
        $risk = new Risk();
        $risk->setSingleLossExpectancy(1000000);
        $risk->setAnnualRateOfOccurrence(2.5);
        // ALE = 1000000 * 2.5 = 2500000
        $this->assertSame(2500000, $risk->getAnnualLossExpectancy());
    }
}
