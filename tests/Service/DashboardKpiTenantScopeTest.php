<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\Document;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Enum\TrainingStatus;
use App\Repository\AssetRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Service\DashboardStatisticsService;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The KPI snapshot cron (KpiSnapshotCommand) runs without the request-bound
 * Doctrine tenant filter. The BCM, training, supplier and documentation KPI
 * blocks used findAll(), so tenant A's snapshot counted tenant B's data.
 * Two tenants: each repository answers findAll() with A + B and
 * findBy(['tenant' => A]) with A only — A's KPIs must only reflect A.
 */
final class DashboardKpiTenantScopeTest extends TestCase
{
    private Tenant $tenantA;

    protected function setUp(): void
    {
        $this->tenantA = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenantA, 1);
    }

    #[Test]
    public function snapshotBlocksOfTenantAExcludeTenantBData(): void
    {
        $processA = (new BusinessProcess())->setCriticality('critical')->setRto(4);
        $processB = (new BusinessProcess())->setCriticality('critical');
        $planA = new BusinessContinuityPlan();
        $planA->setStatus('active'); // @phpstan-ignore lifecycle.directSetStatus (test fixture)
        $planB = new BusinessContinuityPlan();
        $planB->setStatus('active'); // @phpstan-ignore lifecycle.directSetStatus (test fixture)
        $exerciseB = (new BCExercise())->setExerciseDate(new \DateTime());

        $trainingA = new Training();
        $trainingA->setStatus(TrainingStatus::Completed->value); // @phpstan-ignore lifecycle.directSetStatus (test fixture)
        $trainingB = (new Training())->setScheduledDate(new \DateTime('-1 month'));

        $supplierA = (new Supplier())->setCriticality('critical')->setLastSecurityAssessment(new \DateTime('-1 month'));
        $supplierB = (new Supplier())->setCriticality('critical');

        $service = new DashboardStatisticsService(
            $this->createStub(AssetRepository::class),
            $this->createStub(RiskRepository::class),
            $this->createStub(IncidentRepository::class),
            $this->createStub(ControlRepository::class),
            $this->createStub(Security::class),
            $this->createStub(ModuleConfigurationService::class),
            trainingRepository: $this->repo(TrainingRepository::class, [$trainingA], [$trainingB]),
            businessProcessRepository: $this->repo(BusinessProcessRepository::class, [$processA], [$processB]),
            bcPlanRepository: $this->repo(BusinessContinuityPlanRepository::class, [$planA], [$planB]),
            bcExerciseRepository: $this->repo(BCExerciseRepository::class, [], [$exerciseB]),
            supplierRepository: $this->repo(SupplierRepository::class, [$supplierA], [$supplierB]),
            documentRepository: $this->repo(DocumentRepository::class, [$this->document()], [$this->document(), $this->document()]),
        );

        $bcm = $this->invoke($service, 'getBusinessContinuityKPIs');
        self::assertSame(1, $bcm['critical_processes']['value']);
        self::assertSame(100, $bcm['bia_coverage']['value']);
        self::assertSame(1, $bcm['active_bc_plans']['value']);
        self::assertSame(0, $bcm['bc_exercises_ytd']['value']);

        $training = $this->invoke($service, 'getTrainingKPIs');
        self::assertSame(100, $training['training_completion_rate']['value']);
        self::assertSame(0, $training['overdue_trainings']['value']);

        self::assertSame(100, $this->invoke($service, 'getSupplierKPIs')['supplier_assessment_rate']['value']);
        self::assertSame(1, $this->invoke($service, 'getDocumentationKPIs')['total_documents']['value']);
    }

    private function document(): Document
    {
        $document = new Document();
        $document->setStatus(\App\Enum\DocumentStatus::Approved->value); // @phpstan-ignore lifecycle.directSetStatus (test fixture)

        return $document;
    }

    /**
     * @template T of EntityRepository
     * @param class-string<T> $class
     * @param list<object>    $ofA
     * @param list<object>    $ofB
     * @return T
     */
    private function repo(string $class, array $ofA, array $ofB): EntityRepository
    {
        $repo = $this->createStub($class);
        $repo->method('findAll')->willReturn([...$ofA, ...$ofB]);
        $repo->method('findBy')->willReturnCallback(
            fn (array $criteria): array => ($criteria['tenant'] ?? null) === $this->tenantA ? $ofA : [...$ofA, ...$ofB],
        );

        return $repo;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function invoke(DashboardStatisticsService $service, string $method): array
    {
        return (new \ReflectionMethod($service, $method))->invoke($service, $this->tenantA);
    }
}
