<?php

namespace App\Tests\Entity;

use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\Document;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

class BCExerciseTest extends TestCase
{
    public function testConstructor(): void
    {
        $exercise = new BCExercise();

        $this->assertNotNull($exercise->getCreatedAt());
        $this->assertInstanceOf(\DateTime::class, $exercise->getCreatedAt());
        $this->assertEquals(0, $exercise->getTestedPlans()->count());
        $this->assertEquals(0, $exercise->getDocuments()->count());
    }

    public function testGettersAndSetters(): void
    {
        $exercise = new BCExercise();

        $exercise->setName('Q1 BC Exercise');
        $this->assertEquals('Q1 BC Exercise', $exercise->getName());

        $exercise->setDescription('Annual business continuity exercise');
        $this->assertEquals('Annual business continuity exercise', $exercise->getDescription());

        $exercise->setScope('IT systems and data recovery');
        $this->assertEquals('IT systems and data recovery', $exercise->getScope());

        $exercise->setObjectives('Test RTO and RPO compliance');
        $this->assertEquals('Test RTO and RPO compliance', $exercise->getObjectives());

        $exercise->setScenario('Major data center outage');
        $this->assertEquals('Major data center outage', $exercise->getScenario());

        $exercise->setParticipants('IT team, management, crisis team');
        $this->assertEquals('IT team, management, crisis team', $exercise->getParticipants());

        $exercise->setFacilitator('John Doe');
        $this->assertEquals('John Doe', $exercise->getFacilitator());

        $exercise->setObservers('External auditor');
        $this->assertEquals('External auditor', $exercise->getObservers());

        $exercise->setResults('All systems recovered within RTO');
        $this->assertEquals('All systems recovered within RTO', $exercise->getResults());

        $exercise->setWhatWentWell('Communication was effective');
        $this->assertEquals('Communication was effective', $exercise->getWhatWentWell());

        $exercise->setAreasForImprovement('Documentation needs update');
        $this->assertEquals('Documentation needs update', $exercise->getAreasForImprovement());

        $exercise->setFindings('Network failover delay identified');
        $this->assertEquals('Network failover delay identified', $exercise->getFindings());

        $exercise->setActionItems('Update network procedures');
        $this->assertEquals('Update network procedures', $exercise->getActionItems());

        $exercise->setLessonsLearned('Regular testing is crucial');
        $this->assertEquals('Regular testing is crucial', $exercise->getLessonsLearned());

        $exercise->setPlanUpdatesRequired('Update RTO values');
        $this->assertEquals('Update RTO values', $exercise->getPlanUpdatesRequired());
    }

    public function testExerciseTypeGetterAndSetter(): void
    {
        $exercise = new BCExercise();

        // Default value
        $this->assertEquals('tabletop', $exercise->getExerciseType());

        $exercise->setExerciseType('simulation');
        $this->assertEquals('simulation', $exercise->getExerciseType());

        $exercise->setExerciseType('full_test');
        $this->assertEquals('full_test', $exercise->getExerciseType());
    }

    public function testStatusGetterAndSetter(): void
    {
        $exercise = new BCExercise();

        // Default value
        $this->assertEquals('planned', $exercise->getStatus());

        $exercise->setStatus('in_progress');
        $this->assertEquals('in_progress', $exercise->getStatus());

        $exercise->setStatus('completed');
        $this->assertEquals('completed', $exercise->getStatus());

        $exercise->setStatus('cancelled');
        $this->assertEquals('cancelled', $exercise->getStatus());
    }

    public function testDurationHoursGetterAndSetter(): void
    {
        $exercise = new BCExercise();

        $this->assertNull($exercise->getDurationHours());

        $exercise->setDurationHours(4);
        $this->assertEquals(4, $exercise->getDurationHours());
    }

    public function testSuccessCriteriaGetterAndSetter(): void
    {
        $exercise = new BCExercise();

        $this->assertNull($exercise->getSuccessCriteria());

        $criteria = [
            'RTO_met' => true,
            'RPO_met' => true,
            'communication_effective' => false,
            'team_prepared' => true,
        ];

        $exercise->setSuccessCriteria($criteria);
        $this->assertEquals($criteria, $exercise->getSuccessCriteria());
    }

