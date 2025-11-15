<?php

namespace App\Tests\Service;

use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Service\RiskIntelligenceService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;

class RiskIntelligenceServiceTest extends TestCase
{
    private RiskIntelligenceService $service;
    private IncidentRepository $incidentRepo;
    private ControlRepository $controlRepo;

    protected function setUp(): void
    {
        $this->incidentRepo = $this->createMock(IncidentRepository::class);
        $this->controlRepo = $this->createMock(ControlRepository::class);

        $this->service = new RiskIntelligenceService(
            $this->incidentRepo,
            $this->controlRepo
        );
    }

    public function testSuggestRisksFromIncidentsWithNoIncidents(): void
    {
        $this->incidentRepo->method('findAll')->willReturn([]);

        $result = $this->service->suggestRisksFromIncidents();

        $this->assertEmpty($result);
    }

    public function testSuggestRisksFromIncidentsSuggestsNewRisks(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getTitle')->willReturn('Data Breach');
        $incident->method('getIncidentNumber')->willReturn('INC-001');
        $incident->method('getCategory')->willReturn('data_breach');
        $incident->method('getSeverity')->willReturn('high');
        $incident->method('getRootCause')->willReturn('Phishing attack');

        $this->incidentRepo->method('findAll')->willReturn([$incident]);
        $this->incidentRepo->method('count')->willReturn(1);

        $result = $this->service->suggestRisksFromIncidents();

        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('incident', $result[0]);
        $this->assertArrayHasKey('suggested_risk', $result[0]);
        $this->assertSame($incident, $result[0]['incident']);

        $suggestedRisk = $result[0]['suggested_risk'];
        $this->assertStringContainsString('Wiederholung:', $suggestedRisk['title']);
        $this->assertStringContainsString('Data Breach', $suggestedRisk['title']);
        $this->assertStringContainsString('INC-001', $suggestedRisk['description']);
        $this->assertStringContainsString('Phishing attack', $suggestedRisk['threat']);
        $this->assertEquals(4, $suggestedRisk['impact']); // high severity -> impact 4
    }

    public function testSuggestRisksFromIncidentsMapsSeverityToImpact(): void
    {
        $severityMappings = [
            'critical' => 5,
            'high' => 4,
            'medium' => 3,
            'low' => 2,
            'negligible' => 1
        ];

        foreach ($severityMappings as $severity => $expectedImpact) {
            $incident = $this->createMock(Incident::class);
            $incident->method('getTitle')->willReturn('Test Incident');
            $incident->method('getIncidentNumber')->willReturn('INC-001');
            $incident->method('getCategory')->willReturn('test');
            $incident->method('getSeverity')->willReturn($severity);
            $incident->method('getRootCause')->willReturn('Test cause');

            $this->incidentRepo = $this->createMock(IncidentRepository::class);
            $this->incidentRepo->method('findAll')->willReturn([$incident]);
            $this->incidentRepo->method('count')->willReturn(0);

            $service = new RiskIntelligenceService($this->incidentRepo, $this->controlRepo);
            $result = $service->suggestRisksFromIncidents();

            $this->assertEquals($expectedImpact, $result[0]['suggested_risk']['impact'], "Failed for severity: $severity");
        }
    }

    public function testSuggestRisksEstimatesProbabilityFromIncidentCount(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getTitle')->willReturn('Recurring Issue');
        $incident->method('getIncidentNumber')->willReturn('INC-001');
        $incident->method('getCategory')->willReturn('cyber_attack');
        $incident->method('getSeverity')->willReturn('medium');
        $incident->method('getRootCause')->willReturn('Known vulnerability');

        $this->incidentRepo->method('findAll')->willReturn([$incident]);

        // Test different incident counts
        $countToProbability = [
            6 => 5,  // > 5 incidents
            4 => 4,  // > 3 incidents
            2 => 3,  // > 1 incidents
            1 => 2,  // > 0 incidents
            0 => 1   // 0 incidents
        ];

        foreach ($countToProbability as $count => $expectedProbability) {
            $this->incidentRepo = $this->createMock(IncidentRepository::class);
            $this->incidentRepo->method('findAll')->willReturn([$incident]);
            $this->incidentRepo->method('count')->willReturn($count);

            $service = new RiskIntelligenceService($this->incidentRepo, $this->controlRepo);
            $result = $service->suggestRisksFromIncidents();

            if (!empty($result)) {
                $this->assertEquals(
                    $expectedProbability,
                    $result[0]['suggested_risk']['probability'],
                    "Failed for count: $count"
                );
            }
        }
    }

