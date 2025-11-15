<?php

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Repository\BusinessProcessRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\ProtectionRequirementService;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\AbstractQuery;
use PHPUnit\Framework\TestCase;

class ProtectionRequirementServiceTest extends TestCase
{
    private ProtectionRequirementService $service;
    private BusinessProcessRepository $businessProcessRepo;
    private IncidentRepository $incidentRepo;
    private RiskRepository $riskRepo;

    protected function setUp(): void
    {
        $this->businessProcessRepo = $this->createMock(BusinessProcessRepository::class);
        $this->incidentRepo = $this->createMock(IncidentRepository::class);
        $this->riskRepo = $this->createMock(RiskRepository::class);

        $this->service = new ProtectionRequirementService(
            $this->businessProcessRepo,
            $this->incidentRepo,
            $this->riskRepo
        );
    }

    public function testCalculateAvailabilityRequirementWithNoBusinessProcesses(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getAvailabilityValue')->willReturn(3);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([]);

        $this->businessProcessRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->calculateAvailabilityRequirement($asset);

        $this->assertEquals(3, $result['value']);
        $this->assertEquals('manual', $result['source']);
        $this->assertEquals('low', $result['confidence']);
        $this->assertNull($result['recommendation']);
        $this->assertStringContainsString('Keine BCM-Daten', $result['reasoning']);
    }

    public function testCalculateAvailabilityRequirementWithBusinessProcess(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getAvailabilityValue')->willReturn(3);

        $process = $this->createMock(BusinessProcess::class);
        $process->method('getName')->willReturn('Critical Payment Process');
        $process->method('getRto')->willReturn(2); // 2 hours
        $process->method('getMtpd')->willReturn(4);
        $process->method('getBusinessImpactScore')->willReturn(5);
        $process->method('getSuggestedAvailabilityValue')->willReturn(5);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([$process]);

        $this->businessProcessRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->calculateAvailabilityRequirement($asset);

        $this->assertEquals(5, $result['value']);
        $this->assertEquals(3, $result['current']);
        $this->assertEquals('bcm', $result['source']);
        $this->assertEquals('high', $result['confidence']);
        $this->assertEquals(5, $result['recommendation']); // Different from current
        $this->assertStringContainsString('Critical Payment Process', $result['reasoning']);
        $this->assertStringContainsString('RTO=2h', $result['reasoning']);
        $this->assertSame($process, $result['process']);
    }

    public function testCalculateAvailabilityRequirementSelectsMostCriticalProcess(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getAvailabilityValue')->willReturn(3);

        $process1 = $this->createMock(BusinessProcess::class);
        $process1->method('getName')->willReturn('Less Critical');
        $process1->method('getRto')->willReturn(24); // 24 hours
        $process1->method('getMtpd')->willReturn(48);
        $process1->method('getBusinessImpactScore')->willReturn(3);
        $process1->method('getSuggestedAvailabilityValue')->willReturn(3);

        $process2 = $this->createMock(BusinessProcess::class);
        $process2->method('getName')->willReturn('Most Critical');
        $process2->method('getRto')->willReturn(1); // 1 hour - lowest
        $process2->method('getMtpd')->willReturn(2);
        $process2->method('getBusinessImpactScore')->willReturn(5);
        $process2->method('getSuggestedAvailabilityValue')->willReturn(5);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([$process1, $process2]);

        $this->businessProcessRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->calculateAvailabilityRequirement($asset);

        // Should select process2 with RTO=1
        $this->assertEquals(5, $result['value']);
        $this->assertStringContainsString('Most Critical', $result['reasoning']);
        $this->assertSame($process2, $result['process']);
    }

    public function testCalculateConfidentialityRequirementWithNoIncidentsOrRisks(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getConfidentialityValue')->willReturn(2);
        $asset->method('getName')->willReturn('Test Asset');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([]);

        $this->incidentRepo->method('createQueryBuilder')->willReturn($queryBuilder);
        $this->riskRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->calculateConfidentialityRequirement($asset);

        $this->assertEquals(2, $result['value']);
        $this->assertEquals(2, $result['current']);
        $this->assertEquals('incidents_risks', $result['source']);
        $this->assertEquals('medium', $result['confidence']);
        $this->assertNull($result['recommendation']);
        $this->assertEquals(0, $result['incidents']);
        $this->assertEquals(0, $result['risks']);
    }

    public function testCalculateConfidentialityRequirementWithDataBreachIncidents(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getConfidentialityValue')->willReturn(2);
        $asset->method('getName')->willReturn('Customer Database');
        $asset->method('getId')->willReturn(123);

        $incident = $this->createMock(Incident::class);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        // Incidents query returns 1 breach
        $incidentQueryBuilder = clone $queryBuilder;
        $incidentQuery = $this->createMock(AbstractQuery::class);
        $incidentQueryBuilder->method('getQuery')->willReturn($incidentQuery);
        $incidentQuery->method('getResult')->willReturn([$incident]);

        // Risks query returns empty
        $riskQueryBuilder = clone $queryBuilder;
        $riskQuery = $this->createMock(AbstractQuery::class);
        $riskQueryBuilder->method('getQuery')->willReturn($riskQuery);
        $riskQuery->method('getResult')->willReturn([]);

        $this->incidentRepo->method('createQueryBuilder')->willReturn($incidentQueryBuilder);
        $this->riskRepo->method('createQueryBuilder')->willReturn($riskQueryBuilder);

        $result = $this->service->calculateConfidentialityRequirement($asset);

        $this->assertEquals(4, $result['value']); // Suggested increase to 4
        $this->assertEquals(2, $result['current']);
        $this->assertEquals(4, $result['recommendation']);
        $this->assertEquals('high', $result['confidence']);
        $this->assertEquals(1, $result['incidents']);
        $this->assertStringContainsString('Datenschutzverletzung', $result['reasoning']);
    }