    public function testSuccessRatingGetterAndSetter(): void
    {
        $exercise = new BCExercise();

        $this->assertNull($exercise->getSuccessRating());

        $exercise->setSuccessRating(4);
        $this->assertEquals(4, $exercise->getSuccessRating());
    }

    public function testReportCompletedGetterAndSetter(): void
    {
        $exercise = new BCExercise();

        // Default value
        $this->assertFalse($exercise->isReportCompleted());

        $exercise->setReportCompleted(true);
        $this->assertTrue($exercise->isReportCompleted());
    }

    public function testExerciseDateGetterAndSetter(): void
    {
        $exercise = new BCExercise();

        $this->assertNull($exercise->getExerciseDate());

        $date = new \DateTime('2024-06-15');
        $exercise->setExerciseDate($date);
        $this->assertEquals($date, $exercise->getExerciseDate());
    }

    public function testReportDateGetterAndSetter(): void
    {
        $exercise = new BCExercise();

        $this->assertNull($exercise->getReportDate());

        $date = new \DateTime('2024-06-20');
        $exercise->setReportDate($date);
        $this->assertEquals($date, $exercise->getReportDate());
    }

    public function testTenantRelationship(): void
    {
        $exercise = new BCExercise();
        $tenant = new Tenant();
        $tenant->setName('Test Tenant');

        $this->assertNull($exercise->getTenant());

        $exercise->setTenant($tenant);
        $this->assertSame($tenant, $exercise->getTenant());
    }

    public function testAddAndRemoveTestedPlan(): void
    {
        $exercise = new BCExercise();
        $plan = new BusinessContinuityPlan();

        $this->assertEquals(0, $exercise->getTestedPlans()->count());

        $exercise->addTestedPlan($plan);
        $this->assertEquals(1, $exercise->getTestedPlans()->count());
        $this->assertTrue($exercise->getTestedPlans()->contains($plan));

        // Adding the same plan again should not increase count
        $exercise->addTestedPlan($plan);
        $this->assertEquals(1, $exercise->getTestedPlans()->count());

        $exercise->removeTestedPlan($plan);
        $this->assertEquals(0, $exercise->getTestedPlans()->count());
        $this->assertFalse($exercise->getTestedPlans()->contains($plan));
    }

    public function testAddAndRemoveDocument(): void
    {
        $exercise = new BCExercise();
        $document = new Document();

        $this->assertEquals(0, $exercise->getDocuments()->count());

        $exercise->addDocument($document);
        $this->assertEquals(1, $exercise->getDocuments()->count());
        $this->assertTrue($exercise->getDocuments()->contains($document));

        // Adding the same document again should not increase count
        $exercise->addDocument($document);
        $this->assertEquals(1, $exercise->getDocuments()->count());

        $exercise->removeDocument($document);
        $this->assertEquals(0, $exercise->getDocuments()->count());
        $this->assertFalse($exercise->getDocuments()->contains($document));
    }

    public function testTimestamps(): void
    {
        $exercise = new BCExercise();

        $this->assertNotNull($exercise->getCreatedAt());
        $this->assertNull($exercise->getUpdatedAt());

        $createdAt = new \DateTime('2024-01-01 10:00:00');
        $exercise->setCreatedAt($createdAt);
        $this->assertEquals($createdAt, $exercise->getCreatedAt());

        $updatedAt = new \DateTime('2024-01-02 15:00:00');
        $exercise->setUpdatedAt($updatedAt);
        $this->assertEquals($updatedAt, $exercise->getUpdatedAt());
    }

    public function testUpdateTimestamps(): void
    {
        $exercise = new BCExercise();
        $originalCreatedAt = $exercise->getCreatedAt();

        $exercise->updateTimestamps();

        $this->assertNotNull($exercise->getCreatedAt());
        $this->assertNotNull($exercise->getUpdatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $exercise->getUpdatedAt());
    }