    public function testCalculateResidualRiskWithNoControls(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(20);
        $risk->method('getControls')->willReturn(new ArrayCollection());

        $result = $this->service->calculateResidualRisk($risk);

        $this->assertEquals(20, $result['inherent']);
        $this->assertEquals(20, $result['residual']); // No reduction
        $this->assertEquals(0, $result['reduction']);
        $this->assertEquals(0, $result['controls_applied']);
        $this->assertStringContainsString('Keine Controls', $result['recommendation']);
    }

    public function testCalculateResidualRiskWithImplementedControls(): void
    {
        $control1 = $this->createMock(Control::class);
        $control1->method('getImplementationStatus')->willReturn('implemented');
        $control1->method('getImplementationPercentage')->willReturn(100);

        $control2 = $this->createMock(Control::class);
        $control2->method('getImplementationStatus')->willReturn('implemented');
        $control2->method('getImplementationPercentage')->willReturn(80);

        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(20);
        $risk->method('getControls')->willReturn(new ArrayCollection([$control1, $control2]));

        $result = $this->service->calculateResidualRisk($risk);

        $this->assertEquals(20, $result['inherent']);
        $this->assertLessThan(20, $result['residual']); // Should be reduced
        $this->assertGreaterThan(0, $result['reduction']);
        $this->assertEquals(2, $result['controls_applied']);
        $this->assertEquals(2, $result['controls_total']);
        $this->assertGreaterThan(0, $result['reduction_percentage']);
    }

    public function testCalculateResidualRiskWithInProgressControls(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('getImplementationStatus')->willReturn('in_progress');
        $control->method('getImplementationPercentage')->willReturn(50);

        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(20);
        $risk->method('getControls')->willReturn(new ArrayCollection([$control]));

        $result = $this->service->calculateResidualRisk($risk);

        $this->assertEquals(20, $result['inherent']);
        $this->assertLessThan(20, $result['residual']); // Still some reduction
        $this->assertEquals(0, $result['controls_applied']); // Not fully implemented
        $this->assertStringContainsString('in Planung', $result['recommendation']);
    }

    public function testCalculateResidualRiskCapsReductionAt80Percent(): void
    {
        // Create many implemented controls to test cap
        $controls = [];
        for ($i = 0; $i < 10; $i++) {
            $control = $this->createMock(Control::class);
            $control->method('getImplementationStatus')->willReturn('implemented');
            $control->method('getImplementationPercentage')->willReturn(100);
            $controls[] = $control;
        }

        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(20);
        $risk->method('getControls')->willReturn(new ArrayCollection($controls));

        $result = $this->service->calculateResidualRisk($risk);

        // Maximum reduction is 80%, so residual should be at least 20% of inherent
        $this->assertGreaterThanOrEqual(4, $result['residual']); // 20 * 0.2 = 4
        $this->assertLessThanOrEqual(80, $result['reduction_percentage']);
    }

    public function testCalculateResidualRiskRecommendationsBasedOnResidual(): void
    {
        $testCases = [
            ['inherent' => 25, 'expectedPhrase' => 'kritisch'],      // residual > 12
            ['inherent' => 15, 'expectedPhrase' => 'moderat'],       // residual > 6
            ['inherent' => 10, 'expectedPhrase' => 'akzeptabel']     // residual <= 6
        ];

        foreach ($testCases as $testCase) {
            $risk = $this->createMock(Risk::class);
            $risk->method('getInherentRiskLevel')->willReturn($testCase['inherent']);
            $risk->method('getControls')->willReturn(new ArrayCollection()); // No controls

            $result = $this->service->calculateResidualRisk($risk);

            $this->assertStringContainsString(
                $testCase['expectedPhrase'],
                $result['recommendation'],
                "Failed for inherent risk: {$testCase['inherent']}"
            );
        }
    }

    public function testSuggestControlsForRiskWithNoSimilarIncidents(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getImpact')->willReturn(3);
        $risk->method('getDescription')->willReturn('Test risk');
        $risk->method('getControls')->willReturn(new ArrayCollection());

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([]);

        $this->incidentRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->suggestControlsForRisk($risk);

        $this->assertEmpty($result);
    }

