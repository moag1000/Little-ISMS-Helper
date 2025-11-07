<?php

namespace App\Tests\Entity;

use App\Entity\Control;
use App\Entity\Training;
use PHPUnit\Framework\TestCase;

class TrainingTest extends TestCase
{
    public function testGetControlCoverageCountWithNoControls(): void
    {
        $training = new Training();

        $this->assertEquals(0, $training->getControlCoverageCount());
    }

    public function testGetControlCoverageCountWithMultipleControls(): void
    {
        $training = new Training();

        $control1 = new Control();
        $control2 = new Control();
        $control3 = new Control();

        $training->addCoveredControl($control1);
        $training->addCoveredControl($control2);
        $training->addCoveredControl($control3);

        $this->assertEquals(3, $training->getControlCoverageCount());
    }

    public function testGetTrainingEffectivenessWithNotCompletedStatus(): void
    {
        $training = new Training();
        $training->setStatus('planned');

        $control = new Control();
        $control->setImplementationPercentage(80);
        $training->addCoveredControl($control);

        // Not completed = null
        $this->assertNull($training->getTrainingEffectiveness());
    }

    public function testGetTrainingEffectivenessWithNoControls(): void
    {
        $training = new Training();
        $training->setStatus('completed');

        // Completed but no controls = null
        $this->assertNull($training->getTrainingEffectiveness());
    }

    public function testGetTrainingEffectivenessWithSingleControl(): void
    {
        $training = new Training();
        $training->setStatus('completed');

        $control = new Control();
        $control->setImplementationPercentage(75);
        $training->addCoveredControl($control);

        // Average = 75 / 1 = 75.0
        $this->assertEquals(75.0, $training->getTrainingEffectiveness());
    }

    public function testGetTrainingEffectivenessWithMultipleControls(): void
    {
        $training = new Training();
        $training->setStatus('completed');

        $control1 = new Control();
        $control1->setImplementationPercentage(80);

        $control2 = new Control();
        $control2->setImplementationPercentage(60);

        $control3 = new Control();
        $control3->setImplementationPercentage(100);

        $training->addCoveredControl($control1);
        $training->addCoveredControl($control2);
        $training->addCoveredControl($control3);

        // Average = (80 + 60 + 100) / 3 = 80.0
        $this->assertEquals(80.0, $training->getTrainingEffectiveness());
    }

    public function testGetTrainingEffectivenessWithNullImplementation(): void
    {
        $training = new Training();
        $training->setStatus('completed');

        $control1 = new Control();
        $control1->setImplementationPercentage(100);

        $control2 = new Control();
        // Implementation percentage not set = null, treated as 0

        $training->addCoveredControl($control1);
        $training->addCoveredControl($control2);

        // Average = (100 + 0) / 2 = 50.0
        $this->assertEquals(50.0, $training->getTrainingEffectiveness());
    }

    public function testGetCoveredCategoriesWithNoControls(): void
    {
        $training = new Training();

        $categories = $training->getCoveredCategories();

        $this->assertIsArray($categories);
        $this->assertEmpty($categories);
    }

    public function testGetCoveredCategoriesWithSingleCategory(): void
    {
        $training = new Training();

        $control1 = new Control();
        $control1->setCategory('Access Control');

        $control2 = new Control();
        $control2->setCategory('Access Control');

        $training->addCoveredControl($control1);
        $training->addCoveredControl($control2);

        $categories = $training->getCoveredCategories();

        $this->assertCount(1, $categories);
        $this->assertContains('Access Control', $categories);
    }

    public function testGetCoveredCategoriesWithMultipleCategories(): void
    {
        $training = new Training();

        $control1 = new Control();
        $control1->setCategory('Access Control');

        $control2 = new Control();
        $control2->setCategory('Cryptography');

        $control3 = new Control();
        $control3->setCategory('Physical Security');

        $control4 = new Control();
        $control4->setCategory('Access Control'); // Duplicate

        $training->addCoveredControl($control1);
        $training->addCoveredControl($control2);
        $training->addCoveredControl($control3);
        $training->addCoveredControl($control4);

        $categories = $training->getCoveredCategories();

        // Should have 3 unique categories
        $this->assertCount(3, $categories);
        $this->assertContains('Access Control', $categories);
        $this->assertContains('Cryptography', $categories);
        $this->assertContains('Physical Security', $categories);
    }

