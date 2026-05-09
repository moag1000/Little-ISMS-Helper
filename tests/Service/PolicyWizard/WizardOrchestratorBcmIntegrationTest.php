<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\WizardRunRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\BcExerciseAutoSeeder;
use App\Service\PolicyWizard\DocumentGeneratorInterface;
use App\Service\PolicyWizard\HierarchyOverrideValidator;
use App\Service\PolicyWizard\Step\LifecycleStep;
use App\Service\PolicyWizard\Step\OperationalBaselinesStep;
use App\Service\PolicyWizard\Step\OrganisationScopeStep;
use App\Service\PolicyWizard\Step\ReviewGenerateStep;
use App\Service\PolicyWizard\Step\RiskClassificationStep;
use App\Service\PolicyWizard\Step\RolesStep;
use App\Service\PolicyWizard\Step\WelcomeStandardsStep;
use App\Service\PolicyWizard\StepEvaluator;
use App\Service\PolicyWizard\WizardOrchestrator;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W5-B — orchestrator → BcExerciseAutoSeeder integration.
 *
 * Verifies that {@see WizardOrchestrator::complete()} fires the
 * BCExercise auto-seeder ONLY when:
 *  - `bcm` is in `WizardRun.standardsAdopted`
 *  - the run is NOT a sandbox (per architecture §6.4)
 *  - for targeted re-runs, `exercise_testing_programme` is in
 *    `WizardRun.targetedTopics`
 */
#[AllowMockObjectsWithoutExpectations]
final class WizardOrchestratorBcmIntegrationTest extends TestCase
{
    private function makeStepEvaluator(): StepEvaluator
    {
        return new StepEvaluator([
            new WelcomeStandardsStep(),
            new OrganisationScopeStep(),
            new RolesStep(),
            new RiskClassificationStep(),
            new OperationalBaselinesStep(),
            new LifecycleStep(),
            new ReviewGenerateStep(),
        ]);
    }

    private function makeNullValidator(): HierarchyOverrideValidator
    {
        $stub = $this->createStub(HierarchyOverrideValidator::class);
        $stub->method('validate')->willReturn([]);
        return $stub;
    }

    private function makeTenant(int $id = 1): Tenant
    {
        $t = $this->createStub(Tenant::class);
        $t->method('getId')->willReturn($id);
        $t->method('getAllAncestors')->willReturn([]);
        return $t;
    }

    private function makeUser(int $id = 99): User
    {
        $u = $this->createStub(User::class);
        $u->method('getId')->willReturn($id);
        return $u;
    }

    private function makeNoopGenerator(): DocumentGeneratorInterface
    {
        $stub = $this->createStub(DocumentGeneratorInterface::class);
        $stub->method('generate')->willReturn(['document_ids' => []]);
        return $stub;
    }

    /**
     * @param array<int, string> $standards
     * @param array<int, string>|null $targetedTopics
     */
    private function makeRun(
        Tenant $tenant,
        User $user,
        array $standards,
        ?array $targetedTopics = null,
        string $mode = WizardStepKeys::MODE_FULL,
    ): WizardRun {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStartedByUser($user);
        $run->setMode($mode);
        $run->setStandardsAdopted($standards);
        if ($targetedTopics !== null) {
            $run->setTargetedTopics($targetedTopics);
        }
        $run->setStep(WizardStepKeys::STEP_REVIEW_GENERATE);
        $run->setStatus(WizardStepKeys::STATUS_IN_PROGRESS);
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, 555);
        return $run;
    }

    /**
     * Real BcExerciseAutoSeeder wrapped around a tracking EntityManager
     * so we can assert "the seeder ran" by counting BCExercise persists.
     * BcExerciseAutoSeeder is `final`, so PHPUnit's mock generator
     * cannot double it — we use the real implementation with
     * collaborator stubs instead.
     *
     * @return array{0: BcExerciseAutoSeeder, 1: \ArrayObject<int, object>}
     */
    private function makeRealSeeder(): array
    {
        $persisted = new \ArrayObject();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use ($persisted): void {
            $persisted->append($entity);
        });
        $em->method('flush');

        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logCustom');

        return [new BcExerciseAutoSeeder($em, $audit), $persisted];
    }

    #[Test]
    public function orchestratorTriggersSeederWhenBcmInScope(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush');

        [$seeder, $persisted] = $this->makeRealSeeder();

        $orchestrator = new WizardOrchestrator(
            $em,
            $this->createStub(WizardRunRepository::class),
            $this->makeStepEvaluator(),
            $this->makeNoopGenerator(),
            $this->makeNullValidator(),
            null, // approvalKickoff
            null, // documentRepo
            new \Psr\Log\NullLogger(),
            $seeder,
        );

        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $run = $this->makeRun($tenant, $user, ['iso27001', 'bcm']);

        $orchestrator->complete($run);

        // Real seeder ran: 4 BCExercise rows persisted.
        self::assertCount(
            4,
            iterator_to_array($persisted->getIterator()),
            'orchestrator invoked the seeder which persisted 4 BCExercise rows',
        );
    }

    #[Test]
    public function orchestratorSkipsSeederWhenOnlyIsoIsInScope(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush');

        [$seeder, $persisted] = $this->makeRealSeeder();

        $orchestrator = new WizardOrchestrator(
            $em,
            $this->createStub(WizardRunRepository::class),
            $this->makeStepEvaluator(),
            $this->makeNoopGenerator(),
            $this->makeNullValidator(),
            null,
            null,
            new \Psr\Log\NullLogger(),
            $seeder,
        );

        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $run = $this->makeRun($tenant, $user, ['iso27001']);

        $orchestrator->complete($run);

        self::assertCount(
            0,
            iterator_to_array($persisted->getIterator()),
            'no BCExercise placeholders persisted when BCM not in scope',
        );
    }
}
