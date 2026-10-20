<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProcessTimedWorkflowsCommand;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Lifecycle\FieldCompletionAutoTransitionInterface;
use App\Repository\Notification\NotificationRuleRepository;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use App\Repository\RiskAppetiteRepository;
use App\Repository\TenantRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\AuditLogger;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\SlaDeadlineWatcher;
use App\Service\WorkflowAutoProgressionService;
use App\Service\WorkflowService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Automatic progression never satisfies four-eyes (#1059 follow-up).
 *
 * Runs the real chain — ProcessTimedWorkflowsCommand / the deprecated
 * WorkflowAutoProgressionService → WorkflowService → LifecycleService →
 * FourEyesValidator — against the test database. The closing `approve`
 * of workflow_instance_lifecycle is four-eyes; an automatic approval that
 * would fire it must leave the instance untouched.
 */
final class ProcessTimedWorkflowsFourEyesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('fea_', true);
        $this->tenant = (new Tenant())->setName('Auto Four Eyes ' . $uid)->setCode('fea_' . substr($uid, -10));
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            $conn = $this->em->getConnection();
            $tenantId = $this->tenant?->getId();
            if ($tenantId !== null) {
                $conn->executeStatement('DELETE FROM workflow_instances WHERE tenant_id = ?', [$tenantId]);
                $conn->executeStatement(
                    'DELETE FROM workflow_steps WHERE workflow_id IN (SELECT id FROM workflows WHERE tenant_id = ?)',
                    [$tenantId],
                );
                $conn->executeStatement('DELETE FROM workflows WHERE tenant_id = ?', [$tenantId]);
                $conn->executeStatement('DELETE FROM users WHERE tenant_id = ?', [$tenantId]);
                $conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
            }
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    /**
     * #1058 report: a time-based step followed only by a closing notification
     * step was rolled back but reported as an error on every cron run.
     */
    #[Test]
    public function timeBasedStepBeforeClosingNotificationStaysPendingAndCronExitsZero(): void
    {
        $initiator = $this->user('initiator', ['ROLE_MANAGER']);
        $this->user('second', ['ROLE_MANAGER']);
        $instance = $this->instance($initiator, [['approval', true], ['notification', false]]);
        $firstStepId = $instance->getCurrentStep()?->getId();

        $tester = $this->runCron($instance);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('needs a human approver', $tester->getDisplay());
        self::assertStringNotContainsString('Errors encountered', $tester->getDisplay());

        $reloaded = $this->reload($instance);
        self::assertSame('in_progress', $reloaded->getStatus());
        self::assertSame([], $reloaded->getApprovalHistory() ?? []);
        self::assertSame([], $reloaded->getCompletedSteps() ?? []);
        self::assertSame($firstStepId, $reloaded->getCurrentStep()?->getId());
        self::assertNull($reloaded->getCompletedAt());
    }

    /**
     * One-person organisation: the single-approver escape would accept a
     * reason — but the cron's fixed reason is no offline counter-signature.
     */
    #[Test]
    public function timeBasedLastStepInSingleUserTenantIsNotSelfApproved(): void
    {
        $initiator = $this->user('solo', ['ROLE_MANAGER']);
        $instance = $this->instance($initiator, [['approval', true]]);

        $tester = $this->runCron($instance);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $reloaded = $this->reload($instance);
        self::assertSame('in_progress', $reloaded->getStatus());
        self::assertSame([], $reloaded->getApprovalHistory() ?? []);
    }

    #[Test]
    public function intermediateTimeBasedStepStillAutoProgresses(): void
    {
        $initiator = $this->user('initiator', ['ROLE_MANAGER']);
        $instance = $this->instance($initiator, [['approval', true], ['approval', false]]);
        $secondStepId = $instance->getWorkflow()?->getSteps()->toArray()[1]->getId();

        $tester = $this->runCron($instance);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $reloaded = $this->reload($instance);
        self::assertSame('in_progress', $reloaded->getStatus());
        self::assertSame('auto_approved', ($reloaded->getApprovalHistory() ?? [])[0]['action'] ?? null);
        self::assertSame($secondStepId, $reloaded->getCurrentStep()?->getId());
    }

    /**
     * Field-completion auto-progression (deprecated service, bridged from the
     * listener): even a user who could approve by hand does not complete a
     * four-eyes closing step automatically, and nothing is thrown.
     */
    #[Test]
    public function fieldCompletionDoesNotApproveFourEyesClosingStep(): void
    {
        $initiator = $this->user('initiator', ['ROLE_MANAGER']);
        $editor = $this->user('editor', ['ROLE_MANAGER']);

        $control = new Control();
        (new \ReflectionProperty(Control::class, 'id'))->setValue($control, random_int(10000000, 99999999));
        $control->setName('Filled in');

        $instance = $this->instance($initiator, [['approval', false]], 'Control', (int) $control->getId());
        $step = $instance->getCurrentStep();
        self::assertInstanceOf(WorkflowStep::class, $step);
        $step->setMetadata(['autoProgressConditions' => [
            'type' => 'field_completion',
            'entity' => 'Control',
            'fields' => ['name'],
        ]]);
        $this->em->flush();

        // Unused in production wiring (deprecated) — built from real collaborators.
        $container = static::getContainer();
        $service = new WorkflowAutoProgressionService(
            $this->em,
            PropertyAccess::createPropertyAccessor(),
            $container->get(WorkflowService::class),
            new NullLogger(),
            $container->get(RiskAppetiteRepository::class),
            $this->createStub(FieldCompletionAutoTransitionInterface::class),
        );
        $progressed = $service->checkAndProgressWorkflow($control, $editor);

        self::assertFalse($progressed);
        $reloaded = $this->reload($instance);
        self::assertSame('in_progress', $reloaded->getStatus());
        self::assertSame([], $reloaded->getApprovalHistory() ?? []);
        self::assertSame($step->getId(), $reloaded->getCurrentStep()?->getId());
    }

    private function runCron(WorkflowInstance ...$instances): CommandTester
    {
        $repository = $this->createStub(WorkflowInstanceRepository::class);
        $repository->method('findBy')->willReturn($instances);

        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('findActive')->willReturn([]);
        $watcher = new SlaDeadlineWatcher(
            $tenants,
            $this->createStub(SlaDeadlineMonitorRepository::class),
            $this->createStub(NotificationRuleRepository::class),
            $this->createStub(NotificationDispatcher::class),
            $this->em,
            $this->createStub(AuditLogger::class),
            new NullLogger(),
        );

        $command = new ProcessTimedWorkflowsCommand(
            $repository,
            static::getContainer()->get(WorkflowService::class),
            $this->em,
            $watcher,
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    /** @param list<string> $roles */
    private function user(string $name, array $roles): User
    {
        $user = new User();
        $user->setEmail($name . '_' . uniqid('', true) . '@example.com');
        $user->setFirstName(ucfirst($name));
        $user->setLastName('Test');
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        $user->setTenant($this->tenant);
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @param list<array{0: string, 1: bool}> $steps [stepType, timeBased] */
    private function instance(User $initiator, array $steps, string $entityType = 'Risk', ?int $entityId = null): WorkflowInstance
    {
        $workflow = new Workflow();
        $workflow->setName('Auto four eyes ' . uniqid('', true));
        $workflow->setEntityType($entityType);
        $workflow->setTenant($this->tenant);
        $this->em->persist($workflow);

        $first = null;
        foreach ($steps as $i => [$type, $timeBased]) {
            $step = new WorkflowStep();
            $step->setName('Step ' . ($i + 1));
            $step->setStepOrder($i + 1);
            $step->setStepType($type);
            $step->setApproverRole('ROLE_MANAGER');
            $step->setTenant($this->tenant);
            if ($timeBased) {
                $step->setMetadata(['autoProgressConditions' => ['type' => 'time_based', 'delay' => '1 hours']]);
            }
            $workflow->addStep($step);
            $this->em->persist($step);
            $first ??= $step;
        }

        $instance = new WorkflowInstance(); // @lifecycle-initial-state
        $instance->setWorkflow($workflow);
        $instance->setEntityType($entityType);
        $instance->setEntityId($entityId ?? random_int(100000, 999999));
        $instance->setTenant($this->tenant);
        $instance->setInitiatedBy($initiator);
        $instance->setCurrentStep($first);
        $instance->setCurrentStepIndex(0);
        $instance->setStartedAt(new DateTimeImmutable('-2 hours'));
        $instance->setApprovalHistory([]);
        $instance->setStatus('in_progress'); // fixture: instance already started
        $this->em->persist($instance);
        $this->em->flush();

        return $instance;
    }

    private function reload(WorkflowInstance $instance): WorkflowInstance
    {
        $this->em->clear();
        $fresh = $this->em->find(WorkflowInstance::class, $instance->getId());
        self::assertInstanceOf(WorkflowInstance::class, $fresh);

        return $fresh;
    }
}
