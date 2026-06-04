<?php

declare(strict_types=1);

namespace App\Tests\Service\Fte;

use App\Entity\Tenant;
use App\Repository\Fte\FteTrackingMetricRepository;
use App\Service\Fte\BoardReportGenerator;
use App\Service\ModuleConfigurationService;
use App\Service\PdfExportService;
use App\Service\RiskQuantSummaryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Twig\Environment;

/**
 * F46: Board report ALE aggregate — verifies risk_quant module integration
 * in BoardReportGenerator::generateMonthly().
 */
class BoardReportQuantTest extends TestCase
{
    private BoardReportGenerator $generator;
    /** @var FteTrackingMetricRepository&MockObject */
    private FteTrackingMetricRepository $metricRepo;
    /** @var RiskQuantSummaryInterface&MockObject */
    private RiskQuantSummaryInterface $riskQuantSummary;
    /** @var ModuleConfigurationService&MockObject */
    private ModuleConfigurationService $moduleConfig;

    protected function setUp(): void
    {
        $this->metricRepo = $this->createMock(FteTrackingMetricRepository::class);
        $this->metricRepo->method('getSavingsAggregate')->willReturn(120);
        $this->metricRepo->method('getSavingsBySource')->willReturn([]);
        $this->metricRepo->method('getMonthlyTrend')->willReturn([]);

        $this->riskQuantSummary = $this->createMock(RiskQuantSummaryInterface::class);
        $this->moduleConfig = $this->createMock(ModuleConfigurationService::class);

        $twig = $this->createMock(Environment::class);
        $pdf = $this->createMock(PdfExportService::class);

        $this->generator = new BoardReportGenerator(
            $this->metricRepo,
            $pdf,
            $twig,
            $this->riskQuantSummary,
            $this->moduleConfig,
        );
    }

    #[Test]
    public function generateMonthlyIncludesAleWhenModuleActive(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $this->moduleConfig
            ->method('isModuleActive')
            ->with('risk_quant')
            ->willReturn(true);

        $this->riskQuantSummary
            ->method('getRiskQuantSummary')
            ->with($tenant)
            ->willReturn(['total_ale_eur' => 250000, 'quantified_risk_count' => 3]);

        $data = $this->generator->generateMonthly($tenant, new DateTimeImmutable('2026-06-01'));

        $this->assertTrue($data['risk_quant_active']);
        $this->assertSame(250000, $data['risk_quant']['total_ale_eur']);
        $this->assertSame(3, $data['risk_quant']['quantified_risk_count']);
    }

    #[Test]
    public function generateMonthlyExcludesAleWhenModuleInactive(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $this->moduleConfig
            ->method('isModuleActive')
            ->with('risk_quant')
            ->willReturn(false);

        $data = $this->generator->generateMonthly($tenant, new DateTimeImmutable('2026-06-01'));

        $this->assertFalse($data['risk_quant_active']);
        $this->assertSame(0, $data['risk_quant']['total_ale_eur']);
        $this->assertSame(0, $data['risk_quant']['quantified_risk_count']);
    }

    #[Test]
    public function generateMonthlyCsvIncludesAleLinesWhenActive(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $this->moduleConfig->method('isModuleActive')->willReturn(true);
        $this->riskQuantSummary->method('getRiskQuantSummary')->willReturn([
            'total_ale_eur' => 125000,
            'quantified_risk_count' => 2,
        ]);

        $data = $this->generator->generateMonthly($tenant, new DateTimeImmutable('2026-06-01'));
        $csv = $this->generator->renderAsCsv($data);

        $this->assertStringContainsString('Risk Quantification', $csv);
        $this->assertStringContainsString('125000', $csv);
        $this->assertStringContainsString('2', $csv);
    }

    #[Test]
    public function generateMonthlyCsvOmitsAleLinesWhenInactive(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $this->moduleConfig->method('isModuleActive')->willReturn(false);

        $data = $this->generator->generateMonthly($tenant, new DateTimeImmutable('2026-06-01'));
        $csv = $this->generator->renderAsCsv($data);

        $this->assertStringNotContainsString('Risk Quantification', $csv);
        $this->assertStringNotContainsString('Annual Loss Expectancy', $csv);
    }
}
