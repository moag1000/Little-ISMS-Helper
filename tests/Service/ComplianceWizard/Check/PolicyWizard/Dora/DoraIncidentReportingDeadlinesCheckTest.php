<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\IncidentSlaConfig;
use App\Entity\Tenant;
use App\Repository\IncidentSlaConfigRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraIncidentReportingDeadlinesCheck;
use App\Service\IncidentSlaConfigResolver;
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
        $this->check = new DoraIncidentReportingDeadlinesCheck(new IncidentSlaConfigResolver($this->slaRepository));
    }

    #[Test]
    public function testPassesWhenConditionsMet(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // All severities exactly at the DORA ceiling.
        $this->stubRows([
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
    public function testFailsWhenSlaExceedsCeiling(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // critical exceeds 4h (8h), breach exceeds 720h, high tight enough.
        $this->stubRows([
            $this->makeSlaConfig(IncidentSlaConfig::SEVERITY_CRITICAL, 8),
            $this->makeSlaConfig(IncidentSlaConfig::SEVERITY_HIGH, 24),
            $this->makeSlaConfig(IncidentSlaConfig::SEVERITY_BREACH, 800),
        ]);

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertNotNull($result->gap);
        self::assertCount(2, $result->details['violations']);
    }

    #[Test]
    public function testMissingRowsUseTheEffectiveDefaults(): void
    {
        // A new tenant without SLA rows runs on IncidentSlaConfig::DEFAULTS,
        // which meet the DORA ceilings — no false gap.
        $this->stubRows([]);

        self::assertTrue($this->check->run($this->createMock(Tenant::class))->passed);
    }

    #[Test]
    public function testGapMessageActionable(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->stubRows([$this->makeSlaConfig(IncidentSlaConfig::SEVERITY_CRITICAL, 24)]);

        $result = $this->check->run($tenant);

        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
        self::assertSame('app_admin_incident_sla_config', $result->gap['route']);
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

    /**
     * @param list<IncidentSlaConfig> $rows
     */
    private function stubRows(array $rows): void
    {
        $bySeverity = [];
        foreach ($rows as $row) {
            $bySeverity[$row->getSeverity()] = $row;
        }
        $this->slaRepository->method('findByTenantAndSeverity')->willReturnCallback(
            static fn (Tenant $tenant, string $severity): ?IncidentSlaConfig => $bySeverity[$severity] ?? null,
        );
    }

    private function makeSlaConfig(string $severity, int $responseHours): IncidentSlaConfig
    {
        $cfg = new IncidentSlaConfig();
        $cfg->setSeverity($severity);
        $cfg->setResponseHours($responseHours);
        return $cfg;
    }
}