    public function testIsFullyComplete(): void
    {
        $exercise = new BCExercise();

        // Not fully complete by default
        $this->assertFalse($exercise->isFullyComplete());

        // Complete status but no report
        $exercise->setStatus('completed');
        $this->assertFalse($exercise->isFullyComplete());

        // Report completed but not marked as completed
        $exercise->setStatus('planned');
        $exercise->setReportCompleted(true);
        $this->assertFalse($exercise->isFullyComplete());

        // Both status completed and report completed
        $exercise->setStatus('completed');
        $exercise->setReportCompleted(true);
        $this->assertTrue($exercise->isFullyComplete());
    }

    public function testGetSuccessPercentage(): void
    {
        $exercise = new BCExercise();

        // No criteria set
        $this->assertEquals(0, $exercise->getSuccessPercentage());

        // All criteria met
        $criteria = [
            'RTO_met' => true,
            'RPO_met' => true,
            'communication_effective' => true,
            'team_prepared' => true,
        ];
        $exercise->setSuccessCriteria($criteria);
        $this->assertEquals(100, $exercise->getSuccessPercentage());

        // Half criteria met
        $criteria = [
            'RTO_met' => true,
            'RPO_met' => true,
            'communication_effective' => false,
            'team_prepared' => false,
        ];
        $exercise->setSuccessCriteria($criteria);
        $this->assertEquals(50, $exercise->getSuccessPercentage());

        // One out of three criteria met
        $criteria = [
            'RTO_met' => true,
            'RPO_met' => false,
            'communication_effective' => false,
        ];
        $exercise->setSuccessCriteria($criteria);
        $this->assertEquals(33, $exercise->getSuccessPercentage());

        // None met
        $criteria = [
            'RTO_met' => false,
            'RPO_met' => false,
        ];
        $exercise->setSuccessCriteria($criteria);
        $this->assertEquals(0, $exercise->getSuccessPercentage());
    }

    public function testGetEffectivenessScore(): void
    {
        $exercise = new BCExercise();

        // No data - score is 0
        $this->assertEquals(0, $exercise->getEffectivenessScore());

        // Perfect score scenario
        $exercise->setSuccessRating(5); // 40%
        $exercise->setSuccessCriteria([
            'RTO_met' => true,
            'RPO_met' => true,
            'communication_effective' => true,
            'team_prepared' => true,
        ]); // 30%
        $exercise->setReportCompleted(true); // 20%
        $exercise->setActionItems('Update procedures'); // 10%

        // Total should be 40 + 30 + 20 + 10 = 100
        $this->assertEquals(100, $exercise->getEffectivenessScore());

        // Partial score scenario
        $exercise2 = new BCExercise();
        $exercise2->setSuccessRating(3); // (3/5) * 40 = 24
        $exercise2->setSuccessCriteria([
            'RTO_met' => true,
            'RPO_met' => false,
        ]); // 50% * 0.3 = 15
        $exercise2->setReportCompleted(false); // 0
        $exercise2->setActionItems(null); // 0

        // Total should be 24 + 15 = 39
        $this->assertEquals(39, $exercise2->getEffectivenessScore());
    }

    public function testGetExerciseTypeDescription(): void
    {
        $exercise = new BCExercise();

        $exercise->setExerciseType('tabletop');
        $this->assertEquals('Tabletop Exercise (Discussion-based)', $exercise->getExerciseTypeDescription());

        $exercise->setExerciseType('walkthrough');
        $this->assertEquals('Walkthrough (Step-by-step review)', $exercise->getExerciseTypeDescription());

        $exercise->setExerciseType('simulation');
        $this->assertEquals('Simulation (Simulated incident)', $exercise->getExerciseTypeDescription());

        $exercise->setExerciseType('full_test');
        $this->assertEquals('Full Test (Complete activation)', $exercise->getExerciseTypeDescription());

        $exercise->setExerciseType('component_test');
        $this->assertEquals('Component Test (Specific component)', $exercise->getExerciseTypeDescription());

        $exercise->setExerciseType('unknown_type');
        $this->assertEquals('Unknown', $exercise->getExerciseTypeDescription());
    }
}