    public function testCalculateConfidentialityRequirementWithMultipleConfidentialityRisks(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getConfidentialityValue')->willReturn(2);
        $asset->method('getName')->willReturn('HR System');

        $risk1 = $this->createMock(Risk::class);
        $risk2 = $this->createMock(Risk::class);
        $risk3 = $this->createMock(Risk::class);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        // Incidents query returns empty
        $incidentQueryBuilder = clone $queryBuilder;
        $incidentQuery = $this->createMock(AbstractQuery::class);
        $incidentQueryBuilder->method('getQuery')->willReturn($incidentQuery);
        $incidentQuery->method('getResult')->willReturn([]);

        // Risks query returns 3 risks
        $riskQueryBuilder = clone $queryBuilder;
        $riskQuery = $this->createMock(AbstractQuery::class);
        $riskQueryBuilder->method('getQuery')->willReturn($riskQuery);
        $riskQuery->method('getResult')->willReturn([$risk1, $risk2, $risk3]);

        $this->incidentRepo->method('createQueryBuilder')->willReturn($incidentQueryBuilder);
        $this->riskRepo->method('createQueryBuilder')->willReturn($riskQueryBuilder);

        $result = $this->service->calculateConfidentialityRequirement($asset);

        $this->assertEquals(4, $result['value']); // More than 2 risks -> increase to 4
        $this->assertEquals(3, $result['risks']);
        $this->assertEquals('high', $result['confidence']);
        $this->assertStringContainsString('Vertraulichkeitsrisiken', $result['reasoning']);
    }

    public function testCalculateIntegrityRequirementWithNoIncidents(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getIntegrityValue')->willReturn(3);
        $asset->method('getName')->willReturn('Test Asset');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([]);

        $this->incidentRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->calculateIntegrityRequirement($asset);

        $this->assertEquals(3, $result['value']);
        $this->assertEquals(3, $result['current']);
        $this->assertEquals('incidents', $result['source']);
        $this->assertEquals('low', $result['confidence']);
        $this->assertNull($result['recommendation']);
        $this->assertEquals(0, $result['incidents']);
        $this->assertStringContainsString('Keine Integritätsvorfälle', $result['reasoning']);
    }

    public function testCalculateIntegrityRequirementWithIntegrityIncidents(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getIntegrityValue')->willReturn(2);
        $asset->method('getName')->willReturn('Financial Database');

        $incident1 = $this->createMock(Incident::class);
        $incident2 = $this->createMock(Incident::class);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([$incident1, $incident2]);

        $this->incidentRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->calculateIntegrityRequirement($asset);

        $this->assertEquals(4, $result['value']); // Suggested increase to 4
        $this->assertEquals(2, $result['current']);
        $this->assertEquals(4, $result['recommendation']);
        $this->assertEquals('high', $result['confidence']);
        $this->assertEquals(2, $result['incidents']);
        $this->assertStringContainsString('Integritätsverletzung', $result['reasoning']);
    }

    public function testGetCompleteProtectionRequirementAnalysis(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getConfidentialityValue')->willReturn(3);
        $asset->method('getIntegrityValue')->willReturn(3);
        $asset->method('getAvailabilityValue')->willReturn(3);
        $asset->method('getName')->willReturn('Test Asset');

        // Mock all repositories to return empty results
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([]);

        $this->businessProcessRepo->method('createQueryBuilder')->willReturn($queryBuilder);
        $this->incidentRepo->method('createQueryBuilder')->willReturn($queryBuilder);
        $this->riskRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->getCompleteProtectionRequirementAnalysis($asset);

        $this->assertSame($asset, $result['asset']);
        $this->assertArrayHasKey('confidentiality', $result);
        $this->assertArrayHasKey('integrity', $result);
        $this->assertArrayHasKey('availability', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertInstanceOf(\DateTime::class, $result['timestamp']);

        // Verify structure of each analysis
        $this->assertArrayHasKey('value', $result['confidentiality']);
        $this->assertArrayHasKey('source', $result['confidentiality']);
        $this->assertArrayHasKey('confidence', $result['confidentiality']);
        $this->assertArrayHasKey('reasoning', $result['confidentiality']);
    }

    public function testNoRecommendationWhenValueMatchesCurrent(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getAvailabilityValue')->willReturn(5);

        $process = $this->createMock(BusinessProcess::class);
        $process->method('getName')->willReturn('Test Process');
        $process->method('getRto')->willReturn(1);
        $process->method('getMtpd')->willReturn(2);
        $process->method('getBusinessImpactScore')->willReturn(5);
        $process->method('getSuggestedAvailabilityValue')->willReturn(5); // Same as current

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([$process]);

        $this->businessProcessRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->calculateAvailabilityRequirement($asset);

        $this->assertEquals(5, $result['value']);
        $this->assertEquals(5, $result['current']);
        $this->assertNull($result['recommendation']); // No change needed
    }
}