    public function testSuggestControlsForRiskSuggestsRelevantControls(): void
    {
        $control1 = $this->createMock(Control::class);
        $control1->method('getControlId')->willReturn('A.5.1');
        $control1->method('getName')->willReturn('Access Control');

        $control2 = $this->createMock(Control::class);
        $control2->method('getControlId')->willReturn('A.8.1');
        $control2->method('getName')->willReturn('Asset Management');

        $incident = $this->createMock(Incident::class);
        $incident->method('getIncidentNumber')->willReturn('INC-001');
        $incident->method('getTitle')->willReturn('Data Breach');
        $incident->method('getRelatedControls')->willReturn(new ArrayCollection([$control1, $control2]));

        $risk = $this->createMock(Risk::class);
        $risk->method('getImpact')->willReturn(4);
        $risk->method('getDescription')->willReturn('Potential data breach');
        $risk->method('getControls')->willReturn(new ArrayCollection()); // No existing controls

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([$incident]);

        $this->incidentRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->suggestControlsForRisk($risk);

        $this->assertNotEmpty($result);
        $this->assertLessThanOrEqual(5, count($result)); // Max 5 suggestions
        $this->assertArrayHasKey('control', $result[0]);
        $this->assertArrayHasKey('reason', $result[0]);
        $this->assertStringContainsString('INC-001', $result[0]['reason']);
    }

    public function testSuggestControlsForRiskDoesNotSuggestExistingControls(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('getControlId')->willReturn('A.5.1');

        $incident = $this->createMock(Incident::class);
        $incident->method('getIncidentNumber')->willReturn('INC-001');
        $incident->method('getTitle')->willReturn('Test Incident');
        $incident->method('getRelatedControls')->willReturn(new ArrayCollection([$control]));

        $risk = $this->createMock(Risk::class);
        $risk->method('getImpact')->willReturn(3);
        $risk->method('getDescription')->willReturn('Test risk');
        // Risk already has this control - ArrayCollection's contains() will return true automatically
        $risk->method('getControls')->willReturn(new ArrayCollection([$control]));

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([$incident]);

        $this->incidentRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->suggestControlsForRisk($risk);

        // Should not suggest controls that risk already has
        $this->assertEmpty($result);
    }

    public function testAnalyzeIncidentTrendsWithNoIncidents(): void
    {
        $this->incidentRepo->method('findAll')->willReturn([]);

        $result = $this->service->analyzeIncidentTrends();

        $this->assertArrayHasKey('by_category', $result);
        $this->assertArrayHasKey('by_severity', $result);
        $this->assertArrayHasKey('by_month', $result);
        $this->assertArrayHasKey('recurring_patterns', $result);
        $this->assertEmpty($result['by_category']);
        $this->assertEmpty($result['by_severity']);
        $this->assertEmpty($result['by_month']);
    }

    public function testAnalyzeIncidentTrendsGroupsByCategory(): void
    {
        $incident1 = $this->createMock(Incident::class);
        $incident1->method('getCategory')->willReturn('cyber_attack');
        $incident1->method('getSeverity')->willReturn('high');
        $incident1->method('getDetectedAt')->willReturn(new \DateTime('2024-01-15'));

        $incident2 = $this->createMock(Incident::class);
        $incident2->method('getCategory')->willReturn('cyber_attack');
        $incident2->method('getSeverity')->willReturn('medium');
        $incident2->method('getDetectedAt')->willReturn(new \DateTime('2024-01-20'));

        $incident3 = $this->createMock(Incident::class);
        $incident3->method('getCategory')->willReturn('data_breach');
        $incident3->method('getSeverity')->willReturn('critical');
        $incident3->method('getDetectedAt')->willReturn(new \DateTime('2024-02-10'));

        $this->incidentRepo->method('findAll')->willReturn([$incident1, $incident2, $incident3]);

        $result = $this->service->analyzeIncidentTrends();

        $this->assertEquals(2, $result['by_category']['cyber_attack']);
        $this->assertEquals(1, $result['by_category']['data_breach']);
    }

