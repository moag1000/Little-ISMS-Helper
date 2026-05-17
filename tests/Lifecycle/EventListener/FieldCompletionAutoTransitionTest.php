<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\EventListener;

use App\Lifecycle\EventListener\FieldCompletionAutoTransition;
use App\Lifecycle\LifecycleTransitionInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Unit tests for FieldCompletionAutoTransition.
 *
 * Covers:
 * - All fields completed AND transition enabled → lifecycle transition called
 * - One required field empty (null) → transition NOT called
 * - Transition not enabled in workflow → transition NOT called
 * - Entity not in rules map → no interaction with LifecycleService
 * - Missing workflow in registry (e.g. not yet shipped) → skipped gracefully
 * - Exception in LifecycleService → swallowed, original write unaffected
 */
final class FieldCompletionAutoTransitionTest extends TestCase
{
    #[Test]
    public function callsTransitionWhenAllFieldsCompletedAndTransitionEnabled(): void
    {
        $entity = $this->makeEntity('high', 100, ['PII']);

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('can')->with($entity, 'assess')->willReturn(true);

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->with($entity, 'data_breach_lifecycle')->willReturn($workflow);

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::once())
            ->method('transition')
            ->with($entity, 'data_breach_lifecycle', 'assess', null, 'Auto-transition: assess_when_complete');

        $listener = $this->makeListener($registry, $lifecycleService, $entity::class);
        $listener->postUpdate($this->makeEvent($entity));
    }

    #[Test]
    public function doesNotCallTransitionWhenOneFieldIsEmpty(): void
    {
        // affectedDataSubjectsCount is null → field not completed
        $entity = $this->makeEntity('high', null, ['PII']);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('get');

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::never())->method('transition');

        $listener = $this->makeListener($registry, $lifecycleService, $entity::class);
        $listener->postUpdate($this->makeEvent($entity));
    }

    #[Test]
    public function doesNotCallTransitionWhenWorkflowCannotApply(): void
    {
        $entity = $this->makeEntity('high', 100, ['PII']);

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('can')->with($entity, 'assess')->willReturn(false);

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn($workflow);

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::never())->method('transition');

        $listener = $this->makeListener($registry, $lifecycleService, $entity::class);
        $listener->postUpdate($this->makeEvent($entity));
    }

    #[Test]
    public function skipsEntityNotInRulesMap(): void
    {
        $entity = new \stdClass();

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('get');

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::never())->method('transition');

        // Rules map a different class — stdClass is not mapped
        $listener = $this->makeListener($registry, $lifecycleService, 'App\Entity\SomeOtherEntity');
        $listener->postUpdate($this->makeEvent($entity));
    }

    #[Test]
    public function skipsGracefullyWhenWorkflowNotRegistered(): void
    {
        $entity = $this->makeEntity('high', 100, ['PII']);

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willThrowException(new \InvalidArgumentException('Workflow not found'));

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::never())->method('transition');

        $listener = $this->makeListener($registry, $lifecycleService, $entity::class);
        // Must not throw
        $listener->postUpdate($this->makeEvent($entity));
    }

    #[Test]
    public function swallowsExceptionFromLifecycleService(): void
    {
        $entity = $this->makeEntity('high', 100, ['PII']);

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('can')->willReturn(true);

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn($workflow);

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->method('transition')->willThrowException(new \RuntimeException('State-machine error'));

        $listener = $this->makeListener($registry, $lifecycleService, $entity::class);
        // Must not propagate the exception
        $listener->postUpdate($this->makeEvent($entity));
        self::assertTrue(true); // reached without throw
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeEntity(?string $severity, ?int $count, ?array $categories): object
    {
        return new class ($severity, $count, $categories) {
            public function __construct(
                private readonly ?string $severity,
                private readonly ?int $count,
                private readonly ?array $categories,
            ) {}

            public function getSeverity(): ?string { return $this->severity; }
            public function getAffectedDataSubjectsCount(): ?int { return $this->count; }
            public function getDataCategories(): ?array { return $this->categories; }
        };
    }

    private function makeListener(
        Registry $registry,
        LifecycleTransitionInterface $lifecycleService,
        string $mappedClass,
    ): FieldCompletionAutoTransition {
        return new FieldCompletionAutoTransition(
            workflowRegistry: $registry,
            lifecycleService: $lifecycleService,
            rules: [
                $mappedClass => [
                    'assess_when_complete' => [
                        'workflow' => 'data_breach_lifecycle',
                        'transition' => 'assess',
                        'required_fields' => ['severity', 'affectedDataSubjectsCount', 'dataCategories'],
                    ],
                ],
            ],
        );
    }

    private function makeEvent(object $entity): PostUpdateEventArgs
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        return new PostUpdateEventArgs($entity, $em);
    }
}
