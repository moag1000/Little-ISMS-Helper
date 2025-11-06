<?php

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\Training;
use PHPUnit\Framework\TestCase;

class ControlTest extends TestCase
{
    public function testGetProtectedAssetValueWithNoAssets(): void
    {
        $control = new Control();

        $this->assertEquals(0, $control->getProtectedAssetValue());
    }

    public function testGetProtectedAssetValueWithMultipleAssets(): void
    {
        $control = new Control();

        $asset1 = new Asset();
        $asset1->setConfidentialityValue(3);
        $asset1->setIntegrityValue(4);
        $asset1->setAvailabilityValue(5);
        // getTotalValue() = max(3, 4, 5) = 5

        $asset2 = new Asset();
        $asset2->setConfidentialityValue(2);
        $asset2->setIntegrityValue(3);
        $asset2->setAvailabilityValue(4);
        // getTotalValue() = max(2, 3, 4) = 4

        $control->addProtectedAsset($asset1);
        $control->addProtectedAsset($asset2);

        // Total = 5 + 4 = 9
        $this->assertEquals(9, $control->getProtectedAssetValue());
    }

    public function testGetHighRiskAssetCountWithNoAssets(): void
    {
        $control = new Control();

        $this->assertEquals(0, $control->getHighRiskAssetCount());
    }

    public function testGetHighRiskAssetCountWithMixedAssets(): void
    {
        $control = new Control();

        // High-risk asset (need score >= 70)
        $asset1 = new Asset();
        $asset1->setConfidentialityValue(5);
        $asset1->setIntegrityValue(5);
        $asset1->setAvailabilityValue(5);

        // Add risks to make it high-risk (base 50 + 20 = 70)
        for ($i = 0; $i < 4; $i++) {
            $risk = new \App\Entity\Risk();
            $risk->setStatus('active');
            $asset1->addRisk($risk);
        }

        // Low-risk asset
        $asset2 = new Asset();
        $asset2->setConfidentialityValue(2);
        $asset2->setIntegrityValue(2);
        $asset2->setAvailabilityValue(2);
        // score = 20 < 70

        $control->addProtectedAsset($asset1);
        $control->addProtectedAsset($asset2);

        $this->assertEquals(1, $control->getHighRiskAssetCount());
    }

    public function testGetEffectivenessScoreNotFullyImplemented(): void
    {
        $control = new Control();
        $control->setImplementationPercentage(50);

        // Should return 0 if not 100% implemented
        $this->assertEquals(0.0, $control->getEffectivenessScore());
    }

    public function testGetEffectivenessScoreNoProtectedAssets(): void
    {
        $control = new Control();
        $control->setImplementationPercentage(100);

        // Should return 100 if no assets to protect
        $this->assertEquals(100.0, $control->getEffectivenessScore());
    }

    public function testGetEffectivenessScoreWithNoIncidents(): void
    {
        $control = new Control();
        $control->setImplementationPercentage(100);
        $control->setCreatedAt(new \DateTime('-1 month'));

        $asset = new Asset();
        $control->addProtectedAsset($asset);

        // No incidents = 100% effectiveness
        $this->assertEquals(100.0, $control->getEffectivenessScore());
    }

    public function testGetEffectivenessScoreWithIncidentsAfterImplementation(): void
    {
        $control = new Control();
        $control->setImplementationPercentage(100);
        $control->setCreatedAt(new \DateTime('-2 months'));

        $asset = new Asset();

        // Add incident after control implementation
        $incident = new Incident();
        $incident->setDetectedAt(new \DateTime('-1 month'));
        $asset->addIncident($incident);

        $control->addProtectedAsset($asset);

        // 1 asset, 1 incident
        // incidents per asset = 1/1 = 1
        // score = 100 - (1 * 20) = 80
        $this->assertEquals(80.0, $control->getEffectivenessScore());
    }

