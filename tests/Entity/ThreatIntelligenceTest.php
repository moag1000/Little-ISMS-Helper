<?php

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Entity\ThreatIntelligence;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class ThreatIntelligenceTest extends TestCase
{
    public function testConstructor(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNotNull($threat->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $threat->getCreatedAt());
        $this->assertNotNull($threat->getDetectionDate());
        $this->assertInstanceOf(\DateTimeInterface::class, $threat->getDetectionDate());
        $this->assertNull($threat->getUpdatedAt());
        $this->assertEquals('medium', $threat->getSeverity());
        $this->assertEquals('new', $threat->getStatus());
        $this->assertFalse($threat->isAffectsOrganization());
        $this->assertEquals(0, $threat->getAffectedAssets()->count());
        $this->assertEquals(0, $threat->getResultingIncidents()->count());
    }

    public function testTitle(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getTitle());

        $threat->setTitle('Critical Zero-Day Vulnerability in Apache');
        $this->assertEquals('Critical Zero-Day Vulnerability in Apache', $threat->getTitle());
    }

    public function testDescription(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getDescription());

        $threat->setDescription('A critical vulnerability has been discovered...');
        $this->assertEquals('A critical vulnerability has been discovered...', $threat->getDescription());
    }

    public function testThreatType(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getThreatType());

        $threat->setThreatType('malware');
        $this->assertEquals('malware', $threat->getThreatType());

        $threat->setThreatType('phishing');
        $this->assertEquals('phishing', $threat->getThreatType());

        $threat->setThreatType('ransomware');
        $this->assertEquals('ransomware', $threat->getThreatType());

        $threat->setThreatType('ddos');
        $this->assertEquals('ddos', $threat->getThreatType());

        $threat->setThreatType('zero_day');
        $this->assertEquals('zero_day', $threat->getThreatType());

        $threat->setThreatType('apt');
        $this->assertEquals('apt', $threat->getThreatType());

        $threat->setThreatType('insider_threat');
        $this->assertEquals('insider_threat', $threat->getThreatType());

        $threat->setThreatType('social_engineering');
        $this->assertEquals('social_engineering', $threat->getThreatType());

        $threat->setThreatType('data_breach');
        $this->assertEquals('data_breach', $threat->getThreatType());

        $threat->setThreatType('vulnerability');
        $this->assertEquals('vulnerability', $threat->getThreatType());

        $threat->setThreatType('other');
        $this->assertEquals('other', $threat->getThreatType());
    }

    public function testSeverity(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertEquals('medium', $threat->getSeverity());

        $threat->setSeverity('critical');
        $this->assertEquals('critical', $threat->getSeverity());

        $threat->setSeverity('high');
        $this->assertEquals('high', $threat->getSeverity());

        $threat->setSeverity('low');
        $this->assertEquals('low', $threat->getSeverity());

        $threat->setSeverity('informational');
        $this->assertEquals('informational', $threat->getSeverity());
    }

    public function testSource(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getSource());

        $threat->setSource('NIST NVD');
        $this->assertEquals('NIST NVD', $threat->getSource());

        $threat->setSource(null);
        $this->assertNull($threat->getSource());
    }

    public function testCveId(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getCveId());

        $threat->setCveId('CVE-2024-12345');
        $this->assertEquals('CVE-2024-12345', $threat->getCveId());

        $threat->setCveId(null);
        $this->assertNull($threat->getCveId());
    }

    public function testAffectedSystems(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getAffectedSystems());

        $systems = ['web-server-01', 'db-server-02'];
        $threat->setAffectedSystems($systems);
        $this->assertEquals($systems, $threat->getAffectedSystems());

        $threat->setAffectedSystems(null);
        $this->assertNull($threat->getAffectedSystems());
    }

    public function testIndicators(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getIndicators());

        $indicators = ['192.168.1.100', 'malicious.example.com'];
        $threat->setIndicators($indicators);
        $this->assertEquals($indicators, $threat->getIndicators());

        $threat->setIndicators(null);
        $this->assertNull($threat->getIndicators());
    }

    public function testMitigationRecommendations(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getMitigationRecommendations());

        $threat->setMitigationRecommendations('Update to version 2.5.1 immediately');
        $this->assertEquals('Update to version 2.5.1 immediately', $threat->getMitigationRecommendations());

        $threat->setMitigationRecommendations(null);
        $this->assertNull($threat->getMitigationRecommendations());
    }

    public function testStatus(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertEquals('new', $threat->getStatus());

        $threat->setStatus('analyzing');
        $this->assertEquals('analyzing', $threat->getStatus());

        $threat->setStatus('mitigated');
        $this->assertEquals('mitigated', $threat->getStatus());

        $threat->setStatus('monitoring');
        $this->assertEquals('monitoring', $threat->getStatus());

        $threat->setStatus('closed');
        $this->assertEquals('closed', $threat->getStatus());
    }

    public function testDetectionDate(): void
    {
        $threat = new ThreatIntelligence();

        // Constructor sets detectionDate
        $this->assertNotNull($threat->getDetectionDate());

        $newDate = new \DateTime('2024-06-15');
        $threat->setDetectionDate($newDate);
        $this->assertEquals($newDate, $threat->getDetectionDate());
    }

    public function testMitigationDate(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getMitigationDate());

        $mitigationDate = new \DateTime('2024-06-20');
        $threat->setMitigationDate($mitigationDate);
        $this->assertEquals($mitigationDate, $threat->getMitigationDate());

        $threat->setMitigationDate(null);
        $this->assertNull($threat->getMitigationDate());
    }

    public function testActionsTaken(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getActionsTaken());

        $threat->setActionsTaken('Patched all affected systems');
        $this->assertEquals('Patched all affected systems', $threat->getActionsTaken());

        $threat->setActionsTaken(null);
        $this->assertNull($threat->getActionsTaken());
    }

    public function testAssignedToRelationship(): void
    {
        $threat = new ThreatIntelligence();
        $user = new User();

        $this->assertNull($threat->getAssignedTo());

        $threat->setAssignedTo($user);
        $this->assertSame($user, $threat->getAssignedTo());

        $threat->setAssignedTo(null);
        $this->assertNull($threat->getAssignedTo());
    }

    public function testIsAffectsOrganization(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertFalse($threat->isAffectsOrganization());

        $threat->setAffectsOrganization(true);
        $this->assertTrue($threat->isAffectsOrganization());

        $threat->setAffectsOrganization(false);
        $this->assertFalse($threat->isAffectsOrganization());
    }

    public function testCvssScore(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getCvssScore());

        $threat->setCvssScore(9);
        $this->assertEquals(9, $threat->getCvssScore());

        $threat->setCvssScore(null);
        $this->assertNull($threat->getCvssScore());
    }

    public function testReferences(): void
    {
        $threat = new ThreatIntelligence();

        $this->assertNull($threat->getReferences());

        $threat->setReferences('https://nvd.nist.gov/vuln/detail/CVE-2024-12345');
        $this->assertEquals('https://nvd.nist.gov/vuln/detail/CVE-2024-12345', $threat->getReferences());

        $threat->setReferences(null);
        $this->assertNull($threat->getReferences());
    }

    public function testTimestamps(): void
    {
        $threat = new ThreatIntelligence();

        // createdAt set in constructor
        $this->assertNotNull($threat->getCreatedAt());

        // updatedAt initially null
        $this->assertNull($threat->getUpdatedAt());

        $now = new \DateTime();
        $threat->setUpdatedAt($now);
        $this->assertEquals($now, $threat->getUpdatedAt());

        $threat->setUpdatedAt(null);
        $this->assertNull($threat->getUpdatedAt());
    }

    public function testTenantRelationship(): void
    {
        $threat = new ThreatIntelligence();
        $tenant = new Tenant();

        $this->assertNull($threat->getTenant());

        $threat->setTenant($tenant);
        $this->assertSame($tenant, $threat->getTenant());

        $threat->setTenant(null);
        $this->assertNull($threat->getTenant());
    }

    public function testAddAndRemoveAffectedAsset(): void
    {
        $threat = new ThreatIntelligence();
        $asset1 = new Asset();
        $asset2 = new Asset();

        $this->assertEquals(0, $threat->getAffectedAssets()->count());

        $threat->addAffectedAsset($asset1);
        $this->assertEquals(1, $threat->getAffectedAssets()->count());
        $this->assertTrue($threat->getAffectedAssets()->contains($asset1));

        $threat->addAffectedAsset($asset2);
        $this->assertEquals(2, $threat->getAffectedAssets()->count());
        $this->assertTrue($threat->getAffectedAssets()->contains($asset2));

        // Adding same asset again should not duplicate
        $threat->addAffectedAsset($asset1);
        $this->assertEquals(2, $threat->getAffectedAssets()->count());

        $threat->removeAffectedAsset($asset1);
        $this->assertEquals(1, $threat->getAffectedAssets()->count());
        $this->assertFalse($threat->getAffectedAssets()->contains($asset1));
        $this->assertTrue($threat->getAffectedAssets()->contains($asset2));

        $threat->removeAffectedAsset($asset2);
        $this->assertEquals(0, $threat->getAffectedAssets()->count());
    }

    public function testAddAndRemoveResultingIncident(): void
    {
        $threat = new ThreatIntelligence();
        $incident1 = new Incident();
        $incident2 = new Incident();

        $this->assertEquals(0, $threat->getResultingIncidents()->count());

        $threat->addResultingIncident($incident1);
        $this->assertEquals(1, $threat->getResultingIncidents()->count());
        $this->assertTrue($threat->getResultingIncidents()->contains($incident1));
        $this->assertSame($threat, $incident1->getOriginatingThreat());

        $threat->addResultingIncident($incident2);
        $this->assertEquals(2, $threat->getResultingIncidents()->count());
        $this->assertTrue($threat->getResultingIncidents()->contains($incident2));
        $this->assertSame($threat, $incident2->getOriginatingThreat());

        // Adding same incident again should not duplicate
        $threat->addResultingIncident($incident1);
        $this->assertEquals(2, $threat->getResultingIncidents()->count());

        $threat->removeResultingIncident($incident1);
        $this->assertEquals(1, $threat->getResultingIncidents()->count());
        $this->assertFalse($threat->getResultingIncidents()->contains($incident1));
        $this->assertNull($incident1->getOriginatingThreat());

        $threat->removeResultingIncident($incident2);
        $this->assertEquals(0, $threat->getResultingIncidents()->count());
    }

    public function testRemoveResultingIncidentWhenNotOwned(): void
    {
        $threat1 = new ThreatIntelligence();
        $threat2 = new ThreatIntelligence();
        $incident = new Incident();

        $threat1->addResultingIncident($incident);
        $this->assertSame($threat1, $incident->getOriginatingThreat());

        // Manually set to different threat
        $incident->setOriginatingThreat($threat2);

        // Removing should not change the originating threat since it's owned by threat2 now
        $threat1->removeResultingIncident($incident);
        $this->assertSame($threat2, $incident->getOriginatingThreat());
    }

    public function testFluentSetters(): void
    {
        $threat = new ThreatIntelligence();
        $user = new User();
        $tenant = new Tenant();
        $asset = new Asset();

        $result = $threat
            ->setTitle('Test Threat')
            ->setDescription('Test Description')
            ->setThreatType('malware')
            ->setSeverity('critical')
            ->setStatus('analyzing')
            ->setAffectsOrganization(true)
            ->setCvssScore(9)
            ->setAssignedTo($user)
            ->setTenant($tenant)
            ->addAffectedAsset($asset);

        $this->assertSame($threat, $result);
        $this->assertEquals('Test Threat', $threat->getTitle());
        $this->assertEquals('Test Description', $threat->getDescription());
        $this->assertEquals('malware', $threat->getThreatType());
        $this->assertEquals('critical', $threat->getSeverity());
        $this->assertEquals('analyzing', $threat->getStatus());
        $this->assertTrue($threat->isAffectsOrganization());
        $this->assertEquals(9, $threat->getCvssScore());
        $this->assertSame($user, $threat->getAssignedTo());
        $this->assertSame($tenant, $threat->getTenant());
        $this->assertTrue($threat->getAffectedAssets()->contains($asset));
    }
}
