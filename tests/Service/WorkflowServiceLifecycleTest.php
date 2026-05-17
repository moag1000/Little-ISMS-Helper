<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Workflow\Loader\RegulatoryWorkflowLoader;
use App\Repository\WorkflowInstanceRepository;
use App\Repository\WorkflowRepository;
use App\Repository\UserRepository;
use App\Service\EmailNotificationService;
use App\Service\WorkflowService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Sprint Y.0 — WorkflowService lifecycle-delegate tests.
 *
 * Verifies that WorkflowService's public methods correctly delegate
 * status transitions to LifecycleService (Symfony state-machine facade)
 * instead of calling setStatus() directly.
 *
 * Test scope:
 *  - moveToNextStep() on final step calls lifecycleService->transition('approve')
 *  - rejectStep() calls lifecycleService->transition('reject')
 *  - cancelWorkflow() from 'pending'      calls transition('cancel')
 *  - cancelWorkflow() from 'in_progress'  calls transition('cancel_in_progress')
 *  - startWorkflow()  calls transition('start') when first step exists
 */
#[AllowMockObjectsWithoutExpectations]
final class WorkflowServiceLifecycleTest extends TestCase
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

    // -----------------------------------------------------------------------
    // moveToNextStep — final step fires 'approve' transition
    // -----------------------------------------------------------------------

    #[Test]
    public function moveToNextStepOnFinalStepTransitionsToApproved(): void
    {
        $step = $this->buildStep(1, 'Final Review', 5);
        $workflow = $this->buildWorkflow('Test', 'Risk', [$step]);
        $instance = $this->buildRealInstance('in_progress', $step, $workflow);

        $this->lifecycleService->expects($this->once())
            ->method('transition')
            ->with($instance, 'workflow_instance_lifecycle', 'approve');

        $result = $this->service->moveToNextStep($instance);

        $this->assertNull($result, 'Final step must return null from moveToNextStep');
        $this->assertNull($instance->getCurrentStep(), 'currentStep must be null after completion');
        $this->assertNotNull($instance->getCompletedAt(), 'completedAt must be set');
    }

    #[Test]
    public function moveToNextStepOnIntermediateStepDoesNotTransition(): void
    {
        $step1 = $this->buildStep(1, 'Step 1', 3);
        $step2 = $this->buildStep(2, 'Step 2', 3);
        $workflow = $this->buildWorkflow('Test', 'Risk', [$step1, $step2]);
        $instance = $this->buildRealInstance('in_progress', $step1, $workflow);

        $this->lifecycleService->expects($this->never())->method('transition');

        $result = $this->service->moveToNextStep($instance);

        $this->assertSame($step2, $result, 'Should return the next step object');
        $this->assertSame('in_progress', $instance->getStatus(), 'Status must remain in_progress on intermediate step');
        $this->assertSame(1, $instance->getCurrentStepIndex());
    }

    // -----------------------------------------------------------------------
    // rejectStep
    // -----------------------------------------------------------------------

    #[Test]
    public function rejectStepCallsRejectTransition(): void
    {
        $user = $this->buildUser(1, 'Jane', 'Smith');

        $step = $this->buildStep(1, 'Review', 5);
        $step->method('getApproverRole')->willReturn('ROLE_MANAGER');
        $step->method('getApproverUsers')->willReturn(null);

        $instance = $this->buildRealInstance('in_progress', $step);

        $this->security->method('isGranted')
            ->willReturnCallback(static fn ($role) => in_array($role, ['ROLE_ADMIN', 'ROLE_MANAGER'], true));

        $this->lifecycleService->expects($this->once())
            ->method('transition')
            ->with($instance, 'workflow_instance_lifecycle', 'reject', $user, 'Incomplete evidence');

        $result = $this->service->rejectStep($instance, $user, 'Incomplete evidence');

        $this->assertTrue($result);
        $this->assertSame('Incomplete evidence', $instance->getComments());
        $this->assertNotNull($instance->getCompletedAt());
    }

    #[Test]
    public function rejectStepReturnsFalseIfNotInProgress(): void
    {
        $user = $this->buildUser(1, 'John', 'Doe');
        $instance = $this->buildRealInstance('approved');

        $this->lifecycleService->expects($this->never())->method('transition');

        $result = $this->service->rejectStep($instance, $user, 'Late rejection');

        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------------
    // cancelWorkflow
    // -----------------------------------------------------------------------

    #[Test]
    public function cancelWorkflowFromPendingCallsCancelTransition(): void
    {
        $instance = $this->buildRealInstance('pending');

        $this->lifecycleService->expects($this->once())
            ->method('transition')
            ->with($instance, 'workflow_instance_lifecycle', 'cancel', null, 'Project ended');

        $this->service->cancelWorkflow($instance, 'Project ended');

        $this->assertSame('Project ended', $instance->getComments());
        $this->assertNotNull($instance->getCompletedAt());
    }

    #[Test]
    public function cancelWorkflowFromInProgressCallsCancelInProgressTransition(): void
    {
        $instance = $this->buildRealInstance('in_progress');

        $this->lifecycleService->expects($this->once())
            ->method('transition')
            ->with($instance, 'workflow_instance_lifecycle', 'cancel_in_progress', null, 'Withdrawn by CISO');

        $this->service->cancelWorkflow($instance, 'Withdrawn by CISO');
    }

    // -----------------------------------------------------------------------
    // startWorkflow — transitions pending → in_progress when steps exist
    // -----------------------------------------------------------------------

    #[Test]
    public function startWorkflowWithStepsCallsStartTransition(): void
    {
        $currentUser = $this->buildUser(99, 'Admin', 'User');
        $this->security->method('getUser')->willReturn($currentUser);

        $step = $this->buildStep(1, 'Initial Review', 7);
        $step->method('getStepType')->willReturn('approval');
        $step->method('getApproverUsers')->willReturn([]);
        $step->method('getApproverRole')->willReturn('ROLE_MANAGER');

        $dbWorkflow = $this->buildWorkflow('Risk Review', 'Risk', [$step]);
        $this->workflowRepository->method('findOneBy')->willReturn($dbWorkflow);

        // Make duplicate-check return null so a new instance is created
        $query = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOneOrNullResult'])
            ->getMock();
        $query->method('getOneOrNullResult')->willReturn(null);
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

        $this->userRepository->method('findByRole')->willReturn([]);

        $this->lifecycleService->expects($this->once())
            ->method('transition')
            ->with(
                $this->isInstanceOf(WorkflowInstance::class),
                'workflow_instance_lifecycle',
                'start',
                $currentUser,
            );

        $instance = $this->service->startWorkflow('Risk', 42);

        $this->assertInstanceOf(WorkflowInstance::class, $instance);
        $this->assertSame('Risk', $instance->getEntityType());
        $this->assertSame(42, $instance->getEntityId());
        $this->assertSame($step, $instance->getCurrentStep());
        $this->assertSame(0, $instance->getCurrentStepIndex());
    }

    #[Test]
    public function startWorkflowWithNoStepsStaysPendingAndDoesNotTransition(): void
    {
        $currentUser = $this->buildUser(1, 'Admin', 'User');
        $this->security->method('getUser')->willReturn($currentUser);

        $dbWorkflow = $this->buildWorkflow('Empty Workflow', 'Risk', []);
        $this->workflowRepository->method('findOneBy')->willReturn($dbWorkflow);
        $this->workflowInstanceRepository->method('findOneBy')->willReturn(null);

        $this->lifecycleService->expects($this->never())->method('transition');

        $instance = $this->service->startWorkflow('Risk', 7);

        $this->assertInstanceOf(WorkflowInstance::class, $instance);
        $this->assertSame('pending', $instance->getStatus());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function buildUser(int $id, string $first, string $last): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getFirstName')->willReturn($first);
        $user->method('getLastName')->willReturn($last);
        return $user;
    }

    private function buildStep(int $id, string $name, int $days): MockObject
    {
        $step = $this->createMock(WorkflowStep::class);
        $step->method('getId')->willReturn($id);
        $step->method('getName')->willReturn($name);
        $step->method('getDaysToComplete')->willReturn($days);
        return $step;
    }

    private function buildWorkflow(string $name, string $entityType, array $steps = []): MockObject
    {
        $wf = $this->createMock(Workflow::class);
        $wf->method('getName')->willReturn($name);
        $wf->method('getEntityType')->willReturn($entityType);
        $wf->method('getSteps')->willReturn(new ArrayCollection($steps));
        return $wf;
    }

    /**
     * Build a real WorkflowInstance (not a mock) so state-machine field reads work correctly.
     */
    private function buildRealInstance(string $status, ?MockObject $step = null, ?MockObject $workflow = null): WorkflowInstance
    {
        $instance = new WorkflowInstance();
        $instance->setStatus($status);
        if ($step !== null) {
            $instance->setCurrentStep($step);
        }
        if ($workflow !== null) {
            $instance->setWorkflow($workflow);
        }
        return $instance;
    }
}
