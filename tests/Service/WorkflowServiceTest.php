<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Workflow\Loader\RegulatoryWorkflowLoader;
use App\Repository\WorkflowInstanceRepository;
use App\Repository\WorkflowRepository;
use App\Repository\UserRepository;
use App\Service\WorkflowService;
use App\Service\EmailNotificationService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\SecurityBundle\Security;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class WorkflowServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $workflowRepository;
    private MockObject $workflowInstanceRepository;
    private MockObject $userRepository;
    private MockObject $emailService;
    private MockObject $security;
    /** @var MockObject&LifecycleTransitionInterface */
    private MockObject $lifecycleService;
    /** @var MockObject&RegulatoryWorkflowLoader */
    private MockObject $regulatoryWorkflowLoader;
    private WorkflowService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->workflowRepository = $this->createMock(WorkflowRepository::class);
        $this->workflowInstanceRepository = $this->createMock(WorkflowInstanceRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->emailService = $this->createMock(EmailNotificationService::class);
        $this->security = $this->createMock(Security::class);
        $this->lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $this->regulatoryWorkflowLoader = $this->createMock(RegulatoryWorkflowLoader::class);

        $this->service = new WorkflowService(
            $this->entityManager,
            $this->workflowRepository,
            $this->workflowInstanceRepository,
            $this->userRepository,
            $this->emailService,
            $this->security,
            $this->lifecycleService,
            $this->regulatoryWorkflowLoader,
        );
    }

    #[Test]
    public function testStartWorkflowCreatesNewInstance(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        $step = $this->createWorkflowStep(1, 'Review', 5);
        $workflow = $this->createWorkflow('Risk Review', 'Risk', [$step]);

        $this->workflowRepository->method('findBy')
            ->willReturn([$workflow]);

        $this->workflowInstanceRepository->method('findOneBy')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(WorkflowInstance::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $instance = $this->service->startWorkflow('Risk', 123);

        $this->assertInstanceOf(WorkflowInstance::class, $instance);
        $this->assertSame('Risk', $instance->getEntityType());
        $this->assertSame(123, $instance->getEntityId());
        // status is set to 'in_progress' by lifecycleService->transition('start') which is mocked;
        // assert the transition was called (see testStartWorkflowWithStepsCallsStartTransition in WorkflowServiceLifecycleTest)
        $this->assertSame($step, $instance->getCurrentStep());
        $this->assertSame(0, $instance->getCurrentStepIndex());
        $this->assertNotNull($instance->getDueDate());
    }

    #[Test]
    public function testStartWorkflowTakesTheTenantOfTheRecord(): void
    {
        // Without a tenant the TenantFilter hides the instance from its own
        // organisation (approval inbox, My Day).
        $tenant = new Tenant();
        $risk = $this->createStub(Risk::class);
        $risk->method('getTenant')->willReturn($tenant);
        $this->entityManager->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ?Risk => $class === Risk::class && $id === 123 ? $risk : null,
        );
        $this->workflowRepository->method('findBy')
            ->willReturn([$this->createWorkflow('Risk Review', 'Risk', [$this->createWorkflowStep(1, 'Review', 5)])]);

        $instance = $this->service->startWorkflow('Risk', 123);

        $this->assertSame($tenant, $instance?->getTenant());
    }

    #[Test]
    public function testStartWorkflowNeverUsesAnotherOrganisationsDefinition(): void
    {
        // Outside a web request the tenant filter is not armed: a custom
        // definition of another organisation must not be picked up.
        $own = $this->createStub(Tenant::class);
        $own->method('getId')->willReturn(1);
        $other = $this->createStub(Tenant::class);
        $other->method('getId')->willReturn(2);
        $risk = $this->createStub(Risk::class);
        $risk->method('getTenant')->willReturn($own);
        $this->entityManager->method('find')->willReturn($risk);

        $foreign = $this->createWorkflow('Foreign', 'Risk', [$this->createWorkflowStep(1, 'Review', 5)]);
        $foreign->method('getTenant')->willReturn($other);
        $shared = $this->createWorkflow('Shared', 'Risk', [$this->createWorkflowStep(2, 'Review', 5)]);
        $this->workflowRepository->method('findBy')->willReturn([$foreign, $shared]);

        $this->assertSame($shared, $this->service->startWorkflow('Risk', 123)?->getWorkflow());
    }

    #[Test]
    public function testStartWorkflowFallsBackToTheInitiatorsTenant(): void
    {
        $tenant = new Tenant();
        $user = $this->createStub(User::class);
        $user->method('getTenant')->willReturn($tenant);
        $this->security->method('getUser')->willReturn($user);
        $this->entityManager->method('find')->willReturn(null);
        $this->workflowRepository->method('findBy')
            ->willReturn([$this->createWorkflow('Risk Review', 'Risk', [$this->createWorkflowStep(1, 'Review', 5)])]);

        $instance = $this->service->startWorkflow('Risk', 123);

        $this->assertSame($tenant, $instance?->getTenant());
    }

    #[Test]
    public function testStartWorkflowReturnsExistingInstance(): void
    {
        $existingInstance = $this->createMock(WorkflowInstance::class);

        $this->workflowRepository->method('findBy')
            ->willReturn([$this->createWorkflow('Test', 'Risk')]);

        // Mock the QueryBuilder chain that WorkflowService uses
        $query = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOneOrNullResult'])
            ->getMock();
        $query->method('getOneOrNullResult')->willReturn($existingInstance);

        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $qb->method('expr')->willReturn(new \Doctrine\ORM\Query\Expr());

        $this->entityManager->method('createQueryBuilder')->willReturn($qb);

        $instance = $this->service->startWorkflow('Risk', 123);

        $this->assertSame($existingInstance, $instance);
    }

    #[Test]
    public function testStartWorkflowReturnsNullIfNoWorkflowFound(): void
    {
        $this->workflowRepository->method('findBy')
            ->willReturn([]);

        $instance = $this->service->startWorkflow('Risk', 123);

        $this->assertNull($instance);
    }

    #[Test]
    public function testStartWorkflowWithSpecificWorkflowName(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        $step = $this->createWorkflowStep(1, 'Step', 3);
        $workflow = $this->createWorkflow('Critical Risk Review', 'Risk', [$step]);

        $this->workflowRepository->expects($this->once())
            ->method('findBy')
            ->with([
                'entityType' => 'Risk',
                'isActive' => true,
                'name' => 'Critical Risk Review',
            ])
            ->willReturn([$workflow]);

        $this->workflowInstanceRepository->method('findOneBy')
            ->willReturn(null);

        $instance = $this->service->startWorkflow('Risk', 123, 'Critical Risk Review');

        $this->assertNotNull($instance);
    }

    #[Test]
    public function testApproveStepSucceeds(): void
    {
        $user = $this->createUser(1, 'John', 'Doe');
        $user->method('getRoles')->willReturn(['ROLE_MANAGER']);

        $step = $this->createWorkflowStep(1, 'Manager Approval', 5);
        $step->method('getApproverRole')->willReturn('ROLE_MANAGER');
        $step->method('getApproverUsers')->willReturn(null);

        $nextStep = $this->createWorkflowStep(2, 'Final Review', 3);

        $workflow = $this->createWorkflow('Test', 'Risk', [$step, $nextStep]);

        $instance = $this->createWorkflowInstance('in_progress', $step, $workflow);

        $instance->expects($this->once())
            ->method('addApprovalHistoryEntry');

        $instance->expects($this->once())
            ->method('addCompletedStep')
            ->with(1);

        $instance->expects($this->once())
            ->method('setCurrentStep')
            ->with($nextStep);

        // Mock security to allow approval (check both ROLE_ADMIN and ROLE_MANAGER)
        $this->security->method('isGrantedForUser')
            ->willReturnCallback(function ($u, $role) {
                return $role === 'ROLE_ADMIN' || $role === 'ROLE_MANAGER';
            });

        $result = $this->service->approveStep($instance, $user, 'Looks good');

        $this->assertTrue($result);
    }

    #[Test]
    public function testApproveStepFailsIfNotInProgress(): void
    {
        $user = $this->createUser(1, 'John', 'Doe');
        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getStatus')->willReturn('approved');

        $result = $this->service->approveStep($instance, $user);

        $this->assertFalse($result);
    }

    #[Test]
    public function testApproveStepFailsIfNoCurrentStep(): void
    {
        $user = $this->createUser(1, 'John', 'Doe');
        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getStatus')->willReturn('in_progress');
        $instance->method('getCurrentStep')->willReturn(null);

        $result = $this->service->approveStep($instance, $user);

        $this->assertFalse($result);
    }

    #[Test]
    public function testApproveStepFailsIfUserCannotApprove(): void
    {
        $user = $this->createUser(1, 'John', 'Doe');
        $user->method('getRoles')->willReturn(['ROLE_USER']);

        $step = $this->createWorkflowStep(1, 'Admin Approval', 5);
        $step->method('getApproverRole')->willReturn('ROLE_ADMIN');
        $step->method('getApproverUsers')->willReturn(null);

        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getStatus')->willReturn('in_progress');
        $instance->method('getCurrentStep')->willReturn($step);

        $result = $this->service->approveStep($instance, $user);

        $this->assertFalse($result);
    }

    #[Test]
    public function testRejectStepSucceeds(): void
    {
        $user = $this->createUser(1, 'Jane', 'Smith');
        $user->method('getRoles')->willReturn(['ROLE_MANAGER']);

        $step = $this->createWorkflowStep(1, 'Review', 5);
        $step->method('getApproverRole')->willReturn('ROLE_MANAGER');
        $step->method('getApproverUsers')->willReturn(null);

        $instance = $this->createWorkflowInstance('in_progress', $step);

        $instance->expects($this->once())
            ->method('addApprovalHistoryEntry');

        $instance->expects($this->once())
            ->method('setCompletedAt');

        $instance->expects($this->once())
            ->method('setComments')
            ->with('Does not meet requirements');

        $this->lifecycleService->expects($this->once())
            ->method('transition')
            ->with($instance, 'workflow_instance_lifecycle', 'reject', $user, 'Does not meet requirements');

        // Mock security to allow rejection (check both ROLE_ADMIN and ROLE_MANAGER)
        $this->security->method('isGrantedForUser')
            ->willReturnCallback(function ($u, $role) {
                return $role === 'ROLE_ADMIN' || $role === 'ROLE_MANAGER';
            });

        $result = $this->service->rejectStep($instance, $user, 'Does not meet requirements');

        $this->assertTrue($result);
    }

    /** S17: `reject_action: loop_back` sends the chain back instead of ending it (CAPA 10.1 f). */
    #[Test]
    public function aRejectionOnALoopBackStepReturnsToTheTargetStep(): void
    {
        $workflow = new Workflow();
        $steps = [];
        foreach ([1 => null, 2 => null, 3 => ['rejectAction' => 'loop_back', 'rejectTargetStep' => 1]] as $order => $metadata) {
            $step = (new WorkflowStep())->setName('Step ' . $order)->setStepOrder($order)
                ->setApproverRole('ROLE_MANAGER')->setDaysToComplete(5)->setMetadata($metadata);
            $workflow->addStep($step);
            $steps[$order] = $step;
        }
        $instance = new WorkflowInstance();
        $instance->setWorkflow($workflow);
        $instance->setStatus('in_progress'); // @lifecycle-initial-state (unit fixture)
        $instance->setCurrentStep($steps[3]);
        $instance->setCurrentStepIndex(2);
        $user = $this->createUser(1, 'Jane', 'Smith');
        $this->security->method('isGrantedForUser')->willReturnCallback(static fn ($u, $role): bool => $role === 'ROLE_MANAGER');

        $this->lifecycleService->expects($this->never())->method('transition');

        self::assertTrue($this->service->rejectStep($instance, $user, 'Not effective'));
        self::assertSame($steps[1], $instance->getCurrentStep());
        self::assertSame(0, $instance->getCurrentStepIndex());
        self::assertSame('in_progress', $instance->getStatus());
        self::assertNull($instance->getCompletedAt());
        self::assertSame('rejected', $instance->getApprovalHistory()[0]['action'] ?? null);
    }

    #[Test]
    public function testRejectStepFailsIfNotInProgress(): void
    {
        $user = $this->createUser(1, 'John', 'Doe');
        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getStatus')->willReturn('cancelled');

        $result = $this->service->rejectStep($instance, $user, 'Reason');

        $this->assertFalse($result);
    }

    #[Test]
    public function testCancelWorkflowDelegatesToLifecycleService(): void
    {
        // cancelWorkflow delegates status to LifecycleService (Sprint Y.0).
        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getStatus')->willReturn('pending');

        $instance->expects($this->once())
            ->method('setCompletedAt')
            ->with($this->isInstanceOf(\DateTimeImmutable::class));

        $instance->expects($this->once())
            ->method('setComments')
            ->with('Project cancelled');

        $this->lifecycleService->expects($this->once())
            ->method('transition')
            ->with($instance, 'workflow_instance_lifecycle', 'cancel', null, 'Project cancelled');

        $this->service->cancelWorkflow($instance, 'Project cancelled');
    }

    #[Test]
    public function testApprovalWithSpecificUser(): void
    {
        $approver = $this->createUser(5, 'Approved', 'User');
        $approver->method('getRoles')->willReturn(['ROLE_USER']);

        $step = $this->createWorkflowStep(1, 'Specific User Approval', 5);
        $step->method('getApproverRole')->willReturn(null);
        $step->method('getApproverUsers')->willReturn([5]); // User ID 5 is allowed

        $workflow = $this->createWorkflow('Test', 'Risk', [$step]);

        $instance = $this->createWorkflowInstance('in_progress', $step, $workflow);

        // User should be able to approve because they are in the approver list
        $this->assertTrue($this->service->approveStep($instance, $approver));
    }

    #[Test]
    public function testWorkflowInstancePendingStatus(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        // Workflow with no steps
        $workflow = $this->createWorkflow('Empty Workflow', 'Risk', []);

        $this->workflowRepository->method('findBy')
            ->willReturn([$workflow]);

        $this->workflowInstanceRepository->method('findOneBy')
            ->willReturn(null);

        $instance = $this->service->startWorkflow('Risk', 123);

        // Should remain pending since there are no steps
        $this->assertSame('pending', $instance->getStatus());
    }

    /**
     * The closing four-eyes `approve` carries the authorising step: its
     * single-approver escape must count users eligible for THAT step
     * (approver_users, approver_role via hierarchy, ROLE_ADMIN), not the
     * holders of the transition's roles.
     */
    #[Test]
    public function finalApprovalPassesTheStepEligibilityRuleToTheLifecycle(): void
    {
        $approver = $this->createUser(1, 'Dana', 'Dpo');
        $approver->method('getRoles')->willReturn(['ROLE_DPO']);

        $step = $this->createWorkflowStep(1, 'DPO sign-off', 5);
        $step->method('getApproverRole')->willReturn('ROLE_DPO');
        $step->method('getApproverUsers')->willReturn(['7']);
        $instance = $this->createWorkflowInstance('in_progress', $step, $this->createWorkflow('T', 'Risk', [$step]));
        $this->security->method('isGrantedForUser')->willReturnCallback(static fn ($u, $role): bool => $role === 'ROLE_DPO');

        $captured = null;
        $this->lifecycleService->expects($this->once())->method('transition')
            ->willReturnCallback(function (...$args) use (&$captured): void {
                $captured = $args;
            });

        $service = new WorkflowService(
            $this->entityManager,
            $this->workflowRepository,
            $this->workflowInstanceRepository,
            $this->userRepository,
            $this->emailService,
            $this->security,
            $this->lifecycleService,
            $this->regulatoryWorkflowLoader,
        );

        self::assertTrue($service->approveStep($instance, $approver, 'ok'));
        self::assertIsArray($captured);
        self::assertSame('approve', $captured[2]);
        self::assertTrue($captured[6], 'approver authority verified against the step');
        $eligible = $captured[7];
        self::assertInstanceOf(\App\Lifecycle\EligibleApprovers::class, $eligible);
        // A DPO step is the DPO's alone (S27: no admin override), and so is
        // every user named in approver_users. The transition role
        // (ROLE_MANAGER) alone is not enough.
        self::assertSame(['ROLE_DPO'], $eligible->roles);
        self::assertSame([7], $eligible->userIds);
    }

    /**
     * ISO 27001 5.1 / NIS-2 Art. 20: a step reserved for top management is
     * decided by whoever holds ROLE_TOP_MGMT — the admin override does not reach it.
     */
    #[Test]
    public function adminCannotApproveAStepReservedForTopManagement(): void
    {
        $admin = $this->createUser(3, 'Ada', 'Admin');
        $step = $this->createWorkflowStep(9, 'Leitung billigt', 5);
        $step->method('getApproverRole')->willReturn('ROLE_TOP_MGMT');
        $step->method('getApproverUsers')->willReturn([]);
        $this->security->method('isGrantedForUser')->willReturnCallback(static fn ($u, $role): bool => $role === 'ROLE_ADMIN');

        self::assertFalse($this->service->canUserApprove($admin, $step));
    }

    #[Test]
    public function topManagementApprovesItsOwnStep(): void
    {
        $board = $this->createUser(4, 'Bo', 'Board');
        $step = $this->createWorkflowStep(9, 'Leitung billigt', 5);
        $step->method('getApproverRole')->willReturn('ROLE_TOP_MGMT');
        $step->method('getApproverUsers')->willReturn([]);
        $this->security->method('isGrantedForUser')->willReturnCallback(static fn ($u, $role): bool => $role === 'ROLE_TOP_MGMT');

        self::assertTrue($this->service->canUserApprove($board, $step));
    }

    #[Test]
    public function adminStillApprovesOrdinarySteps(): void
    {
        $admin = $this->createUser(3, 'Ada', 'Admin');
        $step = $this->createWorkflowStep(10, 'CISO sign-off', 5);
        $step->method('getApproverRole')->willReturn('ROLE_CISO');
        $step->method('getApproverUsers')->willReturn([]);
        $this->security->method('isGrantedForUser')->willReturnCallback(static fn ($u, $role): bool => $role === 'ROLE_ADMIN');

        self::assertTrue($this->service->canUserApprove($admin, $step));
    }

    /** S27: a DPO step is the data protection officer's — the admin override does not reach it. */
    #[Test]
    public function adminCannotApproveAStepReservedForTheDpo(): void
    {
        $admin = $this->createUser(3, 'Ada', 'Admin');
        $step = $this->createWorkflowStep(10, 'DPO sign-off', 5);
        $step->method('getApproverRole')->willReturn('ROLE_DPO');
        $step->method('getApproverUsers')->willReturn([]);
        $this->security->method('isGrantedForUser')->willReturnCallback(static fn ($u, $role): bool => $role === 'ROLE_ADMIN');

        self::assertFalse($this->service->canUserApprove($admin, $step));
    }

    private function createUser(int $id, string $firstName, string $lastName): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getFirstName')->willReturn($firstName);
        $user->method('getLastName')->willReturn($lastName);
        return $user;
    }

    private function createWorkflowStep(int $id, string $name, int $daysToComplete): MockObject
    {
        $step = $this->createMock(WorkflowStep::class);
        $step->method('getId')->willReturn($id);
        $step->method('getName')->willReturn($name);
        $step->method('getDaysToComplete')->willReturn($daysToComplete);
        return $step;
    }

    private function createWorkflow(string $name, string $entityType, array $steps = []): MockObject
    {
        $workflow = $this->createMock(Workflow::class);
        $workflow->method('getName')->willReturn($name);
        $workflow->method('getEntityType')->willReturn($entityType);
        $workflow->method('getSteps')->willReturn(new ArrayCollection($steps));
        return $workflow;
    }

    private function createWorkflowInstance(string $status, MockObject $currentStep, ?MockObject $workflow = null): MockObject
    {
        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getStatus')->willReturn($status);
        $instance->method('getCurrentStep')->willReturn($currentStep);

        if ($workflow) {
            $instance->method('getWorkflow')->willReturn($workflow);
        }

        return $instance;
    }
}
