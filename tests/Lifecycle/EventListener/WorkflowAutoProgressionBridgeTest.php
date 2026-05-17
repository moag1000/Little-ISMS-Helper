<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\EventListener;

use App\Entity\WorkflowInstance;
use App\Lifecycle\EventListener\WorkflowAutoProgressionBridge;
use App\Lifecycle\LifecycleTransitionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WorkflowAutoProgressionBridge.
 *
 * Covers:
 * - Non-WorkflowInstance entity → no-op
 * - WorkflowInstance with status != 'approved' → no-op
 * - WorkflowInstance approved but entity class not in bridges → no-op
 * - WorkflowInstance approved with mapped entity class → bridge fires transition
 * - Parent entity not found (em->find returns null) → no transition
 * - Exception during transition → swallowed, WorkflowInstance update safe
 */
final class WorkflowAutoProgressionBridgeTest extends TestCase
{
    private const ENTITY_CLASS = 'App\Entity\Document';
    private const BRIDGE_WORKFLOW = 'document_lifecycle';
    private const BRIDGE_TRANSITION = 'approve';

    #[Test]
    public function skipsNonWorkflowInstanceEntities(): void
    {
        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::never())->method('transition');

        $bridge = $this->makeBridge($lifecycleService);
        $bridge->postUpdate($this->makeEvent(new \stdClass()));
    }

    #[Test]
    public function skipsPendingWorkflowInstance(): void
    {
        $instance = $this->makeInstance('pending', self::ENTITY_CLASS, 42);

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::never())->method('transition');

        $bridge = $this->makeBridge($lifecycleService);
        $bridge->postUpdate($this->makeEvent($instance));
    }

    #[Test]
    public function skipsApprovedInstanceWithUnmappedEntityClass(): void
    {
        $instance = $this->makeInstance('approved', 'App\Entity\UnmappedEntity', 1);

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::never())->method('transition');

        $bridge = $this->makeBridge($lifecycleService);
        $bridge->postUpdate($this->makeEvent($instance));
    }

    #[Test]
    public function firesTransitionWhenApprovedAndEntityClassMapped(): void
    {
        $instance = $this->makeInstance('approved', self::ENTITY_CLASS, 7);
        $parentEntity = new \stdClass();

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::once())
            ->method('transition')
            ->with(
                $parentEntity,
                self::BRIDGE_WORKFLOW,
                self::BRIDGE_TRANSITION,
                null,
                self::stringContains('WorkflowInstance'),
            );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->with(self::ENTITY_CLASS, 7)->willReturn($parentEntity);

        $bridge = $this->makeBridge($lifecycleService);
        $bridge->postUpdate(new PostUpdateEventArgs($instance, $em));
    }

    #[Test]
    public function skipsWhenParentEntityNotFound(): void
    {
        $instance = $this->makeInstance('approved', self::ENTITY_CLASS, 99);

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::never())->method('transition');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $bridge = $this->makeBridge($lifecycleService);
        $bridge->postUpdate(new PostUpdateEventArgs($instance, $em));
    }

    #[Test]
    public function swallowsExceptionFromLifecycleService(): void
    {
        $instance = $this->makeInstance('approved', self::ENTITY_CLASS, 1);
        $parentEntity = new \stdClass();

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->method('transition')->willThrowException(new \RuntimeException('Lifecycle error'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($parentEntity);

        $bridge = $this->makeBridge($lifecycleService);
        $bridge->postUpdate(new PostUpdateEventArgs($instance, $em));
        self::assertTrue(true); // no exception propagated
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeInstance(string $status, string $entityType, int $entityId): WorkflowInstance
    {
        $instance = new WorkflowInstance();
        $instance->setStatus($status);
        $instance->setEntityType($entityType);
        $instance->setEntityId($entityId);
        return $instance;
    }

    private function makeBridge(LifecycleTransitionInterface $lifecycleService): WorkflowAutoProgressionBridge
    {
        return new WorkflowAutoProgressionBridge(
            lifecycleService: $lifecycleService,
            bridges: [
                self::ENTITY_CLASS => [
                    'workflow' => self::BRIDGE_WORKFLOW,
                    'transition' => self::BRIDGE_TRANSITION,
                ],
            ],
        );
    }

    private function makeEvent(object $entity): PostUpdateEventArgs
    {
        $em = $this->createMock(EntityManagerInterface::class);
        return new PostUpdateEventArgs($entity, $em);
    }
}