    public function testHasCriticalControlsWithNoControls(): void
    {
        $training = new Training();

        // No controls = no critical controls
        $this->assertFalse($training->hasCriticalControls());
    }

    public function testHasCriticalControlsWithFullyImplementedApplicableControls(): void
    {
        $training = new Training();

        $control1 = new Control();
        $control1->setApplicable(true);
        $control1->setImplementationPercentage(100);

        $control2 = new Control();
        $control2->setApplicable(true);
        $control2->setImplementationPercentage(80);

        $training->addCoveredControl($control1);
        $training->addCoveredControl($control2);

        // All applicable and >= 50% implemented = no critical controls
        $this->assertFalse($training->hasCriticalControls());
    }

    public function testHasCriticalControlsWithNonApplicableControl(): void
    {
        $training = new Training();

        $control = new Control();
        $control->setApplicable(false);
        $control->setImplementationPercentage(100);

        $training->addCoveredControl($control);

        // Not applicable = critical control
        $this->assertTrue($training->hasCriticalControls());
    }

    public function testHasCriticalControlsWithLowImplementation(): void
    {
        $training = new Training();

        $control = new Control();
        $control->setApplicable(true);
        $control->setImplementationPercentage(30);

        $training->addCoveredControl($control);

        // < 50% implemented = critical control
        $this->assertTrue($training->hasCriticalControls());
    }

    public function testHasCriticalControlsWithBoundaryCase(): void
    {
        $training = new Training();

        $control1 = new Control();
        $control1->setApplicable(true);
        $control1->setImplementationPercentage(50); // Exactly 50%

        $control2 = new Control();
        $control2->setApplicable(true);
        $control2->setImplementationPercentage(49); // Below 50%

        $training->addCoveredControl($control1);

        // Exactly 50% = NOT critical
        $this->assertFalse($training->hasCriticalControls());

        $training->addCoveredControl($control2);

        // One control at 49% = critical
        $this->assertTrue($training->hasCriticalControls());
    }

    public function testHasCriticalControlsWithMixedControls(): void
    {
        $training = new Training();

        $control1 = new Control();
        $control1->setApplicable(true);
        $control1->setImplementationPercentage(100);

        $control2 = new Control();
        $control2->setApplicable(true);
        $control2->setImplementationPercentage(80);

        $control3 = new Control();
        $control3->setApplicable(true);
        $control3->setImplementationPercentage(20); // Critical

        $training->addCoveredControl($control1);
        $training->addCoveredControl($control2);
        $training->addCoveredControl($control3);

        // At least one critical = true
        $this->assertTrue($training->hasCriticalControls());
    }

    public function testAddAndRemoveCoveredControl(): void
    {
        $training = new Training();
        $control = new Control();

        $this->assertEquals(0, $training->getCoveredControls()->count());

        $training->addCoveredControl($control);
        $this->assertEquals(1, $training->getCoveredControls()->count());
        $this->assertTrue($training->getCoveredControls()->contains($control));

        $training->removeCoveredControl($control);
        $this->assertEquals(0, $training->getCoveredControls()->count());
        $this->assertFalse($training->getCoveredControls()->contains($control));
    }

    public function testTrainingTypeChoices(): void
    {
        $training = new Training();

        // Test valid training types
        $validTypes = ['security_awareness', 'technical', 'compliance', 'role_specific'];

        foreach ($validTypes as $type) {
            $training->setTrainingType($type);
            $this->assertEquals($type, $training->getTrainingType());
        }
    }

    public function testStatusChoices(): void
    {
        $training = new Training();

        // Test valid status values
        $validStatuses = ['planned', 'in_progress', 'completed', 'cancelled'];

        foreach ($validStatuses as $status) {
            $training->setStatus($status);
            $this->assertEquals($status, $training->getStatus());
        }
    }

    public function testDatesHandling(): void
    {
        $training = new Training();

        $scheduledDate = new \DateTime('2024-06-01');
        $completionDate = new \DateTime('2024-06-15');

        $training->setScheduledDate($scheduledDate);
        $training->setCompletionDate($completionDate);

        $this->assertEquals($scheduledDate, $training->getScheduledDate());
        $this->assertEquals($completionDate, $training->getCompletionDate());
    }
}
