<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Risk;
use App\Enum\RiskStatus;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Service\RiskAggregationService;
use App\Service\RiskMatrixService;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Regression: calculateRiskTrend() compared the RiskStatus enum against a
 * list of strings with in_array(..., true), which never matched — so the
 * portfolio trend was always 'stable' no matter how the risks stood.
 */
#[AllowMockObjectsWithoutExpectations]
final class RiskAggregationServiceTest extends TestCase
{
    /**
     * @param list<Risk> $risks
     */
    private function portfolioTrend(array $risks): string
    {
        $riskRepository = $this->createMock(RiskRepository::class);
        $riskRepository->method('findAll')->willReturn($risks);
        $tenantContext = $this->createMock(TenantContext::class);
        $tenantContext->method('getCurrentTenant')->willReturn(null);

        $service = new RiskAggregationService(
            $riskRepository,
            $this->createMock(AssetRepository::class),
            $tenantContext,
            new RiskMatrixService($riskRepository, $this->createMock(TranslatorInterface::class)),
        );

        return $service->getRiskPortfolio()['risk_trend'];
    }

    private function risk(RiskStatus $status, int $probability, int $impact): Risk
    {
        $risk = new Risk();
        $risk->setTitle('R ' . $status->value);
        $risk->setProbability($probability);
        $risk->setImpact($impact);
        $risk->setStatus($status);

        return $risk;
    }

    #[Test]
    public function mostlyTreatedLowRisksTrendDecreasing(): void
    {
        $trend = $this->portfolioTrend([
            $this->risk(RiskStatus::Treated, 1, 1),
            $this->risk(RiskStatus::Closed, 1, 2),
            $this->risk(RiskStatus::Accepted, 2, 1),
            $this->risk(RiskStatus::Identified, 1, 1),
        ]);

        self::assertSame('decreasing', $trend);
    }

    #[Test]
    public function mostlyUntreatedHighRisksTrendIncreasing(): void
    {
        $trend = $this->portfolioTrend([
            $this->risk(RiskStatus::Identified, 5, 5),
            $this->risk(RiskStatus::Assessed, 5, 4),
            $this->risk(RiskStatus::Identified, 4, 5),
            $this->risk(RiskStatus::Monitored, 1, 1),
        ]);

        self::assertSame('increasing', $trend);
    }

    #[Test]
    public function balancedPortfolioStaysStable(): void
    {
        $trend = $this->portfolioTrend([
            $this->risk(RiskStatus::Identified, 1, 1),
            $this->risk(RiskStatus::Treated, 1, 1),
        ]);

        self::assertSame('stable', $trend);
    }

    #[Test]
    public function inTreatmentRisksCountAsStillOpen(): void
    {
        $trend = $this->portfolioTrend([
            $this->risk(RiskStatus::InTreatment, 5, 5),
            $this->risk(RiskStatus::InTreatment, 5, 4),
            $this->risk(RiskStatus::InTreatment, 4, 5),
            $this->risk(RiskStatus::Treated, 1, 1),
            $this->risk(RiskStatus::Closed, 1, 1),
        ]);

        self::assertSame('increasing', $trend);
    }
}
