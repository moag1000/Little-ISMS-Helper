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
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
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
        yield 'incidents' => ['checkIncidentProcess', 'wizard.gap.no_incidents'];
        yield 'treatment plans' => ['checkTreatmentPlanStatus', 'wizard.gap.no_treatment_plans'];
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

    /**
     * @param list<int> $counts COUNT(*) results returned by consecutive queries
     */
    private function countingRepository(string $class, array $counts): object
    {
        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturnOnConsecutiveCalls(...$counts);
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $repo = $this->createMock($class);
        $repo->method('createQueryBuilder')->willReturn($qb);

        return $repo;
    }

    private function serviceWith(?DataSubjectRequestRepository $dsr, ?ProcessingActivityRepository $pa): CoverageCheckService
    {
        return new CoverageCheckService(
            $this->createMock(ControlRepository::class),
            $this->createMock(RiskRepository::class),
            $this->createMock(AssetRepository::class),
            $this->createMock(IncidentRepository::class),
            $this->createMock(BusinessProcessRepository::class),
            $this->createMock(BusinessContinuityPlanRepository::class),
            $this->createMock(InternalAuditRepository::class),
            $this->createMock(TrainingRepository::class),
            $this->createMock(RiskTreatmentPlanRepository::class),
            $this->createMock(SupplierRepository::class),
            $this->createMock(ConsentRepository::class),
            $dsr ?? $this->createMock(DataSubjectRequestRepository::class),
            $pa ?? $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(ComplianceFrameworkRepository::class),
            $this->createMock(ComplianceRequirementFulfillmentRepository::class),
            new PolicyWizardCheckRegistry([]),
            null,
        );
    }

    #[Test]
    public function noDataSubjectRequestsScoreZeroWithGap(): void
    {
        $service = $this->serviceWith($this->countingRepository(DataSubjectRequestRepository::class, [0, 0]), null);
        $result = $service->checkDsrCoverage([], null);

        self::assertSame(0.0, $result['score']);
        self::assertSame('no_data', $result['details']['reason']);
        self::assertSame('wizard.gap.no_dsr', $result['gap']['title']);
    }

    #[Test]
    public function noProcessingActivitiesScoreZeroForDpia(): void
    {
        $service = $this->serviceWith(null, $this->countingRepository(ProcessingActivityRepository::class, [0, 0]));
        $result = $service->checkDpiaCoverage([], null);

        self::assertSame(0.0, $result['score']);
        self::assertSame('no_data', $result['details']['reason']);
        self::assertSame('wizard.gap.no_processing_activities', $result['gap']['title']);
    }

    #[Test]
    public function activitiesWithoutHighRiskMakeDpiaNotApplicable(): void
    {
        $service = $this->serviceWith(null, $this->countingRepository(ProcessingActivityRepository::class, [0, 3]));
        $result = $service->checkDpiaCoverage([], null);

        self::assertSame(100, $result['score']);
        self::assertSame('not_applicable', $result['details']['reason']);
        self::assertNull($result['gap']);
    }
}
