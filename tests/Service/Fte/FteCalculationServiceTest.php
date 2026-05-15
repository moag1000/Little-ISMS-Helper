<?php

declare(strict_types=1);

namespace App\Tests\Service\Fte;

use App\Entity\Fte\FteCalibrationConstant;
use App\Entity\Tenant;
use App\Repository\Fte\FteCalibrationConstantRepository;
use App\Service\AuditLogger;
use App\Service\Fte\FteCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FteCalculationService savings formulas.
 *
 * All tests use stub calibration repository returning fixed values
 * to isolate formula logic from database access.
 */
class FteCalculationServiceTest extends TestCase
{
    private FteCalibrationConstantRepository $calibrationRepo;
    private EntityManagerInterface $em;
    private AuditLogger $auditLogger;
    private FteCalculationService $service;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->calibrationRepo = $this->createMock(FteCalibrationConstantRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->tenant = $this->createStub(Tenant::class);

        $this->service = new FteCalculationService(
            $this->calibrationRepo,
            $this->em,
            $this->auditLogger,
        );
    }

    #[Test]
    public function calculateSsoJitSavingsReturnsCorrectValue(): void
    {
        $this->calibrationRepo
            ->method('getMinutesFor')
            ->with($this->tenant, FteCalibrationConstant::OP_MANUAL_USER_PROVISIONING)
            ->willReturn(20.0);

        $savings = $this->service->calculateSsoJitSavings(3, $this->tenant);

        // 3 users × 20 min/user - 0 actual = 60
        $this->assertSame(60, $savings);
    }

    #[Test]
    public function calculateSsoJitSavingsNeverNegative(): void
    {
        $this->calibrationRepo
            ->method('getMinutesFor')
            ->willReturn(0.0);

        $savings = $this->service->calculateSsoJitSavings(5, $this->tenant);

        $this->assertGreaterThanOrEqual(0, $savings);
    }

    #[Test]
    public function calculateBulkImportSavingsForRisk(): void
    {
        $this->calibrationRepo
            ->method('getMinutesFor')
            ->with($this->tenant, FteCalibrationConstant::OP_MANUAL_RISK_CREATION)
            ->willReturn(5.0);

        $savings = $this->service->calculateBulkImportSavings(100, 'risk', $this->tenant);

        // 100 rows × 5 min - 1 actual = 499
        $this->assertSame(499, $savings);
    }

    #[Test]
    public function calculateBulkImportSavingsForAsset(): void
    {
        $this->calibrationRepo
            ->method('getMinutesFor')
            ->with($this->tenant, FteCalibrationConstant::OP_MANUAL_ASSET_CREATION)
            ->willReturn(3.0);

        $savings = $this->service->calculateBulkImportSavings(50, 'asset', $this->tenant);

        // 50 × 3 - 1 = 149
        $this->assertSame(149, $savings);
    }

    #[Test]
    public function calculateBulkImportSavingsNeverNegative(): void
    {
        $this->calibrationRepo
            ->method('getMinutesFor')
            ->willReturn(0.0);

        $savings = $this->service->calculateBulkImportSavings(1, 'risk', $this->tenant);

        $this->assertSame(0, $savings);
    }

    #[Test]
    public function calculateEvidenceReuseSavingsRequiresAtLeastTwoFrameworks(): void
    {
        $savings = $this->service->calculateEvidenceReuseSavings(5, 1, $this->tenant);

        $this->assertSame(0, $savings);
    }

    #[Test]
    public function calculateEvidenceReuseSavingsWithMultipleFrameworks(): void
    {
        $this->calibrationRepo
            ->method('getMinutesFor')
            ->with($this->tenant, FteCalibrationConstant::OP_SINGLE_FRAMEWORK_EVIDENCE_MAINTENANCE)
            ->willReturn(8.0);

        $savings = $this->service->calculateEvidenceReuseSavings(1, 3, $this->tenant);

        // Manual: 1 × 3 × 8 = 24
        // Actual: 1 × 8 + 1 × 1 = 9
        // Savings: max(0, 24 - 9) = 15
        $this->assertSame(15, $savings);
    }

    #[Test]
    public function calculateEvidenceReuseSavingsNeverNegative(): void
    {
        $this->calibrationRepo
            ->method('getMinutesFor')
            ->willReturn(0.0);

        $savings = $this->service->calculateEvidenceReuseSavings(1, 5, $this->tenant);

        $this->assertGreaterThanOrEqual(0, $savings);
    }
}
