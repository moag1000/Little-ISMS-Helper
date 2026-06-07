<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Person;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PersonCapacityTest extends TestCase
{
    #[Test]
    public function testDefaultIsmsAvailabilityPctIsOne(): void
    {
        $person = new Person();

        $this->assertEqualsWithDelta(1.0, $person->getIsmsAvailabilityPct(), 0.0001);
    }

    #[Test]
    public function testSetIsmsAvailabilityPct(): void
    {
        $person = new Person();

        $person->setIsmsAvailabilityPct(0.4);
        $this->assertEqualsWithDelta(0.4, $person->getIsmsAvailabilityPct(), 0.0001);
    }

    #[Test]
    public function testGetBaselinePtPerWeekWithReducedAvailability(): void
    {
        $person = new Person();
        $person->setIsmsAvailabilityPct(0.4);

        // 0.4 * 5.0 = 2.0
        $this->assertEqualsWithDelta(2.0, $person->getBaselinePtPerWeek(5.0), 0.0001);
    }

    #[Test]
    public function testGetBaselinePtPerWeekWithDefaultAvailability(): void
    {
        $person = new Person();

        // default 1.0 * 5.0 = 5.0
        $this->assertEqualsWithDelta(5.0, $person->getBaselinePtPerWeek(5.0), 0.0001);
    }
}
