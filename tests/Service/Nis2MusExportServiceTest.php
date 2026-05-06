<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use App\Service\Nis2MusExportService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class Nis2MusExportServiceTest extends TestCase
{
    private Nis2MusExportService $service;

    protected function setUp(): void
    {
        $this->service = new Nis2MusExportService();
    }

    #[Test]
    public function earlyWarningPayloadCarriesIncidentAndEntityMetadata(): void
    {
        $incident = $this->buildIncident();
        $payload = $this->service->buildEarlyWarningPayload($incident);

        $this->assertSame('bsi-mus/nis2-art23/v1', $payload['meta']['schema']);
        $this->assertSame('early_warning', $payload['meta']['phase']);
        $this->assertSame('Acme GmbH', $payload['entity']['name']);
        $this->assertSame('INC-001', $payload['incident']['incident_id']);
        $this->assertSame('high', $payload['incident']['preliminary_severity']);
        $this->assertSame('security', $payload['incident']['nis2_category']);
        $this->assertTrue($payload['incident']['cross_border_impact']);
        $this->assertTrue($payload['incident']['significant_incident_indicator']);
    }

    #[Test]
    public function detailedNotificationAddsAssessmentFields(): void
    {
        $incident = $this->buildIncident();
        $payload = $this->service->buildDetailedNotificationPayload($incident);

        $this->assertSame('detailed_notification', $payload['meta']['phase']);
        $this->assertSame(150, $payload['incident']['affected_users_count']);
        $this->assertSame(50000.0, $payload['incident']['estimated_financial_impact_eur']);
        $this->assertSame('Patched and isolated.', $payload['incident']['mitigation_taken']);
    }

    #[Test]
    public function finalReportAddsRootCauseAndLessonsLearned(): void
    {
        $incident = $this->buildIncident();
        $payload = $this->service->buildFinalReportPayload($incident);

        $this->assertSame('final_report', $payload['meta']['phase']);
        $this->assertSame('Unpatched VPN gateway.', $payload['incident']['root_cause']);
        $this->assertSame('Mandatory monthly patch SLA introduced.', $payload['incident']['lessons_learned']);
    }

    #[Test]
    public function deadlineStatusFlagsOverdueEarlyWarning(): void
    {
        $detected = new DateTimeImmutable('2026-04-30 09:00:00');
        $now = new DateTimeImmutable('2026-05-02 10:00:00');
        $incident = $this->buildIncident();
        $incident->setDetectedAt($detected);

        $status = $this->service->getDeadlineStatus($incident, $now);

        $this->assertTrue($status['early_warning']['overdue']);
        $this->assertFalse($status['detailed_notification']['overdue']);
        $this->assertFalse($status['final_report']['submitted']);
    }

    private function buildIncident(): Incident
    {
        $tenant = new Tenant();
        $tenant->setName('Acme GmbH');
        $tenant->setLegalName('Acme GmbH & Co. KG');
        $tenant->setNaceCode('62.01');
        $tenant->setNis2Classification('essential');
        $tenant->setNis2Sector('Digital Infrastructure');
        $tenant->setNis2ContactPoint('soc@acme.example');

        $incident = new Incident();
        $incident->setTenant($tenant);
        $incident->setIncidentNumber('INC-001');
        $incident->setTitle('VPN exploit attempt');
        $incident->setDescription('Suspicious authentication failures from foreign IPs.');
        $incident->setSeverity(IncidentSeverity::High);
        $incident->setStatus(IncidentStatus::InInvestigation);
        $incident->setNis2Category('security');
        $incident->setCrossBorderImpact(true);
        $incident->setAffectedUsersCount(150);
        $incident->setEstimatedFinancialImpact('50000.00');
        $incident->setDetectedAt(new DateTimeImmutable('2026-05-01 08:00:00'));
        $incident->setOccurredAt(new DateTimeImmutable('2026-05-01 06:00:00'));
        $incident->setCorrectiveActions('Patched and isolated.');
        $incident->setPreventiveActions('Mandatory monthly patch SLA introduced.');
        $incident->setRootCause('Unpatched VPN gateway.');
        $incident->setLessonsLearned('Mandatory monthly patch SLA introduced.');
        $incident->setResolvedAt(new DateTimeImmutable('2026-05-03 17:00:00'));

        return $incident;
    }
}
