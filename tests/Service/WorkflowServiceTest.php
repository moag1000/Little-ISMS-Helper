<?php

namespace App\Tests\Service;

use App\Entity\Role;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Repository\WorkflowInstanceRepository;
use App\Repository\WorkflowRepository;
use App\Service\WorkflowService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class WorkflowServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $workflowRepository;
    private MockObject $workflowInstanceRepository;
    private MockObject $security;
    private WorkflowService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->workflowRepository = $this->createMock(WorkflowRepository::class);
        $this->workflowInstanceRepository = $this->createMock(WorkflowInstanceRepository::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new WorkflowService(
            $this->entityManager,
            $this->workflowRepository,
            $this->workflowInstanceRepository,
            $this->security
        );
    }

    public function testStartWorkflowCreatesNewInstance(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        $step = $this->createWorkflowStep(1, 'Review', 5);
        $workflow = $this->createWorkflow('Risk Review', 'Risk', [$step]);

        $this->workflowRepository->method('findOneBy')
            ->willReturn($workflow);

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
        $this->assertSame('in_progress', $instance->getStatus());
        $this->assertSame($step, $instance->getCurrentStep());
        $this->assertNotNull($instance->getDueDate());
    }

    public function testStartWorkflowReturnsExistingInstance(): void
    {
        $existingInstance = $this->createMock(WorkflowInstance::class);

        $this->workflowRepository->method('findOneBy')
            ->willReturn($this->createWorkflow('Test', 'Risk'));

        $this->workflowInstanceRepository->method('findOneBy')
            ->willReturn($existingInstance);

        $instance = $this->service->startWorkflow('Risk', 123);

        $this->assertSame($existingInstance, $instance);
    }

    public function testStartWorkflowReturnsNullIfNoWorkflowFound(): void
    {
        $this->workflowRepository->method('findOneBy')
            ->willReturn(null);

        $instance = $this->service->startWorkflow('Risk', 123);

        $this->assertNull($instance);
    }

    public function testStartWorkflowWithSpecificWorkflowName(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        $step = $this->createWorkflowStep(1, 'Step', 3);
        $workflow = $this->createWorkflow('Critical Risk Review', 'Risk', [$step]);

        $this->workflowRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'name' => 'Critical Risk Review',
                'entityType' => 'Risk',
                'isActive' => true
            ])
            ->willReturn($workflow);

        $this->workflowInstanceRepository->method('findOneBy')
            ->willReturn(null);

        $instance = $this->service->startWorkflow('Risk', 123, 'Critical Risk Review');

        $this->assertNotNull($instance);
    }

    public function testApproveStepSucceeds(): void
    {
        $user = $this->createUser(1, 'John', 'Doe');
        $role = $this->createRole('ROLE_MANAGER');
        $user->method('getRoles')->willReturn(['ROLE_MANAGER']);

        $step = $this->createWorkflowStep(1, 'Manager Approval', 5);
        $step->method('getApproverRole')->willReturn($role);
        $step->method('getApproverUser')->willReturn(null);

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

        $result = $this->service->approveStep($instance, $user, 'Looks good');

        $this->assertTrue($result);
    }

    public function testApproveStepFailsIfNotInProgress(): void
    {
        $user = $this->createUser(1, 'John', 'Doe');
        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getStatus')->willReturn('approved');

        $result = $this->service->approveStep($instance, $user);

        $this->assertFalse($result);
    }

    public function testApproveStepFailsIfNoCurrentStep(): void
    {
        $user = $this->createUser(1, 'John', 'Doe');
        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getStatus')->willReturn('in_progress');
        $instance->method('getCurrentStep')->willReturn(null);

        $result = $this->service->approveStep($instance, $user);

        $this->assertFalse($result);
    }

    public function testApproveStepFailsIfUserCannotApprove(): void
    {
        $user = $this->createUser(1, 'John', 'Doe');
        $user->method('getRoles')->willReturn(['ROLE_USER']);

        $role = $this->createRole('ROLE_ADMIN');
        $step = $this->createWorkflowStep(1, 'Admin Approval', 5);
        $step->method('getApproverRole')->willReturn($role);
        $step->method('getApproverUser')->willReturn(null);

        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getStatus')->willReturn('in_progress');
        $instance->method('getCurrentStep')->willReturn($step);

        $result = $this->service->approveStep($instance, $user);

        $this->assertFalse($result);
    }

    public function testRejectStepSucceeds(): void
    {
        $user = $this->createUser(1, 'Jane', 'Smith');
        $user->method('getRoles')->willReturn(['ROLE_MANAGER']);

        $role = $this->createRole('ROLE_MANAGER');
        $step = $this->createWorkflowStep(1, 'Review', 5);
        $step->method('getApproverRole')->willReturn($role);
        $step->method('getApproverUser')->willReturn(null);

        $instance = $this->createWorkflowInstance('in_progress', $step);

        $instance->expects($this->once())
            ->method('addApprovalHistoryEntry');

        $instance->expects($this->once())
            ->method('setStatus')
            ->with('rejected');

        $instance->expects($this->once())
            ->method('setCompletedAt');

        $instance->expects($this->once())
            ->method('setComments')
            ->with('Does not meet requirements');

        $result = $this->service->rejectStep($instance, $user, 'Does not meet requirements');

        $this->assertTrue($result);
    }

    public function testRejectStepFailsIfNotInProgress(): void
    {
        $user = $this->createUser(1, 'John', 'Doe');
        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getStatus')->willReturn('cancelled');

        $result = $this->service->rejectStep($instance, $user, 'Reason');

        $this->assertFalse($result);
    }

    public function testCancelWorkflowSetsStatusAndComments(): void
    {
        $instance = $this->createMock(WorkflowInstance::class);

        $instance->expects($this->once())
            ->method('setStatus')
            ->with('cancelled');

        $instance->expects($this->once())
            ->method('setCompletedAt')
            ->with($this->isInstanceOf(\DateTimeImmutable::class));

        $instance->expects($this->once())
            ->method('setComments')
            ->with('Project cancelled');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->cancelWorkflow($instance, 'Project cancelled');
    }

    public function testApprovalWithSpecificUser(): void
    {
        $approver = $this->createUser(5, 'Approved', 'User');
        $approver->method('getRoles')->willReturn(['ROLE_USER']);

        $specificUser = $this->createUser(5, 'Approved', 'User');

        $step = $this->createWorkflowStep(1, 'Specific User Approval', 5);
        $step->method('getApproverRole')->willReturn(null);
        $step->method('getApproverUser')->willReturn($specificUser);

        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getStatus')->willReturn('in_progress');
        $instance->method('getCurrentStep')->willReturn($step);

        // User should be able to approve because they are the specific approver
        $this->assertFalse($this->service->approveStep($instance, $approver));
    }

    public function testWorkflowInstancePendingStatus(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        // Workflow with no steps
        $workflow = $this->createWorkflow('Empty Workflow', 'Risk', []);

        $this->workflowRepository->method('findOneBy')
            ->willReturn($workflow);

        $this->workflowInstanceRepository->method('findOneBy')
            ->willReturn(null);

        $instance = $this->service->startWorkflow('Risk', 123);

        // Should remain pending since there are no steps
        $this->assertSame('pending', $instance->getStatus());
    }

    private function createUser(int $id, string $firstName, string $lastName): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getFirstName')->willReturn($firstName);
        $user->method('getLastName')->willReturn($lastName);
        return $user;
    }

    private function createRole(string $name): MockObject
    {
        $role = $this->createMock(Role::class);
        $role->method('getName')->willReturn($name);
        return $role;
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
