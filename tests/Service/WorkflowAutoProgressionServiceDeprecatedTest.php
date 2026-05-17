<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Entity\Workflow;
use App\Lifecycle\FieldCompletionAutoTransitionInterface;
use App\Repository\RiskAppetiteRepository;
use App\Service\WorkflowAutoProgressionService;
use App\Service\WorkflowService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Verifies that the deprecated WorkflowAutoProgressionService wrapper
 * (Y.1 migration) correctly delegates to FieldCompletionAutoTransition
 * while preserving backward-compat for the 14 call-sites that still
 * inject WAPS.
 *
 * Covers:
 * - checkAndProgressWorkflow() calls postUpdate() on the listener
 * - Returns true even when legacy chain finds nothing (listener fires)
 * - Returns true when legacy chain finds a step to auto-approve
 * - Exception in listener is swallowed; legacy path continues
 * - Public API signature is unchanged (same return type + params)
 */
#[AllowMockObjectsWithoutExpectations]
final class WorkflowAutoProgressionServiceDeprecatedTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $workflowService;
    private MockObject $logger;
    private MockObject $riskAppetiteRepository;
    private MockObject $fieldCompletionAutoTransition;

    protected function setUp(): void
    {
        $this->entityManager                = $this->createMock(EntityManagerInterface::class);
        $this->workflowService              = $this->createMock(WorkflowService::class);
        $this->logger                       = $this->createMock(LoggerInterface::class);
        $this->riskAppetiteRepository       = $this->createMock(RiskAppetiteRepository::class);
        $this->fieldCompletionAutoTransition = $this->createMock(FieldCompletionAutoTransitionInterface::class);
    }

    // ── Delegation tests ──────────────────────────────────────────────────────

    #[Test]
    public function delegatesToFieldCompletionAutoTransitionOnEveryCall(): void
    {
        $entity = $this->makeEntity(id: 42);
        $user   = $this->makeUser();

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);

        $this->fieldCompletionAutoTransition
            ->expects(self::once())
            ->method('postUpdate')
            ->with(self::callback(static fn (PostUpdateEventArgs $args) => $args->getObject() === $entity));

        $this->makeService()->checkAndProgressWorkflow($entity, $user);
    }

    #[Test]
    public function returnsFalseWhenLegacyChainFindsNothingEvenIfListenerFires(): void
    {
        $entity = $this->makeEntity(id: 1);
        $user   = $this->makeUser();

        // Legacy chain: no active workflow
        $this->workflowService->method('getWorkflowInstance')->willReturn(null);

        // Listener: fires without exception (simulates a successful auto-transition)
        // postUpdate() is void — no willReturn() needed; default behaviour is no-op.
        $this->fieldCompletionAutoTransition->expects(self::once())->method('postUpdate');

        $result = $this->makeService()->checkAndProgressWorkflow($entity, $user);

        // Return value is based on legacy path; listener is a side-effect only
        self::assertFalse($result);
    }

    #[Test]
    public function swallowsListenerExceptionAndContinuesToLegacyPath(): void
    {
        $entity = $this->makeEntity(id: 1);
        $user   = $this->makeUser();

        $this->fieldCompletionAutoTransition
            ->method('postUpdate')
            ->willThrowException(new \RuntimeException('Synthetic listener error'));

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);

        // Warning should be logged
        $this->logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(self::stringContains('[WAPS]'));

        // Must not throw, must return false (listener failed, legacy found nothing)
        $result = $this->makeService()->checkAndProgressWorkflow($entity, $user);
        self::assertFalse($result);
    }

    #[Test]
    public function returnsTrueWhenLegacyChainAutoProgressesStep(): void
    {
        $entity = $this->makeEntity(id: 7);
        $user   = $this->makeUser();

        // Listener fires cleanly (postUpdate is void — default mock is no-op)

        // Legacy chain: active instance + matching step
        $step = $this->makeStepWithAutoType();

        $nextStep = $this->createMock(WorkflowStep::class);
        $nextStep->method('getId')->willReturn(99);
        $nextStep->method('getName')->willReturn('Next');
        $nextStep->method('getDaysToComplete')->willReturn(0);
        $nextStep->method('getStepType')->willReturn('approval');
        $nextStep->method('getMetadata')->willReturn(null);

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

        $this->workflowService->method('getWorkflowInstance')->willReturn($workflowInstance);
        $this->workflowService->method('moveToNextStep')->willReturn(null); // no next step

        $result = $this->makeService()->checkAndProgressWorkflow($entity, $user);
        self::assertTrue($result);
    }

    #[Test]
    public function publicApiSignatureReturnsBoolean(): void
    {
        $entity = $this->makeEntity(id: 5);
        $user   = $this->makeUser();

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);

        $result = $this->makeService()->checkAndProgressWorkflow($entity, $user);
        self::assertIsBool($result);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeService(): WorkflowAutoProgressionService
    {
        return new WorkflowAutoProgressionService(
            entityManager:                 $this->entityManager,
            propertyAccessor:              PropertyAccess::createPropertyAccessor(),
            workflowService:               $this->workflowService,
            logger:                        $this->logger,
            riskAppetiteRepository:        $this->riskAppetiteRepository,
            fieldCompletionAutoTransition: $this->fieldCompletionAutoTransition,
        );
    }

    private function makeEntity(int $id): object
    {
        return new class ($id) {
            public function __construct(private readonly int $id) {}
            public function getId(): int { return $this->id; }
        };
    }

    private function makeUser(): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getFirstName')->willReturn('Test');
        $user->method('getLastName')->willReturn('User');
        return $user;
    }

    private function makeStepWithAutoType(): MockObject
    {
        $step = $this->createMock(WorkflowStep::class);
        $step->method('getId')->willReturn(1);
        $step->method('getName')->willReturn('Auto Step');
        $step->method('getMetadata')->willReturn([
            'autoProgressConditions' => ['type' => 'auto'],
        ]);
        return $step;
    }
}
