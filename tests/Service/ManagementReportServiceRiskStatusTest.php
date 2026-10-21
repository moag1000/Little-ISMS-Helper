<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Risk;
use App\Enum\RiskStatus;
use App\Repository\AssetRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ControlRepository;
use App\Repository\DataBreachRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\TrainingRepository;
use App\Service\DashboardStatisticsService;
use App\Service\ManagementReportService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Regression: ManagementReportService compared Risk::getStatus() — a
 * RiskStatus enum — with strings, so closed risks were never excluded from
 * the monthly trend and the status filter never matched anything.
 */
#[AllowMockObjectsWithoutExpectations]
final class ManagementReportServiceRiskStatusTest extends TestCase
{
    /**
     * @param list<Risk> $risks
     */
    private function service(array $risks): ManagementReportService
    {
        $riskRepository = $this->createMock(RiskRepository::class);
        $riskRepository->method('findAll')->willReturn($risks);

        return new ManagementReportService(
            $this->createMock(AssetRepository::class),
            $riskRepository,
            $this->createMock(ControlRepository::class),
            $this->createMock(IncidentRepository::class),
            $this->createMock(TrainingRepository::class),
            $this->createMock(BusinessContinuityPlanRepository::class),
            $this->createMock(BCExerciseRepository::class),
            $this->createMock(BusinessProcessRepository::class),
            $this->createMock(ComplianceFrameworkRepository::class),
            $this->createMock(DataBreachRepository::class),
            $this->createMock(RiskTreatmentPlanRepository::class),
            $this->createMock(DashboardStatisticsService::class),
            $this->createMock(TranslatorInterface::class),
        );
    }

    private function risk(RiskStatus $status): Risk
    {
        $risk = new Risk();
        $risk->setTitle('R ' . $status->value);
        $risk->setProbability(4);
        $risk->setImpact(4);
        $risk->setStatus($status);
        $risk->setCreatedAt(new \DateTimeImmutable('-1 day'));

        return $risk;
    }

    #[Test]
    public function riskTrendExcludesClosedRisks(): void
    {
        $trend = $this->service([
            $this->risk(RiskStatus::Assessed),
            $this->risk(RiskStatus::Closed),
        ])->getRiskTrendData(1);

        self::assertCount(1, $trend);
        self::assertSame(1, $trend[0]['total']);
        self::assertSame(1, $trend[0]['high_critical']);
    }

    #[Test]
    public function statusFilterAcceptsBackingValueAndEnum(): void
    {
        $open = $this->risk(RiskStatus::Assessed);
        $closed = $this->risk(RiskStatus::Closed);
        $service = $this->service([$open, $closed]);

        self::assertSame(1, $service->getRiskManagementReport(['status' => 'closed'])['total_risks']);
        self::assertSame(1, $service->getRiskManagementReport(['status' => RiskStatus::Assessed])['total_risks']);
        self::assertSame(0, $service->getRiskManagementReport(['status' => 'no_such_status'])['total_risks']);
    }
}
