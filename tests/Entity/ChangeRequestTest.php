<?php

namespace App\Tests\Entity;

use App\Entity\ChangeRequest;
use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\BusinessProcess;
use App\Entity\Risk;
use App\Entity\Document;
use PHPUnit\Framework\TestCase;

class ChangeRequestTest extends TestCase
{
    public function testNewChangeRequestHasDefaultValues(): void
    {
        $changeRequest = new ChangeRequest();

        $this->assertNull($changeRequest->getId());
        $this->assertNull($changeRequest->getTenant());
        $this->assertNull($changeRequest->getChangeNumber());
        $this->assertNull($changeRequest->getTitle());
        $this->assertEquals('other', $changeRequest->getChangeType());
        $this->assertNull($changeRequest->getDescription());
        $this->assertNull($changeRequest->getJustification());
        $this->assertNull($changeRequest->getRequestedBy());
        $this->assertInstanceOf(\DateTime::class, $changeRequest->getRequestedDate());
        $this->assertEquals('medium', $changeRequest->getPriority());
        $this->assertEquals('draft', $changeRequest->getStatus());
        $this->assertNull($changeRequest->getIsmsImpact());
        $this->assertCount(0, $changeRequest->getAffectedAssets());
        $this->assertCount(0, $changeRequest->getAffectedControls());
        $this->assertCount(0, $changeRequest->getAffectedProcesses());
        $this->assertCount(0, $changeRequest->getAssociatedRisks());
        $this->assertCount(0, $changeRequest->getDocuments());
        $this->assertInstanceOf(\DateTime::class, $changeRequest->getCreatedAt());
        $this->assertNull($changeRequest->getUpdatedAt());
    }

    public function testSetAndGetChangeNumber(): void
    {
        $changeRequest = new ChangeRequest();
        $changeRequest->setChangeNumber('CHG-2024-001');

        $this->assertEquals('CHG-2024-001', $changeRequest->getChangeNumber());
    }

    public function testSetAndGetTitle(): void
    {
        $changeRequest = new ChangeRequest();
        $changeRequest->setTitle('Migrate to cloud infrastructure');

        $this->assertEquals('Migrate to cloud infrastructure', $changeRequest->getTitle());
    }

    public function testSetAndGetChangeType(): void
    {
        $changeRequest = new ChangeRequest();
        $changeRequest->setChangeType('technology');

        $this->assertEquals('technology', $changeRequest->getChangeType());
    }

    public function testSetAndGetPriority(): void
    {
        $changeRequest = new ChangeRequest();

        $changeRequest->setPriority('critical');
        $this->assertEquals('critical', $changeRequest->getPriority());

        $changeRequest->setPriority('high');
        $this->assertEquals('high', $changeRequest->getPriority());

        $changeRequest->setPriority('low');
        $this->assertEquals('low', $changeRequest->getPriority());
    }

    public function testSetAndGetStatus(): void
    {
        $changeRequest = new ChangeRequest();

        $changeRequest->setStatus('submitted');
        $this->assertEquals('submitted', $changeRequest->getStatus());

        $changeRequest->setStatus('approved');
        $this->assertEquals('approved', $changeRequest->getStatus());

        $changeRequest->setStatus('closed');
        $this->assertEquals('closed', $changeRequest->getStatus());
    }

    public function testAddAndRemoveAffectedAsset(): void
    {
        $changeRequest = new ChangeRequest();
        $asset = new Asset();
        $asset->setName('Production Server');

        $this->assertCount(0, $changeRequest->getAffectedAssets());

        $changeRequest->addAffectedAsset($asset);
        $this->assertCount(1, $changeRequest->getAffectedAssets());
        $this->assertTrue($changeRequest->getAffectedAssets()->contains($asset));

        $changeRequest->removeAffectedAsset($asset);
        $this->assertCount(0, $changeRequest->getAffectedAssets());
    }

