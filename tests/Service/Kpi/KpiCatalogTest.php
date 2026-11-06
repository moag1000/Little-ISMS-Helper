<?php

declare(strict_types=1);

namespace App\Tests\Service\Kpi;

use App\Entity\Tenant;
use App\Service\DashboardStatisticsService;
use App\Service\Kpi\KpiCatalog;
use App\Service\Kpi\RiskExposure;
use App\Service\KpiThresholdConfigResolver;
use App\Service\KpiThresholdView;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
final class KpiCatalogTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        class_exists(KpiThresholdConfigResolver::class); // KpiThresholdView lives in the resolver's file
        $this->tenant = $this->createMock(Tenant::class);
        $this->tenant->method('getId')->willReturn(7);
    }

    private function catalog(KpiThresholdView $thresholds, bool $quantActive = true, bool $mayseeMoney = true): KpiCatalog
    {
        $statistics = $this->createMock(DashboardStatisticsService::class);
        $statistics->method('getManagementKPIs')->willReturn([
            'core' => [
                'control_compliance' => ['label' => 'kpi.control_compliance', 'value' => 75, 'unit' => '%', 'status' => 'warning'],
                'controls_implemented' => ['label' => 'kpi.controls_implemented', 'value' => 70, 'unit' => '', 'status' => 'info'],
            ],
            'per_framework' => [
                'iso27001' => ['label' => 'ISO 27001', 'value' => 75, 'unit' => '%', 'status' => 'warning'],
            ],
            'active_modules' => ['risks'],
        ]);

        $resolver = $this->createMock(KpiThresholdConfigResolver::class);
        $resolver->method('resolveFor')->willReturn($thresholds);

        $exposure = $this->createMock(RiskExposure::class);
        $exposure->method('forTenant')->willReturn([
            'total' => 150000,
            'quantified' => 2,
            'open' => 3,
            'top' => [
                ['id' => 1, 'title' => 'Ransomware', 'ale' => 100000, 'level' => 20],
                ['id' => 2, 'title' => 'Ausfall RZ', 'ale' => 50000, 'level' => 12],
            ],
        ]);

        $modules = $this->createMock(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturn($quantActive);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($mayseeMoney);

        return new KpiCatalog($statistics, $resolver, $exposure, $modules, $security);
    }

    #[Test]
    public function organisationThresholdsDecideTheTrafficLight(): void
    {
        $kpis = $this->catalog(new KpiThresholdView(70, 50))->managementKpis($this->tenant);

        self::assertSame('good', $kpis['core']['control_compliance']['status'], '75 % is good against a 70 % target');
        self::assertSame('good', $kpis['per_framework']['iso27001']['status'], 'framework coverage follows control_compliance');
        self::assertSame('info', $kpis['core']['controls_implemented']['status'], 'counts keep their own status');
    }

    #[Test]
    public function stricterThresholdsTurnTheLightRed(): void
    {
        $kpis = $this->catalog(new KpiThresholdView(95, 90))->managementKpis($this->tenant);

        self::assertSame('danger', $kpis['core']['control_compliance']['status']);
    }

    #[Test]
    public function euroKpisComeFromTheQuantFields(): void
    {
        $kpis = $this->catalog(new KpiThresholdView(80, 60))->managementKpis($this->tenant);

        self::assertSame(150000, $kpis[KpiCatalog::SECTION_RISK_FINANCE]['risk_exposure_eur']['value']);
        self::assertSame(150000, $kpis[KpiCatalog::SECTION_RISK_FINANCE]['risk_top5_eur']['value']);
        self::assertSame(2, $kpis[KpiCatalog::SECTION_RISK_FINANCE]['risk_exposure_eur']['details']['quantified']);
        self::assertSame('Ransomware', $kpis[KpiCatalog::SECTION_RISK_FINANCE]['risk_top5_eur']['details']['top'][0]['title']);
    }

    #[Test]
    public function euroKpisNeedTheModuleAndTheRole(): void
    {
        self::assertArrayNotHasKey(KpiCatalog::SECTION_RISK_FINANCE, $this->catalog(new KpiThresholdView(80, 60), quantActive: false)->managementKpis($this->tenant));
        self::assertArrayNotHasKey(KpiCatalog::SECTION_RISK_FINANCE, $this->catalog(new KpiThresholdView(80, 60), mayseeMoney: false)->managementKpis($this->tenant));
    }

    #[Test]
    public function withoutTenantThereAreNoKpis(): void
    {
        self::assertSame([], $this->catalog(new KpiThresholdView(80, 60))->managementKpis());
        self::assertNull($this->catalog(new KpiThresholdView(80, 60))->get('control_compliance'));
    }
}
