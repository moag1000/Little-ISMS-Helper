<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Lifecycle\InvalidTransitionException;
use App\Lifecycle\LifecycleRegistry;
use App\Lifecycle\LifecycleService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\TransitionBlockerList;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Tests for LifecycleService — Symfony Workflow facade (audit-s3 P-4).
 *
 * Covers:
 *  - Delegation to Workflow::apply + EM::flush
 *  - NotEnabledTransitionException wrapped into InvalidTransitionException with allowed-list
 *  - LogicException when entity lacks getStatus/setStatus
 *  - No direct AuditLogger calls (handled by listener in Task 11)
 */
final class LifecycleServiceTest extends TestCase
{
    #[Test]
    public function testTransitionDelegatesToWorkflowApply(): void
    {
        $entity = new class {
            public string $status = 'draft';

            public function getStatus(): string
            {
                return $this->status;
            }

            public function setStatus(string $s): void
            {
                $this->status = $s;
            }
        };

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects($this->once())
            ->method('apply')
            ->with($entity, 'submit_for_review', $this->callback(fn ($ctx) => ($ctx['reason'] ?? null) === 'test'));

        $registry = $this->createStub(Registry::class);
        $registry->method('get')->willReturn($workflow);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $audit = $this->createStub(AuditLogger::class);

        $service = new LifecycleService($registry, $em, $audit);
        $service->transition($entity, 'document_lifecycle', 'submit_for_review', null, 'test');
    }

    #[Test]
    public function testThrowsInvalidTransitionExceptionOnNotEnabled(): void
    {
        $entity = new class {
            public string $status = 'draft';

            public function getStatus(): string
            {
                return $this->status;
            }

            public function setStatus(string $s): void
            {
                $this->status = $s;
            }
        };

        $allowedTransition = new Transition('submit_for_review', 'draft', 'in_review');

        $workflow = $this->createStub(WorkflowInterface::class);
        $workflow->method('apply')
            ->willThrowException(new NotEnabledTransitionException($entity, 'publish', $workflow, new TransitionBlockerList(), []));
        $workflow->method('getEnabledTransitions')
            ->willReturn([$allowedTransition]);

        $registry = $this->createStub(Registry::class);
        $registry->method('get')->willReturn($workflow);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $audit = $this->createStub(AuditLogger::class);

        $service = new LifecycleService($registry, $em, $audit);

        try {
            $service->transition($entity, 'document_lifecycle', 'publish');
            $this->fail('Expected InvalidTransitionException was not thrown.');
        } catch (InvalidTransitionException $ex) {
            self::assertSame($entity::class, $ex->entityClass);
            self::assertSame('draft', $ex->fromStatus);
            self::assertSame(['submit_for_review'], $ex->allowedTransitions);
            self::assertStringContainsString('publish', $ex->getMessage());
            self::assertStringContainsString('submit_for_review', $ex->getMessage());
            self::assertInstanceOf(NotEnabledTransitionException::class, $ex->getPrevious());
        }
    }

    #[Test]
    public function testThrowsLogicExceptionWhenEntityLacksGetStatus(): void
    {
        $entity = new \stdClass();

        $registry = $this->createStub(Registry::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $audit = $this->createStub(AuditLogger::class);

        $service = new LifecycleService($registry, $em, $audit);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('lacks getStatus()/setStatus()');

        $service->transition($entity, 'document_lifecycle', 'submit_for_review');
    }

    #[Test]
    public function testDoesNotCallAuditLoggerDirectly(): void
    {
        $entity = new class {
            public string $status = 'draft';

            public function getStatus(): string
            {
                return $this->status;
            }

            public function setStatus(string $s): void
            {
                $this->status = $s;
            }
        };

        $workflow = $this->createStub(WorkflowInterface::class);
        $workflow->method('apply'); // no-op

        $registry = $this->createStub(Registry::class);
        $registry->method('get')->willReturn($workflow);

        $em = $this->createStub(EntityManagerInterface::class);

        // AuditLogger must NOT be invoked — audit is wired via event listener (Task 11)
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->never())->method('logCustom');
        $audit->expects($this->never())->method('logBulk');

        $service = new LifecycleService($registry, $em, $audit);
        $service->transition($entity, 'document_lifecycle', 'submit_for_review', null, 'automated test');
    }
}
