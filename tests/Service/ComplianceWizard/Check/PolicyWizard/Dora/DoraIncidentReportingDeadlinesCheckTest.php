<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\IncidentSlaConfig;
use App\Entity\Tenant;
use App\Repository\IncidentSlaConfigRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraIncidentReportingDeadlinesCheck;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DoraIncidentReportingDeadlinesCheckTest extends TestCase
{
    private IncidentSlaConfigRepository&MockObject $slaRepository;
    private DoraIncidentReportingDeadlinesCheck $check;

    protected function setUp(): void
    {
        $this->slaRepository = $this->createMock(IncidentSlaConfigRepository::class);
        $this->check = new DoraIncidentReportingDeadlinesCheck($this->slaRepository);
    }

    #[Test]
    public function testPassesWhenConditionsMet(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // All severities exactly at the DORA ceiling.
        $this->slaRepository->method('findByTenant')->willReturn([
            $this->makeSlaConfig(IncidentSlaConfig::SEVERITY_CRITICAL, 4),
            $this->makeSlaConfig(IncidentSlaConfig::SEVERITY_HIGH, 72),
            $this->makeSlaConfig(IncidentSlaConfig::SEVERITY_BREACH, 720),
        ]);

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertNull($result->gap);
    }

    #[Test]
    public function testFailsWhenConditionsMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // critical exceeds 4h (8h), breach row missing, high tight enough.
        $this->slaRepository->method('findByTenant')->willReturn([
            $this->makeSlaConfig(IncidentSlaConfig::SEVERITY_CRITICAL, 8),
            $this->makeSlaConfig(IncidentSlaConfig::SEVERITY_HIGH, 24),
        ]);

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertNotNull($result->gap);
        // Two violations: critical exceeds + breach missing.
        self::assertCount(2, $result->details['violations']);
    }

    #[Test]
    public function testGapMessageActionable(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->slaRepository->method('findByTenant')->willReturn([]);

        $result = $this->check->run($tenant);

        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
        self::assertSame('app_admin_incident_sla_index', $result->gap['route']);
        self::assertSame('policy_wizard', $result->gap['translation_domain']);
        self::assertSame(
            'compliance_check.dora_incident_reporting_deadlines.fail_message',
            $result->gap['title'],
        );

        // Pin the regulatory ceilings — bumping these is a deliberate code change.
        self::assertSame(4, DoraIncidentReportingDeadlinesCheck::REQUIRED_DEADLINES_HOURS[IncidentSlaConfig::SEVERITY_CRITICAL]);
        self::assertSame(72, DoraIncidentReportingDeadlinesCheck::REQUIRED_DEADLINES_HOURS[IncidentSlaConfig::SEVERITY_HIGH]);
        self::assertSame(720, DoraIncidentReportingDeadlinesCheck::REQUIRED_DEADLINES_HOURS[IncidentSlaConfig::SEVERITY_BREACH]);
    }

    private function makeSlaConfig(string $severity, int $responseHours): IncidentSlaConfig
    {
        $cfg = new IncidentSlaConfig();
        $cfg->setSeverity($severity);
        $cfg->setResponseHours($responseHours);
        return $cfg;
    }
}