    public function testAddAffectedAssetDoesNotDuplicate(): void
    {
        $changeRequest = new ChangeRequest();
        $asset = new Asset();
        $asset->setName('Database Server');

        $changeRequest->addAffectedAsset($asset);
        $changeRequest->addAffectedAsset($asset);

        $this->assertCount(1, $changeRequest->getAffectedAssets());
    }

    public function testAddAndRemoveAffectedControl(): void
    {
        $changeRequest = new ChangeRequest();
        $control = new Control();
        $control->setTitle('Access Control');

        $changeRequest->addAffectedControl($control);
        $this->assertCount(1, $changeRequest->getAffectedControls());

        $changeRequest->removeAffectedControl($control);
        $this->assertCount(0, $changeRequest->getAffectedControls());
    }

    public function testAddAndRemoveAffectedProcess(): void
    {
        $changeRequest = new ChangeRequest();
        $process = new BusinessProcess();
        $process->setName('Order Processing');

        $changeRequest->addAffectedProcess($process);
        $this->assertCount(1, $changeRequest->getAffectedProcesses());

        $changeRequest->removeAffectedProcess($process);
        $this->assertCount(0, $changeRequest->getAffectedProcesses());
    }

    public function testAddAndRemoveAssociatedRisk(): void
    {
        $changeRequest = new ChangeRequest();
        $risk = new Risk();
        $risk->setTitle('Migration Risk');

        $changeRequest->addAssociatedRisk($risk);
        $this->assertCount(1, $changeRequest->getAssociatedRisks());

        $changeRequest->removeAssociatedRisk($risk);
        $this->assertCount(0, $changeRequest->getAssociatedRisks());
    }

    public function testAddAndRemoveDocument(): void
    {
        $changeRequest = new ChangeRequest();
        $document = new Document();
        $document->setTitle('Implementation Plan');

        $changeRequest->addDocument($document);
        $this->assertCount(1, $changeRequest->getDocuments());

        $changeRequest->removeDocument($document);
        $this->assertCount(0, $changeRequest->getDocuments());
    }

    public function testIsApprovedReturnsTrueForApprovedStatuses(): void
    {
        $changeRequest = new ChangeRequest();

        $changeRequest->setStatus('approved');
        $this->assertTrue($changeRequest->isApproved());

        $changeRequest->setStatus('scheduled');
        $this->assertTrue($changeRequest->isApproved());

        $changeRequest->setStatus('implemented');
        $this->assertTrue($changeRequest->isApproved());

        $changeRequest->setStatus('closed');
        $this->assertTrue($changeRequest->isApproved());
    }

    public function testIsApprovedReturnsFalseForNonApprovedStatuses(): void
    {
        $changeRequest = new ChangeRequest();

        $changeRequest->setStatus('draft');
        $this->assertFalse($changeRequest->isApproved());

        $changeRequest->setStatus('submitted');
        $this->assertFalse($changeRequest->isApproved());

        $changeRequest->setStatus('rejected');
        $this->assertFalse($changeRequest->isApproved());
    }

    public function testIsPendingApprovalReturnsTrueForReviewStatuses(): void
    {
        $changeRequest = new ChangeRequest();

        $changeRequest->setStatus('submitted');
        $this->assertTrue($changeRequest->isPendingApproval());

        $changeRequest->setStatus('under_review');
        $this->assertTrue($changeRequest->isPendingApproval());
    }

    public function testIsPendingApprovalReturnsFalseForOtherStatuses(): void
    {
        $changeRequest = new ChangeRequest();

        $changeRequest->setStatus('draft');
        $this->assertFalse($changeRequest->isPendingApproval());

        $changeRequest->setStatus('approved');
        $this->assertFalse($changeRequest->isPendingApproval());

        $changeRequest->setStatus('closed');
        $this->assertFalse($changeRequest->isPendingApproval());
    }