    public function testGetEffectivenessScoreWithIncidentsBeforeImplementation(): void
    {
        $control = new Control();
        $control->setImplementationPercentage(100);
        $control->setCreatedAt(new \DateTime('-1 month'));

        $asset = new Asset();

        // Add incident BEFORE control implementation
        $incident = new Incident();
        $incident->setDetectedAt(new \DateTime('-2 months'));
        $asset->addIncident($incident);

        $control->addProtectedAsset($asset);

        // Incident before implementation shouldn't count
        $this->assertEquals(100.0, $control->getEffectivenessScore());
    }

    public function testGetEffectivenessScoreMultipleIncidents(): void
    {
        $control = new Control();
        $control->setImplementationPercentage(100);
        $control->setCreatedAt(new \DateTime('-3 months'));

        $asset1 = new Asset();
        $asset2 = new Asset();

        // 3 incidents on asset1
        for ($i = 0; $i < 3; $i++) {
            $incident = new Incident();
            $incident->setDetectedAt(new \DateTime('-1 month'));
            $asset1->addIncident($incident);
        }

        // 1 incident on asset2
        $incident = new Incident();
        $incident->setDetectedAt(new \DateTime('-1 month'));
        $asset2->addIncident($incident);

        $control->addProtectedAsset($asset1);
        $control->addProtectedAsset($asset2);

        // 2 assets, 4 incidents
        // incidents per asset = 4/2 = 2
        // score = 100 - (2 * 20) = 60
        $this->assertEquals(60.0, $control->getEffectivenessScore());
    }

    public function testNeedsReviewWithNoIncidents(): void
    {
        $control = new Control();

        $asset = new Asset();
        $control->addProtectedAsset($asset);

        $this->assertFalse($control->needsReview());
    }

    public function testNeedsReviewWithRecentIncident(): void
    {
        $control = new Control();

        $asset = new Asset();
        $incident = new Incident();
        $incident->setDetectedAt(new \DateTime('-1 month'));
        $asset->addIncident($incident);

        $control->addProtectedAsset($asset);

        // Recent incident (< 3 months) should trigger review
        $this->assertTrue($control->needsReview());
    }

    public function testNeedsReviewWithOldIncident(): void
    {
        $control = new Control();

        $asset = new Asset();
        $incident = new Incident();
        $incident->setDetectedAt(new \DateTime('-6 months'));
        $asset->addIncident($incident);

        $control->addProtectedAsset($asset);

        // Old incident (> 3 months) should not trigger review
        $this->assertFalse($control->needsReview());
    }

    public function testNeedsReviewWithPastDueReviewDate(): void
    {
        $control = new Control();
        $control->setNextReviewDate(new \DateTime('-1 day'));

        // Past due review date should trigger review
        $this->assertTrue($control->needsReview());
    }

    public function testNeedsReviewWithFutureReviewDate(): void
    {
        $control = new Control();
        $control->setNextReviewDate(new \DateTime('+1 month'));

        // Future review date should not trigger review
        $this->assertFalse($control->needsReview());
    }

    public function testHasTrainingCoverageWithNoTrainings(): void
    {
        $control = new Control();

        $this->assertFalse($control->hasTrainingCoverage());
    }

    public function testHasTrainingCoverageWithCompletedTraining(): void
    {
        $control = new Control();

        $training = new Training();
        $training->setStatus('completed');
        $control->addTraining($training);

        $this->assertTrue($control->hasTrainingCoverage());
    }

    public function testHasTrainingCoverageWithOnlyPlannedTraining(): void
    {
        $control = new Control();

        $training = new Training();
        $training->setStatus('planned');
        $control->addTraining($training);

        // Only planned training = no coverage yet
        $this->assertFalse($control->hasTrainingCoverage());
    }

    public function testGetTrainingStatusNoTraining(): void
    {
        $control = new Control();

        $this->assertEquals('no_training', $control->getTrainingStatus());
    }

