<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\Incident;
use App\Entity\Supplier;
use App\Entity\SupplierQuestionnaire;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\IncidentSeverity;
use App\Form\BusinessContinuityPlanType;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\TrainingParticipationRepository;
use App\Service\AuditLogger;
use App\Service\ComplianceWizard\TrainingParticipationCheck;
use App\Service\Deadline\Source\ManagementTrainee;
use App\Service\Deadline\Source\ManagementTrainingSource;
use App\Service\Incident\DoraIncidentReportExport;
use App\Service\Incident\ReportingPlanner;
use App\Service\ModuleConfigurationService;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Service\SupplierQuestionnaireService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * P7 NIS-2 fine-tuning: management training per person, supplier review into
 * the supplier's assessment, BC plan targets from the BIA, DORA report export.
 */
final class Nis2FineTuningTest extends TestCase
{
    #[Test]
    public function everyBoardMemberHasTheirOwnTrainingDuty(): void
    {
        $tenant = (new Tenant())->setNis2Classification(Tenant::NIS2_IMPORTANT);
        $trained = $this->user(1);
        $untrained = $this->user(2);
        $last = new DateTimeImmutable('-3 months');

        $source = new ManagementTrainingSource(
            $this->resolver([$trained, $untrained]),
            $this->participations([1 => $last]),
        );
        $items = iterator_to_array($source->findOpen($tenant));

        self::assertCount(2, $items);
        self::assertInstanceOf(ManagementTrainee::class, $items[0]);
        self::assertSame($last->modify('+12 months')->format('Y-m-d'), $source->dueDateOf($items[0])->format('Y-m-d'));
        self::assertSame((new DateTimeImmutable('today'))->format('Y-m-d'), $source->dueDateOf($items[1])->format('Y-m-d'), 'never trained: due at once');
        self::assertSame([$trained], $source->responsibleOf($items[0])->holders);

        $tenant->setNis2Classification(Tenant::NIS2_NOT_REGULATED);
        self::assertSame([], iterator_to_array($source->findOpen($tenant)));
    }

    #[Test]
    public function theArticle20CheckCountsBoardMembers(): void
    {
        $tenant = new Tenant();
        $check = new TrainingParticipationCheck(
            $this->participations([1 => new DateTimeImmutable('-1 month')]),
            $this->resolver([$this->user(1), $this->user(2, 'Bea', 'Board')]),
        );

        $result = $check->check(['roles' => ['ROLE_TOP_MGMT'], 'months' => 12], $tenant);

        self::assertSame(50.0, $result['score']);
        self::assertNotNull($result['gap']);
        self::assertSame('Bea Board', $result['gap']['description_params']['%names%']);
    }

    #[Test]
    public function aReviewedQuestionnaireBecomesTheSupplierAssessment(): void
    {
        $supplier = new Supplier();
        $questionnaire = (new SupplierQuestionnaire())->setSupplier($supplier);
        $lifecycle = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycle->expects(self::once())->method('transition')->with($questionnaire, 'supplier_questionnaire_lifecycle', 'review');

        $service = new SupplierQuestionnaireService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AuditLogger::class),
            $lifecycle,
        );
        $service->review($questionnaire, 140, ' No ISO certificate. ', null);

        self::assertSame(100, $supplier->getSecurityScore(), 'clamped to 0–100');
        self::assertSame((new DateTimeImmutable('today'))->format('Y-m-d'), $supplier->getLastSecurityAssessment()?->format('Y-m-d'));
        self::assertSame((new DateTimeImmutable('today +12 months'))->format('Y-m-d'), $supplier->getNextAssessmentDate()?->format('Y-m-d'));
        self::assertSame('No ISO certificate.', $supplier->getAssessmentFindings());
    }

    #[Test]
    public function aPlanWithoutOwnTargetsTakesThemFromTheBia(): void
    {
        $process = (new BusinessProcess())->setRto(4)->setRpo(1);
        $plan = (new BusinessContinuityPlan())->setBusinessProcess($process);
        BusinessContinuityPlanType::takeTargetsFromBia($plan);
        self::assertSame(4, $plan->getRto());
        self::assertSame(1, $plan->getRpo());

        $own = (new BusinessContinuityPlan())->setBusinessProcess($process)->setRto(8);
        BusinessContinuityPlanType::takeTargetsFromBia($own);
        self::assertSame(8, $own->getRto(), 'the plan’s own value stays');
    }

    #[Test]
    public function theDoraReportGrowsWithThePhase(): void
    {
        $tenant = (new Tenant())->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);
        $incident = new Incident();
        $incident->setTenant($tenant);
        $incident->setSeverity(IncidentSeverity::High);
        $incident->setDetectedAt(new DateTimeImmutable('2026-05-01 08:00'));
        $incident->setIctIncidentClassification(ReportingPlanner::DORA_MAJOR);
        $incident->setReportSummary('Payment service down for 3 h.');

        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActiveForTenant')->willReturn(true);
        $export = new DoraIncidentReportExport(new ReportingPlanner(static fn (): ModuleConfigurationService => $modules));

        $initial = $export->build($incident, 'initial');
        self::assertSame('Payment service down for 3 h.', $initial['incident']['description']);
        self::assertNotNull($initial['meta']['deadline'], 'deadline from the planner');
        self::assertArrayNotHasKey('handling', $initial);
        self::assertArrayHasKey('handling', $export->build($incident, 'intermediate'));
        self::assertArrayHasKey('closure', $export->build($incident, 'final'));
    }

    private function user(int $id, string $first = 'Ann', string $last = 'Admin'): User
    {
        $user = new User();
        $user->setFirstName($first);
        $user->setLastName($last);
        $user->setEmail(sprintf('u%d@example.test', $id));
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    /** @param list<User> $users */
    private function resolver(array $users): ResponsibilityResolver
    {
        $resolver = $this->createStub(ResponsibilityResolver::class);
        $resolver->method('usersHolding')->willReturn($users);

        return $resolver;
    }

    /** @param array<int, DateTimeImmutable> $last */
    private function participations(array $last): TrainingParticipationRepository
    {
        $repository = $this->createStub(TrainingParticipationRepository::class);
        $repository->method('lastCompletionByUser')->willReturn($last);

        return $repository;
    }
}
