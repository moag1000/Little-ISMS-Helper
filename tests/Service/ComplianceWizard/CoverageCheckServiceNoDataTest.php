<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ConsentRepository;
use App\Repository\ControlRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckRegistry;
use App\Service\ComplianceWizard\CoverageCheckService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "No data recorded" is not evidence: checks over empty subjects score 0
 * with reason no_data and an open gap, never 100.
 */
#[AllowMockObjectsWithoutExpectations]
final class CoverageCheckServiceNoDataTest extends TestCase
{
    private function buildService(): CoverageCheckService
    {
        $risk = $this->createMock(RiskRepository::class);
        $risk->method('findAll')->willReturn([]);
        $audit = $this->createMock(InternalAuditRepository::class);
        $audit->method('findAll')->willReturn([]);
        $training = $this->createMock(TrainingRepository::class);
        $training->method('findAll')->willReturn([]);
        $supplier = $this->createMock(SupplierRepository::class);
        $supplier->method('findAll')->willReturn([]);

        return new CoverageCheckService(
            $this->createMock(ControlRepository::class),
            $risk,
            $this->createMock(AssetRepository::class),
            $this->createMock(IncidentRepository::class),
            $this->createMock(BusinessProcessRepository::class),
            $this->createMock(BusinessContinuityPlanRepository::class),
            $audit,
            $training,
            $this->createMock(RiskTreatmentPlanRepository::class),
            $supplier,
            $this->createMock(ConsentRepository::class),
            $this->createMock(DataSubjectRequestRepository::class),
            $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(ComplianceFrameworkRepository::class),
            $this->createMock(ComplianceRequirementFulfillmentRepository::class),
            new PolicyWizardCheckRegistry([]),
            null,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emptySubjects(): iterable
    {
        yield 'risks' => ['checkRiskCoverage', 'wizard.gap.no_risks'];
        yield 'trainings' => ['checkTrainingCoverage', 'wizard.gap.no_trainings'];
        yield 'audits' => ['checkAuditStatus', 'wizard.gap.no_audits'];
        yield 'suppliers' => ['checkSupplierAssessment', 'wizard.gap.no_suppliers'];
    }

    #[Test]
    #[DataProvider('emptySubjects')]
    public function emptySubjectScoresZeroWithGap(string $method, string $gapTitle): void
    {
        $result = $this->buildService()->$method([], null);

        self::assertSame(0.0, $result['score']);
        self::assertSame('no_data', $result['details']['reason']);
        self::assertNotNull($result['gap']);
        self::assertSame($gapTitle, $result['gap']['title']);
    }
}
