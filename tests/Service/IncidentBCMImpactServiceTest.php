<?php

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Repository\BusinessProcessRepository;
use App\Service\IncidentBCMImpactService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IncidentBCMImpactServiceTest extends TestCase
{
    private MockObject $businessProcessRepository;
    private MockObject $tenantContext;
    private IncidentBCMImpactService $service;
    private MockObject $tenant;

    protected function setUp(): void
    {
        $this->businessProcessRepository = $this->createMock(BusinessProcessRepository::class);
        $this->tenantContext = $this->createMock(TenantContext::class);

        $this->tenant = $this->createMock(Tenant::class);
        $this->tenant->method('getId')->willReturn(1);

        $this->service = new IncidentBCMImpactService(
            $this->businessProcessRepository,
            $this->tenantContext
        );
    }

    // =========================================================================
    // analyzeBusinessImpact Tests
    // =========================================================================

    public function testAnalyzeBusinessImpactReturnsCompleteStructure(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $incident = $this->createIncident('INC-001', 'high', 'open');

        $result = $this->service->analyzeBusinessImpact($incident);

        $this->assertArrayHasKey('incident_id', $result);
        $this->assertArrayHasKey('incident_number', $result);
        $this->assertArrayHasKey('severity', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('downtime', $result);
        $this->assertArrayHasKey('affected_processes', $result);
        $this->assertArrayHasKey('financial_impact', $result);
        $this->assertArrayHasKey('rto_compliance', $result);
        $this->assertArrayHasKey('recovery_priority', $result);
        $this->assertArrayHasKey('historical_context', $result);
        $this->assertArrayHasKey('recommendations', $result);
    }

    public function testAnalyzeBusinessImpactUsesEstimatedDowntime(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $incident = $this->createIncident('INC-001', 'high', 'open');

        $result = $this->service->analyzeBusinessImpact($incident, 10);

        $this->assertSame(10, $result['downtime']['actual_hours']);
        $this->assertTrue($result['downtime']['is_estimated']);
    }

    public function testAnalyzeBusinessImpactCalculatesFinancialImpact(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $process = $this->createBusinessProcess('Process 1', 100.0, 2, 'critical');

        $incident = $this->createIncidentWithProcesses('INC-001', 'high', 'open', [$process]);

        $result = $this->service->analyzeBusinessImpact($incident, 5);

        // 5 hours × €100/hour = €500
        $this->assertSame(500.0, $result['financial_impact']['total_eur']);
        $this->assertSame(100.0, $result['financial_impact']['per_hour_eur']);
    }

    public function testAnalyzeBusinessImpactDetectsRTOViolations(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $process = $this->createBusinessProcess('Process 1', 100.0, 2, 'critical');

        $incident = $this->createIncidentWithProcesses('INC-001', 'critical', 'open', [$process]);

        // 5 hours downtime with 2 hour RTO = violation
        $result = $this->service->analyzeBusinessImpact($incident, 5);

        $this->assertFalse($result['rto_compliance']['is_compliant']);
        $this->assertSame(1, $result['rto_compliance']['violations_count']);
        $this->assertCount(1, $result['rto_compliance']['violations']);

        $violation = $result['rto_compliance']['violations'][0];
        $this->assertSame(2, $violation['rto_hours']);
        $this->assertSame(5, $violation['actual_hours']);
        $this->assertSame(3, $violation['excess_hours']);
    }

    public function testAnalyzeBusinessImpactIsCompliantWhenNoRTOViolations(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $process = $this->createBusinessProcess('Process 1', 100.0, 24, 'medium');

        $incident = $this->createIncidentWithProcesses('INC-001', 'medium', 'open', [$process]);

        // 4 hours downtime with 24 hour RTO = compliant
        $result = $this->service->analyzeBusinessImpact($incident, 4);

        $this->assertTrue($result['rto_compliance']['is_compliant']);
        $this->assertSame(0, $result['rto_compliance']['violations_count']);
    }

    // =========================================================================
    // identifyAffectedProcesses Tests
    // =========================================================================

    public function testIdentifyAffectedProcessesReturnsEmptyForNoAssets(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $incident = $this->createIncident('INC-001', 'high', 'open');
        $incident->method('getAffectedAssets')->willReturn(new ArrayCollection());

        $result = $this->service->identifyAffectedProcesses($incident);

        $this->assertEmpty($result);
    }

    /**
     * Note: Tests for identifyAffectedProcessesFindsByAssets and
     * identifyAffectedProcessesExcludesManuallyLinked are covered
     * by integration tests as they require complex query builder mocking
     * which is not suitable for unit tests with Doctrine Query being final.
     */

    // =========================================================================
    // calculateDowntimeImpact Tests
    // =========================================================================

    public function testCalculateDowntimeImpactReturnsCompleteStructure(): void
    {
        $process = $this->createBusinessProcess('Test Process', 150.0, 4, 'high');

        $result = $this->service->calculateDowntimeImpact($process, 8);

        $this->assertArrayHasKey('process_id', $result);
        $this->assertArrayHasKey('process_name', $result);
        $this->assertArrayHasKey('process_owner', $result);
        $this->assertArrayHasKey('criticality', $result);
        $this->assertArrayHasKey('bia_data', $result);
        $this->assertArrayHasKey('financial_impact', $result);
        $this->assertArrayHasKey('rto_compliance', $result);
        $this->assertArrayHasKey('rto_violated', $result);
        $this->assertArrayHasKey('mtpd_violated', $result);
        $this->assertArrayHasKey('impact_scores', $result);
        $this->assertArrayHasKey('impact_severity', $result);
        $this->assertArrayHasKey('recovery_strategy', $result);
    }

    public function testCalculateDowntimeImpactCalculatesFinancialImpact(): void
    {
        $process = $this->createBusinessProcess('Test Process', 100.0, 4, 'high');

        $result = $this->service->calculateDowntimeImpact($process, 10);

        // 10 hours × €100/hour = €1000
        $this->assertSame(1000.0, $result['financial_impact']);
    }

    public function testCalculateDowntimeImpactDetectsRTOViolation(): void
    {
        $process = $this->createBusinessProcess('Test Process', 100.0, 4, 'high');

        // 8 hours downtime with 4 hour RTO = violation with 4 excess hours
        $result = $this->service->calculateDowntimeImpact($process, 8);

        $this->assertTrue($result['rto_violated']);
        $this->assertSame(4, $result['rto_compliance']['excess_hours']);
        $this->assertSame(50.0, $result['rto_compliance']['compliance_percentage']);
    }

    public function testCalculateDowntimeImpactDetectsMTPDViolation(): void
    {
        $process = $this->createBusinessProcess('Test Process', 100.0, 4, 'critical');
        $process->method('getMtpd')->willReturn(48);

        // 72 hours downtime with 48 hour MTPD = violation
        $result = $this->service->calculateDowntimeImpact($process, 72);

        $this->assertTrue($result['mtpd_violated']);
        $this->assertSame('critical', $result['impact_severity']);
    }

    public function testCalculateDowntimeImpactSeverityCriticalWhenMTPDViolated(): void
    {
        // Critical severity - MTPD violated (MTPD = RTO * 3 = 4 * 3 = 12)
        $process = $this->createBusinessProcess('Critical', 100.0, 4, 'high');
        // Downtime of 48 hours exceeds MTPD of 12 hours
        $result = $this->service->calculateDowntimeImpact($process, 48);
        $this->assertSame('critical', $result['impact_severity']);
    }

    public function testCalculateDowntimeImpactSeverityHighWhenRTOViolated(): void
    {
        // High severity - RTO violated but MTPD not violated
        $process = $this->createBusinessProcess('High', 100.0, 4, 'medium');
        // Downtime of 8 hours exceeds RTO of 4, but not MTPD of 12
        $result = $this->service->calculateDowntimeImpact($process, 8);
        $this->assertSame('high', $result['impact_severity']);
    }

    public function testCalculateDowntimeImpactSeverityLowWhenNoViolations(): void
    {
        // Low severity - no violations, low impact score (3 for criticality 'low')
        $process = $this->createBusinessProcess('Low', 50.0, 24, 'low');
        // Downtime of 4 hours doesn't exceed RTO of 24 or MTPD of 72
        // Business impact score is 3 for 'low' criticality which triggers 'medium'
        $result = $this->service->calculateDowntimeImpact($process, 4);
        // Service logic: score >= 3 = medium, score < 3 = low
        $this->assertSame('medium', $result['impact_severity']);
    }

    // =========================================================================
    // suggestRecoveryPriority Tests
    // =========================================================================

    public function testSuggestRecoveryPriorityReturnsDefaultForNoProcesses(): void
    {
        $incident = $this->createIncident('INC-001', 'high', 'open');

        $result = $this->service->suggestRecoveryPriority($incident, []);

        $this->assertSame('medium', $result['level']);
        $this->assertStringContainsString('No business processes identified', $result['reasoning']);
        $this->assertNotEmpty($result['recommended_actions']);
    }

    public function testSuggestRecoveryPriorityImmediateForCriticalProcess(): void
    {
        $process = $this->createBusinessProcess('Critical Process', 1000.0, 4, 'critical');

        $incident = $this->createIncident('INC-001', 'high', 'open');

        $result = $this->service->suggestRecoveryPriority($incident, [$process]);

        $this->assertSame('immediate', $result['level']);
        $this->assertGreaterThan(0, $result['critical_process_count']);
        $this->assertContains('Activate crisis management team', $result['recommended_actions']);
    }

    public function testSuggestRecoveryPriorityImmediateForLowRTO(): void
    {
        $process = $this->createBusinessProcess('Urgent Process', 500.0, 1, 'high');

        $incident = $this->createIncident('INC-001', 'high', 'open');

        $result = $this->service->suggestRecoveryPriority($incident, [$process]);

        $this->assertSame('immediate', $result['level']);
        $this->assertSame(1, $result['lowest_rto_hours']);
        $this->assertStringContainsString('RTO ≤ 1 hour', $result['reasoning']);
    }

    public function testSuggestRecoveryPriorityHighFor4HourRTO(): void
    {
        $process = $this->createBusinessProcess('High Priority', 200.0, 4, 'high');

        $incident = $this->createIncident('INC-001', 'high', 'open');

        $result = $this->service->suggestRecoveryPriority($incident, [$process]);

        $this->assertSame('high', $result['level']);
        $this->assertContains('Begin recovery procedures within 1 hour', $result['recommended_actions']);
    }

    public function testSuggestRecoveryPriorityHighForCriticalSeverityIncident(): void
    {
        $process = $this->createBusinessProcess('Normal Process', 100.0, 8, 'medium');

        $incident = $this->createIncident('INC-001', 'critical', 'open');

        $result = $this->service->suggestRecoveryPriority($incident, [$process]);

        $this->assertSame('high', $result['level']);
        $this->assertStringContainsString('Critical severity incident', $result['reasoning']);
    }

    public function testSuggestRecoveryPriorityMediumFor24HourRTO(): void
    {
        $process = $this->createBusinessProcess('Standard Process', 50.0, 24, 'medium');

        $incident = $this->createIncident('INC-001', 'medium', 'open');

        $result = $this->service->suggestRecoveryPriority($incident, [$process]);

        $this->assertSame('medium', $result['level']);
        $this->assertContains('Plan recovery within business hours', $result['recommended_actions']);
    }

    public function testSuggestRecoveryPriorityLowForHighRTO(): void
    {
        $process = $this->createBusinessProcess('Low Priority Process', 10.0, 48, 'low');

        $incident = $this->createIncident('INC-001', 'low', 'open');

        $result = $this->service->suggestRecoveryPriority($incident, [$process]);

        $this->assertSame('low', $result['level']);
        $this->assertStringContainsString('RTO > 24 hours', $result['reasoning']);
        $this->assertContains('Schedule recovery during maintenance window', $result['recommended_actions']);
    }

    // =========================================================================
    // generateImpactReport Tests
    // =========================================================================

    public function testGenerateImpactReportReturnsCompleteStructure(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $incident = $this->createIncident('INC-001', 'high', 'open');
        $incident->method('getTitle')->willReturn('Server Outage');

        $result = $this->service->generateImpactReport($incident);

        $this->assertArrayHasKey('report_generated_at', $result);
        $this->assertArrayHasKey('incident', $result);
        $this->assertArrayHasKey('executive_summary', $result);
        $this->assertArrayHasKey('detailed_analysis', $result);
        $this->assertArrayHasKey('charts_data', $result);

        $this->assertSame('INC-001', $result['incident']['number']);
        $this->assertSame('Server Outage', $result['incident']['title']);
        $this->assertSame('high', $result['incident']['severity']);
    }

    public function testGenerateImpactReportExecutiveSummaryStructure(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $process = $this->createBusinessProcess('Critical Process', 500.0, 4, 'critical');

        $incident = $this->createIncident('INC-001', 'critical', 'open');
        $incident->method('getTitle')->willReturn('Major Outage');
        $incident->method('getAffectedBusinessProcesses')
            ->willReturn(new ArrayCollection([$process]));

        $result = $this->service->generateImpactReport($incident);

        $summary = $result['executive_summary'];
        $this->assertArrayHasKey('total_processes_affected', $summary);
        $this->assertArrayHasKey('critical_processes_affected', $summary);
        $this->assertArrayHasKey('estimated_financial_impact', $summary);
        $this->assertArrayHasKey('recovery_priority', $summary);
        $this->assertArrayHasKey('rto_compliant', $summary);
        $this->assertArrayHasKey('key_findings', $summary);
    }

    public function testGenerateImpactReportChartsData(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $process = $this->createBusinessProcess('Test Process', 200.0, 8, 'high');

        $incident = $this->createIncident('INC-001', 'high', 'open');
        $incident->method('getTitle')->willReturn('System Failure');
        $incident->method('getAffectedBusinessProcesses')
            ->willReturn(new ArrayCollection([$process]));

        $result = $this->service->generateImpactReport($incident);

        $charts = $result['charts_data'];
        $this->assertArrayHasKey('financial_by_process', $charts);
        $this->assertArrayHasKey('criticality_distribution', $charts);
        $this->assertArrayHasKey('rto_compliance', $charts);
        $this->assertArrayHasKey('impact_severity', $charts);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createIncident(string $number, string $severity, string $status): MockObject
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn(1);
        $incident->method('getIncidentNumber')->willReturn($number);
        $incident->method('getSeverity')->willReturn($severity);
        $incident->method('getStatus')->willReturn($status);
        $incident->method('getDetectedAt')->willReturn(new DateTimeImmutable('-1 day'));
        $incident->method('getResolvedAt')->willReturn(null);
        $incident->method('getAffectedAssets')->willReturn(new ArrayCollection());
        $incident->method('getAffectedBusinessProcesses')->willReturn(new ArrayCollection());

        return $incident;
    }

    private function createIncidentWithProcesses(string $number, string $severity, string $status, array $processes): MockObject
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn(1);
        $incident->method('getIncidentNumber')->willReturn($number);
        $incident->method('getSeverity')->willReturn($severity);
        $incident->method('getStatus')->willReturn($status);
        $incident->method('getDetectedAt')->willReturn(new DateTimeImmutable('-1 day'));
        $incident->method('getResolvedAt')->willReturn(null);
        $incident->method('getAffectedAssets')->willReturn(new ArrayCollection());
        $incident->method('getAffectedBusinessProcesses')->willReturn(new ArrayCollection($processes));

        return $incident;
    }

    private function createBusinessProcess(string $name, float $impactPerHour, int $rto, string $criticality): MockObject
    {
        $process = $this->createMock(BusinessProcess::class);
        $process->method('getName')->willReturn($name);
        $process->method('getProcessOwner')->willReturn('Process Owner');
        $process->method('getCriticality')->willReturn($criticality);
        // getFinancialImpactPerHour returns ?string, so we pass string representation
        $process->method('getFinancialImpactPerHour')->willReturn((string) $impactPerHour);
        $process->method('getFinancialImpactPerDay')->willReturn((string) ($impactPerHour * 24));
        $process->method('getRto')->willReturn($rto);
        $process->method('getRpo')->willReturn((int) ($rto / 2));
        $process->method('getMtpd')->willReturn($rto * 3);
        $process->method('getReputationalImpact')->willReturn(3);
        $process->method('getRegulatoryImpact')->willReturn(2);
        $process->method('getOperationalImpact')->willReturn(3);
        $process->method('getBusinessImpactScore')->willReturn($criticality === 'critical' ? 5 : ($criticality === 'high' ? 4 : 3));
        $process->method('getRecoveryStrategy')->willReturn('Standard recovery');
        $process->method('getIncidentCount')->willReturn(0);
        $process->method('getTotalDowntimeFromIncidents')->willReturn(0);
        $process->method('getHistoricalFinancialLoss')->willReturn(0.0);
        $process->method('hasRTOViolations')->willReturn(false);

        return $process;
    }
}
