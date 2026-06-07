<?php

declare(strict_types=1);

namespace App\Tests\Service\Planning;

use App\Entity\ActionItem;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\Planning\ActionItemRecurrenceService;
use App\Service\Planning\ActionItemStatusService;
use App\Service\Planning\InvalidActionItemTransitionException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActionItemStatusServiceTest extends TestCase
{
    private function makeService(
        ?EntityManagerInterface $em = null,
        ?AuditLogger $audit = null,
        ?ActionItemRecurrenceService $rec = null,
    ): ActionItemStatusService {
        $em ??= $this->createStub(EntityManagerInterface::class);
        return new ActionItemStatusService(
            $em,
            $audit ?? $this->createStub(AuditLogger::class),
            // Recurrence service is final → use a real instance over the same EM stub/mock.
            $rec ?? new ActionItemRecurrenceService($em),
        );
    }

    private function makeUser(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getUserIdentifier')->willReturn('tester@example.com');
        return $user;
    }

    #[Test]
    public function allowedTargetsFollowTheMatrix(): void
    {
        $service = $this->makeService();

        $open = (new ActionItem())->setStatus(ActionItem::STATUS_OPEN);
        $this->assertSame(
            [ActionItem::STATUS_PLANNED, ActionItem::STATUS_IN_PROGRESS, ActionItem::STATUS_DISMISSED],
            $service->allowedTargets($open),
        );

        $done = (new ActionItem())->setStatus(ActionItem::STATUS_DONE);
        $this->assertSame([], $service->allowedTargets($done));
    }

    #[Test]
    public function canTransitionRejectsIllegalJump(): void
    {
        $service = $this->makeService();
        $open = (new ActionItem())->setStatus(ActionItem::STATUS_OPEN);

        $this->assertTrue($service->canTransition($open, ActionItem::STATUS_IN_PROGRESS));
        $this->assertFalse($service->canTransition($open, ActionItem::STATUS_DONE));
    }

    #[Test]
    public function transitionChangesStatusFlushesAndAudits(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->once())->method('log')
            ->with('action_item.transition', 'ActionItem');

        $service = $this->makeService($em, $audit);
        $item = (new ActionItem())->setStatus(ActionItem::STATUS_OPEN);

        $result = $service->transition($item, ActionItem::STATUS_IN_PROGRESS, $this->makeUser(), 'started');

        $this->assertNull($result);
        $this->assertSame(ActionItem::STATUS_IN_PROGRESS, $item->getStatus());
        $this->assertNotNull($item->getUpdatedAt());
    }

    #[Test]
    public function illegalTransitionThrowsAndDoesNotFlush(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $service = $this->makeService($em);
        $item = (new ActionItem())->setStatus(ActionItem::STATUS_OPEN);

        $this->expectException(InvalidActionItemTransitionException::class);
        $service->transition($item, ActionItem::STATUS_DONE, $this->makeUser());
    }

    #[Test]
    public function sameStatusIsNoOp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $service = $this->makeService($em);
        $item = (new ActionItem())->setStatus(ActionItem::STATUS_OPEN);

        $this->assertNull($service->transition($item, ActionItem::STATUS_OPEN, $this->makeUser()));
    }

    #[Test]
    public function transitionToDoneStampsCompletedAtAndTriggersRecurrence(): void
    {
        // Recurring item → completing it materialises a follow-up (real recurrence service).
        $item = (new ActionItem())
            ->setStatus(ActionItem::STATUS_IN_PROGRESS)
            ->setTitle('Recurring review')
            ->setRecurrenceMonths(12)
            ->setDueDate(new \DateTimeImmutable('2026-01-01'));

        $result = $this->makeService()->transition($item, ActionItem::STATUS_DONE, $this->makeUser());

        $this->assertInstanceOf(ActionItem::class, $result);
        $this->assertSame(ActionItem::STATUS_DONE, $item->getStatus());
        $this->assertNotNull($item->getCompletedAt());
        $this->assertSame($result, $item->getNextActionItem());
    }

    #[Test]
    public function transitionToDoneWithoutRecurrenceReturnsNull(): void
    {
        $item = (new ActionItem())
            ->setStatus(ActionItem::STATUS_IN_PROGRESS)
            ->setRecurrenceMonths(null);

        $this->assertNull($this->makeService()->transition($item, ActionItem::STATUS_DONE, $this->makeUser()));
        $this->assertSame(ActionItem::STATUS_DONE, $item->getStatus());
    }
}
