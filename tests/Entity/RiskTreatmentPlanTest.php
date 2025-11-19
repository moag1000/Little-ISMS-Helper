<?php

namespace App\Tests\Entity;

use App\Entity\Control;
use App\Entity\Risk;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class RiskTreatmentPlanTest extends TestCase
{
    public function testConstructor(): void
    {
        $plan = new RiskTreatmentPlan();

        $this->assertNotNull($plan->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $plan->getCreatedAt());
        $this->assertEquals(0, $plan->getControls()->count());
        $this->assertEquals('planned', $plan->getStatus());
        $this->assertEquals('medium', $plan->getPriority());
        $this->assertEquals(0, $plan->getCompletionPercentage());
    }

    public function testBasicGettersAndSetters(): void
    {
        $plan = new RiskTreatmentPlan();

        $plan->setTitle('Implement encryption');
        $this->assertEquals('Implement encryption', $plan->getTitle());

        $plan->setDescription('Implement AES-256 encryption for data at rest');
        $this->assertEquals('Implement AES-256 encryption for data at rest', $plan->getDescription());

        $plan->setStatus('in_progress');
        $this->assertEquals('in_progress', $plan->getStatus());

        $plan->setPriority('critical');
        $this->assertEquals('critical', $plan->getPriority());
    }

    public function testRelationships(): void
    {
        $plan = new RiskTreatmentPlan();
        $risk = new Risk();
        $tenant = new Tenant();
        $user = new User();

        $plan->setRisk($risk);
        $this->assertSame($risk, $plan->getRisk());

        $plan->setTenant($tenant);
        $this->assertSame($tenant, $plan->getTenant());

        $plan->setResponsiblePerson($user);
        $this->assertSame($user, $plan->getResponsiblePerson());
    }

    public function testDates(): void
    {
        $plan = new RiskTreatmentPlan();

        $startDate = new \DateTime('2024-01-01');
        $plan->setStartDate($startDate);
        $this->assertEquals($startDate, $plan->getStartDate());

        $targetDate = new \DateTime('2024-06-30');
        $plan->setTargetCompletionDate($targetDate);
        $this->assertEquals($targetDate, $plan->getTargetCompletionDate());

        $actualDate = new \DateTime('2024-06-15');
        $plan->setActualCompletionDate($actualDate);
        $this->assertEquals($actualDate, $plan->getActualCompletionDate());
    }

    public function testBudget(): void
    {
        $plan = new RiskTreatmentPlan();

        $this->assertNull($plan->getBudget());

        $plan->setBudget('50000.00');
        $this->assertEquals('50000.00', $plan->getBudget());
    }

    public function testAddAndRemoveControl(): void
    {
        $plan = new RiskTreatmentPlan();
        $control = new Control();

        $this->assertEquals(0, $plan->getControls()->count());
        $this->assertEquals(0, $plan->getControlCount());

        $plan->addControl($control);
        $this->assertEquals(1, $plan->getControls()->count());
        $this->assertTrue($plan->getControls()->contains($control));
        $this->assertEquals(1, $plan->getControlCount());

        $plan->removeControl($control);
        $this->assertEquals(0, $plan->getControls()->count());
    }

    public function testImplementationNotes(): void
    {
        $plan = new RiskTreatmentPlan();

        $this->assertNull($plan->getImplementationNotes());

        $plan->setImplementationNotes('Phase 1 completed successfully');
        $this->assertEquals('Phase 1 completed successfully', $plan->getImplementationNotes());
    }

    public function testCompletionPercentage(): void
    {
        $plan = new RiskTreatmentPlan();

        $this->assertEquals(0, $plan->getCompletionPercentage());

        $plan->setCompletionPercentage(75);
        $this->assertEquals(75, $plan->getCompletionPercentage());
    }

    public function testIsOverdueWhenCompleted(): void
    {
        $plan = new RiskTreatmentPlan();
        $pastDate = (new \DateTime())->modify('-5 days');
        $plan->setTargetCompletionDate($pastDate);
        $plan->setStatus('completed');

        $this->assertFalse($plan->isOverdue());
    }

    public function testIsOverdueWhenCancelled(): void
    {
        $plan = new RiskTreatmentPlan();
        $pastDate = (new \DateTime())->modify('-5 days');
        $plan->setTargetCompletionDate($pastDate);
        $plan->setStatus('cancelled');

        $this->assertFalse($plan->isOverdue());
    }

    public function testIsOverdueWithPastTarget(): void
    {
        $plan = new RiskTreatmentPlan();
        $pastDate = (new \DateTime())->modify('-5 days');
        $plan->setTargetCompletionDate($pastDate);
        $plan->setStatus('in_progress');

        $this->assertTrue($plan->isOverdue());
    }

    public function testIsOverdueWithFutureTarget(): void
    {
        $plan = new RiskTreatmentPlan();
        $futureDate = (new \DateTime())->modify('+10 days');
        $plan->setTargetCompletionDate($futureDate);
        $plan->setStatus('planned');

        $this->assertFalse($plan->isOverdue());
    }

    public function testGetDaysUntilTargetWithFutureDate(): void
    {
        $plan = new RiskTreatmentPlan();
        $futureDate = (new \DateTime())->modify('+10 days');
        $plan->setTargetCompletionDate($futureDate);

        $days = $plan->getDaysUntilTarget();
        $this->assertGreaterThanOrEqual(9, $days);
        $this->assertLessThanOrEqual(10, $days);
    }

    public function testGetDaysUntilTargetWithPastDate(): void
    {
        $plan = new RiskTreatmentPlan();
        $pastDate = (new \DateTime())->modify('-5 days');
        $plan->setTargetCompletionDate($pastDate);

        $days = $plan->getDaysUntilTarget();
        $this->assertLessThanOrEqual(-4, $days);
        $this->assertGreaterThanOrEqual(-5, $days);
    }

    public function testIsOnTrackWhenCompleted(): void
    {
        $plan = new RiskTreatmentPlan();
        $plan->setStatus('completed');

        $this->assertTrue($plan->isOnTrack());
    }

    public function testIsOnTrackWhenNotStarted(): void
    {
        $plan = new RiskTreatmentPlan();
        $plan->setTargetCompletionDate(new \DateTime('+30 days'));

        $this->assertTrue($plan->isOnTrack());
    }

    public function testIsOnTrackWithGoodProgress(): void
    {
        $plan = new RiskTreatmentPlan();
        $plan->setStartDate(new \DateTime('-10 days'));
        $plan->setTargetCompletionDate(new \DateTime('+10 days'));
        $plan->setCompletionPercentage(50);

        // 10 days elapsed out of 20 total = 50% expected, we have 50%
        $this->assertTrue($plan->isOnTrack());
    }

    public function testHasStartedWhenNotStarted(): void
    {
        $plan = new RiskTreatmentPlan();

        $this->assertFalse($plan->hasStarted());

        $futureDate = (new \DateTime())->modify('+5 days');
        $plan->setStartDate($futureDate);
        $this->assertFalse($plan->hasStarted());
    }

    public function testHasStartedWhenStarted(): void
    {
        $plan = new RiskTreatmentPlan();
        $pastDate = (new \DateTime())->modify('-5 days');
        $plan->setStartDate($pastDate);

        $this->assertTrue($plan->hasStarted());
    }

    public function testIsCompleteWhenNotCompleted(): void
    {
        $plan = new RiskTreatmentPlan();
        $plan->setStatus('in_progress');

        $this->assertFalse($plan->isComplete());
    }

    public function testIsCompleteWhenCompletedButNoDate(): void
    {
        $plan = new RiskTreatmentPlan();
        $plan->setStatus('completed');

        $this->assertFalse($plan->isComplete());
    }

    public function testIsCompleteWhenFullyCompleted(): void
    {
        $plan = new RiskTreatmentPlan();
        $plan->setStatus('completed');
        $plan->setActualCompletionDate(new \DateTime());

        $this->assertTrue($plan->isComplete());
    }

    public function testGetResponsiblePersonName(): void
    {
        $plan = new RiskTreatmentPlan();

        $this->assertNull($plan->getResponsiblePersonName());

        $user = new User();
        $user->setFirstName('Jane');
        $user->setLastName('Doe');
        $plan->setResponsiblePerson($user);

        $this->assertEquals('Jane Doe', $plan->getResponsiblePersonName());
    }

    public function testTimestamps(): void
    {
        $plan = new RiskTreatmentPlan();

        // createdAt set in constructor
        $this->assertNotNull($plan->getCreatedAt());

        // updatedAt initially null
        $this->assertNull($plan->getUpdatedAt());

        $now = new \DateTime();
        $plan->setUpdatedAt($now);
        $this->assertEquals($now, $plan->getUpdatedAt());
    }
}
