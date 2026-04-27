<?php

namespace App\Tests\Entity;

use App\Entity\ComplianceMapping;
use App\Entity\MappingGapItem;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MappingGapItemTest extends TestCase
{
    #[Test]
    public function testConstructor(): void
    {
        $item = new MappingGapItem();

        $this->assertNotNull($item->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $item->getCreatedAt());
        $this->assertNull($item->getUpdatedAt());
        $this->assertEquals('medium', $item->getPriority());
        $this->assertEquals(0, $item->getPercentageImpact());
        $this->assertEquals('algorithm', $item->getIdentificationSource());
        $this->assertEquals(50, $item->getConfidence());
        $this->assertEquals('identified', $item->getStatus());
        $this->assertEquals([], $item->getMissingKeywords());
    }

    #[Test]
    public function testMappingRelationship(): void
    {
        $item = new MappingGapItem();
        $mapping = new ComplianceMapping();

        $this->assertNull($item->getMapping());

        $item->setMapping($mapping);
        $this->assertSame($mapping, $item->getMapping());

        $item->setMapping(null);
        $this->assertNull($item->getMapping());
    }

    #[Test]
    public function testGapType(): void
    {
        $item = new MappingGapItem();

        $this->assertNull($item->getGapType());

        $item->setGapType('missing_control');
        $this->assertEquals('missing_control', $item->getGapType());

        $item->setGapType('partial_coverage');
        $this->assertEquals('partial_coverage', $item->getGapType());

        $item->setGapType('scope_difference');
        $this->assertEquals('scope_difference', $item->getGapType());

        $item->setGapType('additional_requirement');
        $this->assertEquals('additional_requirement', $item->getGapType());

        $item->setGapType('evidence_gap');
        $this->assertEquals('evidence_gap', $item->getGapType());
    }

    #[Test]
    public function testDescription(): void
    {
        $item = new MappingGapItem();

        $this->assertNull($item->getDescription());

        $item->setDescription('Missing implementation of encryption requirements');
        $this->assertEquals('Missing implementation of encryption requirements', $item->getDescription());
    }

    #[Test]
    public function testMissingKeywords(): void
    {
        $item = new MappingGapItem();

        $this->assertEquals([], $item->getMissingKeywords());

        $keywords = ['encryption', 'key management', 'HSM'];
        $item->setMissingKeywords($keywords);
        $this->assertEquals($keywords, $item->getMissingKeywords());

        $item->setMissingKeywords(null);
        $this->assertNull($item->getMissingKeywords());
    }

    #[Test]
    public function testRecommendedAction(): void
    {
        $item = new MappingGapItem();

        $this->assertNull($item->getRecommendedAction());

        $item->setRecommendedAction('Implement hardware security module for key storage');
        $this->assertEquals('Implement hardware security module for key storage', $item->getRecommendedAction());

        $item->setRecommendedAction(null);
        $this->assertNull($item->getRecommendedAction());
    }

    #[Test]
    public function testPriority(): void
    {
        $item = new MappingGapItem();

        $this->assertEquals('medium', $item->getPriority());

        $item->setPriority('critical');
        $this->assertEquals('critical', $item->getPriority());

        $item->setPriority('high');
        $this->assertEquals('high', $item->getPriority());

        $item->setPriority('low');
        $this->assertEquals('low', $item->getPriority());
    }

    #[Test]
    public function testEstimatedEffort(): void
    {
        $item = new MappingGapItem();

        $this->assertNull($item->getEstimatedEffort());

        $item->setEstimatedEffort(40);
        $this->assertEquals(40, $item->getEstimatedEffort());

        $item->setEstimatedEffort(null);
        $this->assertNull($item->getEstimatedEffort());
    }

    #[Test]
    public function testPercentageImpact(): void
    {
        $item = new MappingGapItem();

        $this->assertEquals(0, $item->getPercentageImpact());

        $item->setPercentageImpact(25);
        $this->assertEquals(25, $item->getPercentageImpact());
    }

    #[Test]
    public function testPercentageImpactClampingMin(): void
    {
        $item = new MappingGapItem();

        $item->setPercentageImpact(-10);
        $this->assertEquals(0, $item->getPercentageImpact());
    }

    #[Test]
    public function testPercentageImpactClampingMax(): void
    {
        $item = new MappingGapItem();

        $item->setPercentageImpact(150);
        $this->assertEquals(100, $item->getPercentageImpact());
    }

    #[Test]
    public function testIdentificationSource(): void
    {
        $item = new MappingGapItem();

        $this->assertEquals('algorithm', $item->getIdentificationSource());

        $item->setIdentificationSource('manual');
        $this->assertEquals('manual', $item->getIdentificationSource());
    }

    #[Test]
    public function testConfidence(): void
    {
        $item = new MappingGapItem();

        $this->assertEquals(50, $item->getConfidence());

        $item->setConfidence(85);
        $this->assertEquals(85, $item->getConfidence());
    }

    #[Test]
    public function testConfidenceClampingMin(): void
    {
        $item = new MappingGapItem();

        $item->setConfidence(-20);
        $this->assertEquals(0, $item->getConfidence());
    }

    #[Test]
    public function testConfidenceClampingMax(): void
    {
        $item = new MappingGapItem();

        $item->setConfidence(120);
        $this->assertEquals(100, $item->getConfidence());
    }

    #[Test]
    public function testStatus(): void
    {
        $item = new MappingGapItem();

        $this->assertEquals('identified', $item->getStatus());

        $item->setStatus('planned');
        $this->assertEquals('planned', $item->getStatus());

        $item->setStatus('in_progress');
        $this->assertEquals('in_progress', $item->getStatus());

        $item->setStatus('resolved');
        $this->assertEquals('resolved', $item->getStatus());

        $item->setStatus('wont_fix');
        $this->assertEquals('wont_fix', $item->getStatus());
    }

    #[Test]
    public function testTimestamps(): void
    {
        $item = new MappingGapItem();

        // createdAt set in constructor
        $this->assertNotNull($item->getCreatedAt());

        // updatedAt initially null
        $this->assertNull($item->getUpdatedAt());

        $now = new \DateTimeImmutable();
        $item->setUpdatedAt($now);
        $this->assertEquals($now, $item->getUpdatedAt());

        $item->setUpdatedAt(null);
        $this->assertNull($item->getUpdatedAt());
    }

    #[Test]
    public function testGetPriorityBadgeClassCritical(): void
    {
        $item = new MappingGapItem();
        $item->setPriority('critical');

        $this->assertEquals('danger', $item->getPriorityBadgeClass());
    }

    #[Test]
    public function testGetPriorityBadgeClassHigh(): void
    {
        $item = new MappingGapItem();
        $item->setPriority('high');

        $this->assertEquals('warning', $item->getPriorityBadgeClass());
    }

    #[Test]
    public function testGetPriorityBadgeClassMedium(): void
    {
        $item = new MappingGapItem();
        $item->setPriority('medium');

        $this->assertEquals('info', $item->getPriorityBadgeClass());
    }

    #[Test]
    public function testGetPriorityBadgeClassLow(): void
    {
        $item = new MappingGapItem();
        $item->setPriority('low');

        $this->assertEquals('secondary', $item->getPriorityBadgeClass());
    }

    #[Test]
    public function testGetPriorityBadgeClassUnknown(): void
    {
        $item = new MappingGapItem();
        $item->setPriority('unknown');

        $this->assertEquals('secondary', $item->getPriorityBadgeClass());
    }

    #[Test]
    public function testGetGapTypeLabelMissingControl(): void
    {
        $item = new MappingGapItem();
        $item->setGapType('missing_control');

        $this->assertEquals('Fehlende Kontrolle', $item->getGapTypeLabel());
    }

    #[Test]
    public function testGetGapTypeLabelPartialCoverage(): void
    {
        $item = new MappingGapItem();
        $item->setGapType('partial_coverage');

        $this->assertEquals('Teilweise Abdeckung', $item->getGapTypeLabel());
    }

    #[Test]
    public function testGetGapTypeLabelScopeDifference(): void
    {
        $item = new MappingGapItem();
        $item->setGapType('scope_difference');

        $this->assertEquals('Scope-Unterschied', $item->getGapTypeLabel());
    }

    #[Test]
    public function testGetGapTypeLabelAdditionalRequirement(): void
    {
        $item = new MappingGapItem();
        $item->setGapType('additional_requirement');

        $this->assertEquals('Zusätzliche Anforderung', $item->getGapTypeLabel());
    }

    #[Test]
    public function testGetGapTypeLabelEvidenceGap(): void
    {
        $item = new MappingGapItem();
        $item->setGapType('evidence_gap');

        $this->assertEquals('Fehlende Evidenz', $item->getGapTypeLabel());
    }

    #[Test]
    public function testGetGapTypeLabelUnknown(): void
    {
        $item = new MappingGapItem();
        $item->setGapType('unknown_type');

        $this->assertEquals('unknown_type', $item->getGapTypeLabel());
    }

    #[Test]
    public function testGetStatusBadgeClassIdentified(): void
    {
        $item = new MappingGapItem();
        $item->setStatus('identified');

        $this->assertEquals('secondary', $item->getStatusBadgeClass());
    }

    #[Test]
    public function testGetStatusBadgeClassPlanned(): void
    {
        $item = new MappingGapItem();
        $item->setStatus('planned');

        $this->assertEquals('info', $item->getStatusBadgeClass());
    }

    #[Test]
    public function testGetStatusBadgeClassInProgress(): void
    {
        $item = new MappingGapItem();
        $item->setStatus('in_progress');

        $this->assertEquals('warning', $item->getStatusBadgeClass());
    }

    #[Test]
    public function testGetStatusBadgeClassResolved(): void
    {
        $item = new MappingGapItem();
        $item->setStatus('resolved');

        $this->assertEquals('success', $item->getStatusBadgeClass());
    }

    #[Test]
    public function testGetStatusBadgeClassWontFix(): void
    {
        $item = new MappingGapItem();
        $item->setStatus('wont_fix');

        $this->assertEquals('dark', $item->getStatusBadgeClass());
    }

    #[Test]
    public function testGetStatusBadgeClassUnknown(): void
    {
        $item = new MappingGapItem();
        $item->setStatus('unknown_status');

        $this->assertEquals('secondary', $item->getStatusBadgeClass());
    }

    #[Test]
    public function testFluentSetters(): void
    {
        $item = new MappingGapItem();
        $mapping = new ComplianceMapping();

        $result = $item
            ->setMapping($mapping)
            ->setGapType('missing_control')
            ->setDescription('Test gap')
            ->setPriority('high')
            ->setEstimatedEffort(20)
            ->setPercentageImpact(30)
            ->setIdentificationSource('manual')
            ->setConfidence(90)
            ->setStatus('planned');

        $this->assertSame($item, $result);
        $this->assertSame($mapping, $item->getMapping());
        $this->assertEquals('missing_control', $item->getGapType());
        $this->assertEquals('Test gap', $item->getDescription());
        $this->assertEquals('high', $item->getPriority());
        $this->assertEquals(20, $item->getEstimatedEffort());
        $this->assertEquals(30, $item->getPercentageImpact());
        $this->assertEquals('manual', $item->getIdentificationSource());
        $this->assertEquals(90, $item->getConfidence());
        $this->assertEquals('planned', $item->getStatus());
    }
}
