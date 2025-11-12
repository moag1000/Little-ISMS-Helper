<?php

namespace App\Tests\Entity;

use App\Entity\ISMSObjective;
use PHPUnit\Framework\TestCase;

class ISMSObjectiveTest extends TestCase
{
    public function testNewISMSObjectiveHasDefaultValues(): void
    {
        $objective = new ISMSObjective();

        $this->assertNull($objective->getId());
        $this->assertNull($objective->getTitle());
        $this->assertNull($objective->getDescription());
        $this->assertNull($objective->getCategory());
        $this->assertNull($objective->getMeasurableIndicators());
        $this->assertNull($objective->getTargetValue());
        $this->assertNull($objective->getCurrentValue());
        $this->assertNull($objective->getUnit());
        $this->assertNull($objective->getResponsiblePerson());
        $this->assertNull($objective->getTargetDate());
        $this->assertEquals('in_progress', $objective->getStatus());
        $this->assertNull($objective->getProgressNotes());
        $this->assertNull($objective->getAchievedDate());
        $this->assertInstanceOf(\DateTimeImmutable::class, $objective->getCreatedAt());
        $this->assertNull($objective->getUpdatedAt());
    }

    public function testSetAndGetTitle(): void
    {
        $objective = new ISMSObjective();
        $objective->setTitle('Achieve 99.9% system availability');

        $this->assertEquals('Achieve 99.9% system availability', $objective->getTitle());
    }

    public function testSetAndGetDescription(): void
    {
        $objective = new ISMSObjective();
        $description = 'Maintain critical systems with minimal downtime throughout the year';

        $objective->setDescription($description);

        $this->assertEquals($description, $objective->getDescription());
    }

    public function testSetAndGetCategory(): void
    {
        $objective = new ISMSObjective();
        $objective->setCategory('availability');

        $this->assertEquals('availability', $objective->getCategory());
    }

    public function testSetAndGetMeasurableIndicators(): void
    {
        $objective = new ISMSObjective();
        $indicators = 'System uptime percentage, incident response time, MTTR';

        $objective->setMeasurableIndicators($indicators);

        $this->assertEquals($indicators, $objective->getMeasurableIndicators());
    }

    public function testSetAndGetTargetValue(): void
    {
        $objective = new ISMSObjective();
        $objective->setTargetValue('99.9');

        $this->assertEquals('99.9', $objective->getTargetValue());
    }

    public function testSetAndGetCurrentValue(): void
    {
        $objective = new ISMSObjective();
        $objective->setCurrentValue('98.5');

        $this->assertEquals('98.5', $objective->getCurrentValue());
    }

    public function testSetAndGetUnit(): void
    {
        $objective = new ISMSObjective();
        $objective->setUnit('percent');

        $this->assertEquals('percent', $objective->getUnit());
    }

    public function testSetAndGetResponsiblePerson(): void
    {
        $objective = new ISMSObjective();
        $objective->setResponsiblePerson('IT Operations Manager');

        $this->assertEquals('IT Operations Manager', $objective->getResponsiblePerson());
    }

    public function testSetAndGetTargetDate(): void
    {
        $objective = new ISMSObjective();
        $date = new \DateTime('2024-12-31');

        $objective->setTargetDate($date);

        $this->assertEquals($date, $objective->getTargetDate());
    }

    public function testSetAndGetStatus(): void
    {
        $objective = new ISMSObjective();

        $objective->setStatus('not_started');
        $this->assertEquals('not_started', $objective->getStatus());

        $objective->setStatus('achieved');
        $this->assertEquals('achieved', $objective->getStatus());

        $objective->setStatus('delayed');
        $this->assertEquals('delayed', $objective->getStatus());

        $objective->setStatus('cancelled');
        $this->assertEquals('cancelled', $objective->getStatus());
    }

    public function testSetAndGetProgressNotes(): void
    {
        $objective = new ISMSObjective();
        $notes = 'Q1: Achieved 98.2%. Q2: Improved to 98.7%. On track for target.';

        $objective->setProgressNotes($notes);

        $this->assertEquals($notes, $objective->getProgressNotes());
    }

    public function testSetAndGetAchievedDate(): void
    {
        $objective = new ISMSObjective();
        $date = new \DateTime('2024-11-15');

        $objective->setAchievedDate($date);

        $this->assertEquals($date, $objective->getAchievedDate());
    }

    public function testSetAndGetCreatedAt(): void
    {
        $objective = new ISMSObjective();
        $date = new \DateTime('2024-01-01');

        $objective->setCreatedAt($date);

        $this->assertEquals($date, $objective->getCreatedAt());
    }

    public function testSetAndGetUpdatedAt(): void
    {
        $objective = new ISMSObjective();
        $date = new \DateTime('2024-06-15');

        $objective->setUpdatedAt($date);

        $this->assertEquals($date, $objective->getUpdatedAt());
    }

    public function testGetProgressPercentageReturnsZeroWhenNoValues(): void
    {
        $objective = new ISMSObjective();

        $this->assertEquals(0, $objective->getProgressPercentage());
    }

    public function testGetProgressPercentageReturnsZeroWhenTargetIsZero(): void
    {
        $objective = new ISMSObjective();
        $objective->setTargetValue('0');
        $objective->setCurrentValue('10');

        $this->assertEquals(0, $objective->getProgressPercentage());
    }

    public function testGetProgressPercentageCalculatesCorrectly(): void
    {
        $objective = new ISMSObjective();
        $objective->setTargetValue('100');
        $objective->setCurrentValue('75');

        $this->assertEquals(75, $objective->getProgressPercentage());
    }

    public function testGetProgressPercentageCanExceed100(): void
    {
        $objective = new ISMSObjective();
        $objective->setTargetValue('100');
        $objective->setCurrentValue('120');

        $this->assertEquals(120, $objective->getProgressPercentage());
    }

    public function testGetProgressPercentageWorksWithDecimalValues(): void
    {
        $objective = new ISMSObjective();
        $objective->setTargetValue('99.9');
        $objective->setCurrentValue('98.5');

        // 98.5 / 99.9 = 0.9859... = 98%
        $this->assertEquals(98, $objective->getProgressPercentage());
    }

    public function testISMSObjectiveCanTrackCompleteLifecycle(): void
    {
        $objective = new ISMSObjective();

        $objective->setTitle('Reduce incident response time');
        $objective->setDescription('Lower average response time to under 2 hours');
        $objective->setCategory('incident_response');
        $objective->setTargetValue('120'); // 2 hours in minutes
        $objective->setCurrentValue('180'); // Currently 3 hours
        $objective->setUnit('minutes');
        $objective->setResponsiblePerson('Security Team Lead');
        $objective->setTargetDate(new \DateTime('2024-12-31'));
        $objective->setStatus('in_progress');

        $this->assertEquals('Reduce incident response time', $objective->getTitle());
        $this->assertEquals('incident_response', $objective->getCategory());
        $this->assertEquals('minutes', $objective->getUnit());
        $this->assertEquals('in_progress', $objective->getStatus());

        // Progress: 180/120 = 150% (worse than target)
        $this->assertEquals(150, $objective->getProgressPercentage());
    }

    public function testISMSObjectiveAllCategories(): void
    {
        $categories = [
            'availability',
            'confidentiality',
            'integrity',
            'compliance',
            'risk_management',
            'incident_response',
            'awareness',
            'continual_improvement'
        ];

        foreach ($categories as $category) {
            $objective = new ISMSObjective();
            $objective->setCategory($category);
            $this->assertEquals($category, $objective->getCategory());
        }
    }
}