    public function testAnalyzeIncidentTrendsGroupsBySeverity(): void
    {
        $incident1 = $this->createMock(Incident::class);
        $incident1->method('getCategory')->willReturn('test');
        $incident1->method('getSeverity')->willReturn('high');
        $incident1->method('getDetectedAt')->willReturn(new \DateTime('2024-01-15'));

        $incident2 = $this->createMock(Incident::class);
        $incident2->method('getCategory')->willReturn('test');
        $incident2->method('getSeverity')->willReturn('high');
        $incident2->method('getDetectedAt')->willReturn(new \DateTime('2024-01-20'));

        $incident3 = $this->createMock(Incident::class);
        $incident3->method('getCategory')->willReturn('test');
        $incident3->method('getSeverity')->willReturn('medium');
        $incident3->method('getDetectedAt')->willReturn(new \DateTime('2024-02-10'));

        $this->incidentRepo->method('findAll')->willReturn([$incident1, $incident2, $incident3]);

        $result = $this->service->analyzeIncidentTrends();

        $this->assertEquals(2, $result['by_severity']['high']);
        $this->assertEquals(1, $result['by_severity']['medium']);
    }

    public function testAnalyzeIncidentTrendsGroupsByMonth(): void
    {
        $incident1 = $this->createMock(Incident::class);
        $incident1->method('getCategory')->willReturn('test');
        $incident1->method('getSeverity')->willReturn('high');
        $incident1->method('getDetectedAt')->willReturn(new \DateTime('2024-01-15'));

        $incident2 = $this->createMock(Incident::class);
        $incident2->method('getCategory')->willReturn('test');
        $incident2->method('getSeverity')->willReturn('high');
        $incident2->method('getDetectedAt')->willReturn(new \DateTime('2024-01-20'));

        $incident3 = $this->createMock(Incident::class);
        $incident3->method('getCategory')->willReturn('test');
        $incident3->method('getSeverity')->willReturn('medium');
        $incident3->method('getDetectedAt')->willReturn(new \DateTime('2024-02-10'));

        $this->incidentRepo->method('findAll')->willReturn([$incident1, $incident2, $incident3]);

        $result = $this->service->analyzeIncidentTrends();

        $this->assertEquals(2, $result['by_month']['2024-01']);
        $this->assertEquals(1, $result['by_month']['2024-02']);
    }

    public function testAnalyzeIncidentTrendsSortsCategoriesByFrequency(): void
    {
        $incidents = [];
        // Create 5 cyber_attack incidents
        for ($i = 0; $i < 5; $i++) {
            $incident = $this->createMock(Incident::class);
            $incident->method('getCategory')->willReturn('cyber_attack');
            $incident->method('getSeverity')->willReturn('high');
            $incident->method('getDetectedAt')->willReturn(new \DateTime('2024-01-15'));
            $incidents[] = $incident;
        }

        // Create 2 data_breach incidents
        for ($i = 0; $i < 2; $i++) {
            $incident = $this->createMock(Incident::class);
            $incident->method('getCategory')->willReturn('data_breach');
            $incident->method('getSeverity')->willReturn('medium');
            $incident->method('getDetectedAt')->willReturn(new \DateTime('2024-01-15'));
            $incidents[] = $incident;
        }

        $this->incidentRepo->method('findAll')->willReturn($incidents);

        $result = $this->service->analyzeIncidentTrends();

        // Should be sorted by count (descending)
        $categories = array_keys($result['by_category']);
        $this->assertEquals('cyber_attack', $categories[0]); // Most frequent first
        $this->assertEquals('data_breach', $categories[1]);
    }

    public function testAnalyzeIncidentTrendsSortsMonthsChronologically(): void
    {
        $incident1 = $this->createMock(Incident::class);
        $incident1->method('getCategory')->willReturn('test');
        $incident1->method('getSeverity')->willReturn('high');
        $incident1->method('getDetectedAt')->willReturn(new \DateTime('2024-03-15'));

        $incident2 = $this->createMock(Incident::class);
        $incident2->method('getCategory')->willReturn('test');
        $incident2->method('getSeverity')->willReturn('medium');
        $incident2->method('getDetectedAt')->willReturn(new \DateTime('2024-01-20'));

        $incident3 = $this->createMock(Incident::class);
        $incident3->method('getCategory')->willReturn('test');
        $incident3->method('getSeverity')->willReturn('low');
        $incident3->method('getDetectedAt')->willReturn(new \DateTime('2024-02-10'));

        $this->incidentRepo->method('findAll')->willReturn([$incident1, $incident2, $incident3]);

        $result = $this->service->analyzeIncidentTrends();

        // Should be sorted chronologically (ascending)
        $months = array_keys($result['by_month']);
        $this->assertEquals('2024-01', $months[0]);
        $this->assertEquals('2024-02', $months[1]);
        $this->assertEquals('2024-03', $months[2]);
    }
}
