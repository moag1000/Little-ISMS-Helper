<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline\Source;

use App\Entity\ISMSObjective;
use App\Entity\Person;
use App\Service\Deadline\Source\ObjectiveMeasurementSource;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ISO 27001 6.2 b / 9.1: every objective measured by hand has its own next
 * measurement date and reaches the person who measures.
 */
final class ObjectiveMeasurementSourceTest extends TestCase
{
    private function source(): ObjectiveMeasurementSource
    {
        return new ObjectiveMeasurementSource($this->createStub(EntityManagerInterface::class));
    }

    #[Test]
    public function nextMeasurementIsOneCadenceAfterTheLast(): void
    {
        $objective = (new ISMSObjective())->setMeasurementFrequency('quarterly')
            ->setLastMeasuredAt(new DateTimeImmutable('2026-01-31'));

        self::assertSame('2026-04-30', $this->source()->dueDateOf($objective)?->format('Y-m-d'));
    }

    #[Test]
    public function aChangedValueCountsAsMeasurementToday(): void
    {
        $objective = (new ISMSObjective())->setMeasurementFrequency('monthly');
        $objective->setCurrentValue('80');

        self::assertSame((new DateTimeImmutable('today'))->format('Y-m-d'), $objective->getLastMeasuredAt()?->format('Y-m-d'));

        $objective->setLastMeasuredAt(new DateTimeImmutable('2026-01-01'));
        $objective->setCurrentValue('80');
        self::assertSame('2026-01-01', $objective->getLastMeasuredAt()?->format('Y-m-d'), 'same value is no new measurement');
    }

    #[Test]
    public function kpiLinkedFinishedAndSubMonthlyObjectivesCarryNoDuty(): void
    {
        $base = static fn (): ISMSObjective => (new ISMSObjective())->setMeasurementFrequency('monthly')
            ->setLastMeasuredAt(new DateTimeImmutable('2026-01-01'));

        self::assertNull($this->source()->dueDateOf($base()->setKpiKey('control_compliance')), 'the tool measures');
        self::assertNull($this->source()->dueDateOf($base()->setStatus('achieved')));
        self::assertNull($this->source()->dueDateOf($base()->setMeasurementFrequency('weekly')));
        self::assertNull($this->source()->dueDateOf($base()->setMeasurementFrequency(null)));
    }

    #[Test]
    public function theMeasuringPersonIsResponsible(): void
    {
        $person = (new Person())->setFullName('Mia Messung');
        $objective = (new ISMSObjective())->setMeasurementPerson($person);

        self::assertSame([$person], $this->source()->responsibleOf($objective)->holders);
        self::assertSame('Mia Messung', $objective->getEffectiveResponsibleForMeasurement());
    }
}