    public function testGetComplexityScoreCalculatesCorrectly(): void
    {
        $changeRequest = new ChangeRequest();
        $changeRequest->setPriority('medium');

        // Initially should be just the priority score (4 for medium)
        $this->assertEquals(4, $changeRequest->getComplexityScore());

        // Add 5 affected assets (5 * 3 = 15, capped at 30)
        for ($i = 0; $i < 5; $i++) {
            $asset = new Asset();
            $asset->setName("Asset $i");
            $changeRequest->addAffectedAsset($asset);
        }
        $this->assertEquals(19, $changeRequest->getComplexityScore()); // 15 + 4

        // Add 3 affected controls (3 * 5 = 15, capped at 25)
        for ($i = 0; $i < 3; $i++) {
            $control = new Control();
            $control->setTitle("Control $i");
            $changeRequest->addAffectedControl($control);
        }
        $this->assertEquals(34, $changeRequest->getComplexityScore()); // 15 + 15 + 4
    }

    public function testGetComplexityScoreIsCappedAt100(): void
    {
        $changeRequest = new ChangeRequest();
        $changeRequest->setPriority('critical'); // +10

        // Add maximum assets (30 points)
        for ($i = 0; $i < 20; $i++) {
            $asset = new Asset();
            $asset->setName("Asset $i");
            $changeRequest->addAffectedAsset($asset);
        }

        // Add maximum controls (25 points)
        for ($i = 0; $i < 10; $i++) {
            $control = new Control();
            $control->setTitle("Control $i");
            $changeRequest->addAffectedControl($control);
        }

        // Add maximum processes (20 points)
        for ($i = 0; $i < 10; $i++) {
            $process = new BusinessProcess();
            $process->setName("Process $i");
            $changeRequest->addAffectedProcess($process);
        }

        // Add maximum risks (15 points)
        for ($i = 0; $i < 10; $i++) {
            $risk = new Risk();
            $risk->setTitle("Risk $i");
            $changeRequest->addAssociatedRisk($risk);
        }

        // Total would be 30+25+20+15+10 = 100
        $this->assertEquals(100, $changeRequest->getComplexityScore());
    }

    public function testGetWorkflowProgressReturnsCorrectPercentages(): void
    {
        $changeRequest = new ChangeRequest();

        $changeRequest->setStatus('draft');
        $this->assertEquals(0, $changeRequest->getWorkflowProgress());

        $changeRequest->setStatus('submitted');
        $this->assertEquals(14, $changeRequest->getWorkflowProgress());

        $changeRequest->setStatus('under_review');
        $this->assertEquals(28, $changeRequest->getWorkflowProgress());

        $changeRequest->setStatus('approved');
        $this->assertEquals(42, $changeRequest->getWorkflowProgress());

        $changeRequest->setStatus('scheduled');
        $this->assertEquals(57, $changeRequest->getWorkflowProgress());

        $changeRequest->setStatus('implemented');
        $this->assertEquals(71, $changeRequest->getWorkflowProgress());

        $changeRequest->setStatus('verified');
        $this->assertEquals(85, $changeRequest->getWorkflowProgress());

        $changeRequest->setStatus('closed');
        $this->assertEquals(100, $changeRequest->getWorkflowProgress());

        $changeRequest->setStatus('rejected');
        $this->assertEquals(0, $changeRequest->getWorkflowProgress());

        $changeRequest->setStatus('cancelled');
        $this->assertEquals(0, $changeRequest->getWorkflowProgress());
    }

    public function testGetStatusBadgeReturnsCorrectColors(): void
    {
        $changeRequest = new ChangeRequest();

        $changeRequest->setStatus('draft');
        $this->assertEquals('secondary', $changeRequest->getStatusBadge());

        $changeRequest->setStatus('submitted');
        $this->assertEquals('info', $changeRequest->getStatusBadge());

        $changeRequest->setStatus('approved');
        $this->assertEquals('primary', $changeRequest->getStatusBadge());

        $changeRequest->setStatus('implemented');
        $this->assertEquals('success', $changeRequest->getStatusBadge());

        $changeRequest->setStatus('closed');
        $this->assertEquals('dark', $changeRequest->getStatusBadge());

        $changeRequest->setStatus('rejected');
        $this->assertEquals('danger', $changeRequest->getStatusBadge());
    }
}