    public function testGetTrainingStatusTrainingCurrent(): void
    {
        $control = new Control();

        $training = new Training();
        $training->setStatus('completed');
        $training->setCompletionDate(new \DateTime('-6 months'));
        $control->addTraining($training);

        // Completed training < 1 year = current
        $this->assertEquals('training_current', $control->getTrainingStatus());
    }

    public function testGetTrainingStatusTrainingOutdated(): void
    {
        $control = new Control();

        $training = new Training();
        $training->setStatus('completed');
        $training->setCompletionDate(new \DateTime('-2 years'));
        $control->addTraining($training);

        // Completed training > 1 year = outdated
        $this->assertEquals('training_outdated', $control->getTrainingStatus());
    }

    public function testGetTrainingStatusTrainingPlanned(): void
    {
        $control = new Control();

        $training = new Training();
        $training->setStatus('planned');
        $control->addTraining($training);

        $this->assertEquals('training_planned', $control->getTrainingStatus());
    }

    public function testGetTrainingStatusTrainingIncomplete(): void
    {
        $control = new Control();

        $training = new Training();
        $training->setStatus('in_progress');
        $control->addTraining($training);

        // Not completed, not planned = incomplete
        $this->assertEquals('training_incomplete', $control->getTrainingStatus());
    }

    public function testGetTrainingStatusMostRecentCompleted(): void
    {
        $control = new Control();

        // Old completed training
        $training1 = new Training();
        $training1->setStatus('completed');
        $training1->setCompletionDate(new \DateTime('-2 years'));

        // Recent completed training
        $training2 = new Training();
        $training2->setStatus('completed');
        $training2->setCompletionDate(new \DateTime('-3 months'));

        $control->addTraining($training1);
        $control->addTraining($training2);

        // Should use most recent training date
        $this->assertEquals('training_current', $control->getTrainingStatus());
    }

    public function testAddAndRemoveRisk(): void
    {
        $control = new Control();
        $risk = new \App\Entity\Risk();

        $this->assertEquals(0, $control->getRisks()->count());

        $control->addRisk($risk);
        $this->assertEquals(1, $control->getRisks()->count());
        $this->assertTrue($control->getRisks()->contains($risk));

        $control->removeRisk($risk);
        $this->assertEquals(0, $control->getRisks()->count());
        $this->assertFalse($control->getRisks()->contains($risk));
    }

    public function testAddAndRemoveIncident(): void
    {
        $control = new Control();
        $incident = new Incident();

        $this->assertEquals(0, $control->getIncidents()->count());

        $control->addIncident($incident);
        $this->assertEquals(1, $control->getIncidents()->count());
        $this->assertTrue($control->getIncidents()->contains($incident));

        $control->removeIncident($incident);
        $this->assertEquals(0, $control->getIncidents()->count());
        $this->assertFalse($control->getIncidents()->contains($incident));
    }

    public function testAddAndRemoveProtectedAsset(): void
    {
        $control = new Control();
        $asset = new Asset();

        $this->assertEquals(0, $control->getProtectedAssets()->count());

        $control->addProtectedAsset($asset);
        $this->assertEquals(1, $control->getProtectedAssets()->count());
        $this->assertTrue($control->getProtectedAssets()->contains($asset));

        $control->removeProtectedAsset($asset);
        $this->assertEquals(0, $control->getProtectedAssets()->count());
        $this->assertFalse($control->getProtectedAssets()->contains($asset));
    }

    public function testAddAndRemoveTraining(): void
    {
        $control = new Control();
        $training = new Training();

        $this->assertEquals(0, $control->getTrainings()->count());

        $control->addTraining($training);
        $this->assertEquals(1, $control->getTrainings()->count());
        $this->assertTrue($control->getTrainings()->contains($training));

        $control->removeTraining($training);
        $this->assertEquals(0, $control->getTrainings()->count());
        $this->assertFalse($control->getTrainings()->contains($training));
    }
}
