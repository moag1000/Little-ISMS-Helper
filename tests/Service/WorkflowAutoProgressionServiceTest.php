<?php

namespace App\Tests\Service;

use App\Entity\RiskAppetite;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Repository\RiskAppetiteRepository;
use App\Service\WorkflowAutoProgressionService;
use App\Service\WorkflowService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

class WorkflowAutoProgressionServiceTest extends TestCase
{
    private MockObject $entityManager;
    private PropertyAccessorInterface $propertyAccessor;
    private MockObject $workflowService;
    private MockObject $logger;
    private MockObject $riskAppetiteRepository;
    private WorkflowAutoProgressionService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->propertyAccessor = new PropertyAccessor();
        $this->workflowService = $this->createMock(WorkflowService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->riskAppetiteRepository = $this->createMock(RiskAppetiteRepository::class);

        $this->service = new WorkflowAutoProgressionService(
            $this->entityManager,
            $this->propertyAccessor,
            $this->workflowService,
            $this->logger,
            $this->riskAppetiteRepository
        );
    }

    // ========== checkAndProgressWorkflow TESTS ==========

    public function testCheckAndProgressWorkflowReturnsFalseForNewEntity(): void
    {
        $entity = new TestEntity();
        // No ID set - entity not persisted
        $user = $this->createMock(User::class);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    public function testCheckAndProgressWorkflowReturnsFalseWhenNoWorkflowInstance(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $user = $this->createMock(User::class);

        $this->workflowService->method('getWorkflowInstance')
            ->with('TestEntity', 1)
            ->willReturn(null);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    public function testCheckAndProgressWorkflowReturnsFalseWhenWorkflowNotInProgress(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $user = $this->createMock(User::class);

        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getStatus')->willReturn('completed');

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    public function testCheckAndProgressWorkflowReturnsFalseWhenNoCurrentStep(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $user = $this->createMock(User::class);

        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getStatus')->willReturn('in_progress');
        $workflowInstance->method('getCurrentStep')->willReturn(null);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    public function testCheckAndProgressWorkflowReturnsFalseWhenNoAutoProgressConditions(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $user = $this->createMock(User::class);

        $step = $this->createMock(WorkflowStep::class);
        $step->method('getMetadata')->willReturn(null);

        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getStatus')->willReturn('in_progress');
        $workflowInstance->method('getCurrentStep')->willReturn($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    // ========== Field Completion Tests ==========

    public function testFieldCompletionReturnsTrueWhenAllFieldsFilled(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test Name');
        $entity->setDescription('Test Description');
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletion(['name', 'description'], 'TestEntity');
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        // Expect workflow to progress (logger called with info)
        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertTrue($result);
    }

    public function testFieldCompletionReturnsFalseWhenFieldEmpty(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test Name');
        // description is null - not filled
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletion(['name', 'description'], 'TestEntity');
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    public function testFieldCompletionReturnsFalseWhenEntityTypeMismatch(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $user = $this->createUser();

        // Step expects 'DataBreach' but entity is 'TestEntity'
        $step = $this->createStepWithFieldCompletion(['name'], 'DataBreach');
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    public function testFieldCompletionWithEmptyStringReturnsFalse(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('');  // Empty string
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletion(['name'], 'TestEntity');
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    public function testFieldCompletionWithEmptyArrayReturnsFalse(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setTags([]);  // Empty array
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletion(['tags'], 'TestEntity');
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    // ========== Condition Evaluation Tests ==========

    public function testSimpleConditionGreaterThanOrEqual(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setScore(15);
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletionAndCondition(
            ['name'],
            'TestEntity',
            'score >= 10'
        );
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertTrue($result);
    }

    public function testSimpleConditionLessThanFails(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setScore(5);
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletionAndCondition(
            ['name'],
            'TestEntity',
            'score >= 10'
        );
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    public function testBooleanConditionTrue(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setActive(true);
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletionAndCondition(
            ['name'],
            'TestEntity',
            'active = true'
        );
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertTrue($result);
    }

    public function testBooleanConditionFalse(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setActive(false);
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletionAndCondition(
            ['name'],
            'TestEntity',
            'active = true'
        );
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    public function testNotEqualCondition(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setStatus('approved');
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletionAndCondition(
            ['name'],
            'TestEntity',
            'status != pending'
        );
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertTrue($result);
    }

    public function testNullConditionEqual(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        // description is null
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletionAndCondition(
            ['name'],
            'TestEntity',
            'description = null'
        );
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertTrue($result);
    }

    public function testNullConditionNotEqual(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setDescription('Has description');
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletionAndCondition(
            ['name'],
            'TestEntity',
            'description != null'
        );
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertTrue($result);
    }

    // ========== Complex Condition Tests (AND/OR) ==========

    public function testAndConditionBothTrue(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setScore(20);
        $entity->setActive(true);
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletionAndCondition(
            ['name'],
            'TestEntity',
            'score >= 10 AND active = true'
        );
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertTrue($result);
    }

    public function testAndConditionOneFalse(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setScore(20);
        $entity->setActive(false);  // This makes AND fail
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletionAndCondition(
            ['name'],
            'TestEntity',
            'score >= 10 AND active = true'
        );
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    public function testOrConditionOneTrue(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setScore(5);  // Below threshold
        $entity->setActive(true);  // But this is true
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletionAndCondition(
            ['name'],
            'TestEntity',
            'score >= 10 OR active = true'
        );
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertTrue($result);
    }

    public function testOrConditionBothFalse(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setScore(5);  // Below threshold
        $entity->setActive(false);
        $user = $this->createUser();

        $step = $this->createStepWithFieldCompletionAndCondition(
            ['name'],
            'TestEntity',
            'score >= 10 OR active = true'
        );
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    // ========== Auto-Type Progression Tests ==========

    public function testAutoTypeProgressesUnconditionally(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $user = $this->createUser();

        $step = $this->createStepWithAutoType();
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertTrue($result);
    }

    public function testAutoTypeWithConditionChecksCondition(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $entity->setActive(false);
        $user = $this->createUser();

        $step = $this->createStepWithAutoTypeAndCondition('active = true');
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    // ========== Risk Appetite Tests ==========

    public function testRiskAppetiteReturnsFalseForNonRiskEntity(): void
    {
        $entity = new TestEntity();
        $entity->setId(1);
        $user = $this->createUser();

        $step = $this->createStepWithRiskAppetite();
        $workflowInstance = $this->createInProgressWorkflowInstance($step);

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($workflowInstance);

        $result = $this->service->checkAndProgressWorkflow($entity, $user);

        $this->assertFalse($result);
    }

    // ========== Helper Methods ==========

    private function createUser(): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getFirstName')->willReturn('Test');
        $user->method('getLastName')->willReturn('User');
        return $user;
    }

    private function createInProgressWorkflowInstance(MockObject $step): MockObject
    {
        // Create a workflow with steps
        $nextStep = $this->createMock(WorkflowStep::class);
        $nextStep->method('getId')->willReturn(2);
        $nextStep->method('getName')->willReturn('Next Step');
        $nextStep->method('getDaysToComplete')->willReturn(5);
        $nextStep->method('getStepType')->willReturn('approval');
        $nextStep->method('getMetadata')->willReturn(null);  // No auto-progress

        $workflow = $this->createMock(Workflow::class);
        $workflow->method('getSteps')->willReturn(new ArrayCollection([$step, $nextStep]));

        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getStatus')->willReturn('in_progress');
        $workflowInstance->method('getCurrentStep')->willReturn($step);
        $workflowInstance->method('getId')->willReturn(1);
        $workflowInstance->method('getWorkflow')->willReturn($workflow);
        $workflowInstance->method('addApprovalHistoryEntry')->willReturnSelf();
        $workflowInstance->method('addCompletedStep')->willReturnSelf();
        $workflowInstance->method('setCurrentStep')->willReturnSelf();
        $workflowInstance->method('setDueDate')->willReturnSelf();
        return $workflowInstance;
    }

    private function createStepWithFieldCompletion(array $fields, string $entityType): MockObject
    {
        $step = $this->createMock(WorkflowStep::class);
        $step->method('getId')->willReturn(1);
        $step->method('getName')->willReturn('Test Step');
        $step->method('getMetadata')->willReturn([
            'autoProgressConditions' => [
                'type' => 'field_completion',
                'entity' => $entityType,
                'fields' => $fields,
            ],
        ]);
        return $step;
    }

    private function createStepWithFieldCompletionAndCondition(
        array $fields,
        string $entityType,
        string $condition
    ): MockObject {
        $step = $this->createMock(WorkflowStep::class);
        $step->method('getId')->willReturn(1);
        $step->method('getName')->willReturn('Test Step');
        $step->method('getMetadata')->willReturn([
            'autoProgressConditions' => [
                'type' => 'field_completion',
                'entity' => $entityType,
                'fields' => $fields,
                'condition' => $condition,
            ],
        ]);
        return $step;
    }

    private function createStepWithAutoType(): MockObject
    {
        $step = $this->createMock(WorkflowStep::class);
        $step->method('getId')->willReturn(1);
        $step->method('getName')->willReturn('Auto Step');
        $step->method('getMetadata')->willReturn([
            'autoProgressConditions' => [
                'type' => 'auto',
            ],
        ]);
        return $step;
    }

    private function createStepWithAutoTypeAndCondition(string $condition): MockObject
    {
        $step = $this->createMock(WorkflowStep::class);
        $step->method('getId')->willReturn(1);
        $step->method('getName')->willReturn('Auto Step');
        $step->method('getMetadata')->willReturn([
            'autoProgressConditions' => [
                'type' => 'auto',
                'condition' => $condition,
            ],
        ]);
        return $step;
    }

    private function createStepWithRiskAppetite(): MockObject
    {
        $step = $this->createMock(WorkflowStep::class);
        $step->method('getId')->willReturn(1);
        $step->method('getName')->willReturn('Risk Appetite Step');
        $step->method('getMetadata')->willReturn([
            'autoProgressConditions' => [
                'type' => 'risk_appetite',
                'entity' => 'Risk',
                'riskScoreField' => 'residualRisk',
            ],
        ]);
        return $step;
    }
}

/**
 * Test entity for workflow auto-progression tests
 */
class TestEntity
{
    private ?int $id = null;
    private ?string $name = null;
    private ?string $description = null;
    private ?string $status = null;
    private int $score = 0;
    private bool $active = false;
    private array $tags = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): self
    {
        $this->score = $score;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;
        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }
}
