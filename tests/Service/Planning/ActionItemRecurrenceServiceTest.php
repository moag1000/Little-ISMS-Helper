<?php

declare(strict_types=1);

namespace App\Tests\Service\Planning;

use App\Entity\ActionItem;
use App\Entity\ActionItemReference;
use App\Service\Planning\ActionItemRecurrenceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActionItemRecurrenceServiceTest extends TestCase
{
    #[Test]
    public function oneOffItemHasNoFollowUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $service = new ActionItemRecurrenceService($em);
        $item = (new ActionItem())->setRecurrenceMonths(null);

        $this->assertNull($service->materialiseFollowUp($item));
    }

    #[Test]
    public function followUpDueDateIsAnchoredOnCompletion(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');

        $service = new ActionItemRecurrenceService($em);

        $item = new ActionItem();
        $item->setTitle('Doc review')
            ->setOrigin(ActionItem::ORIGIN_INTERNAL)
            ->setRecurrenceMonths(12)
            ->setPlannedEffortPt('1.5')
            ->setScopes(['HQ'])
            ->setDueDate(new \DateTimeImmutable('2026-01-01'))
            ->setCompletedAt(new \DateTimeImmutable('2026-03-15'));

        $ref = (new ActionItemReference())->setRefType('document_review')->setRefId(42);
        $item->addReference($ref);

        $follow = $service->materialiseFollowUp($item);

        $this->assertInstanceOf(ActionItem::class, $follow);
        // Anchor = completedAt (2026-03-15) + 12 months, NOT old dueDate (2026-01-01).
        $this->assertSame('2027-03-15', $follow->getDueDate()?->format('Y-m-d'));
        $this->assertSame(ActionItem::STATUS_OPEN, $follow->getStatus());
        $this->assertSame('Doc review', $follow->getTitle());
        $this->assertSame('1.5', $follow->getPlannedEffortPt());
        $this->assertSame(['HQ'], $follow->getScopes());
        $this->assertSame($follow, $item->getNextActionItem());

        // References copied (association, not shared instance).
        $this->assertCount(1, $follow->getReferences());
        $copied = $follow->getReferences()->first();
        $this->assertNotSame($ref, $copied);
        $this->assertSame('document_review', $copied->getRefType());
        $this->assertSame(42, $copied->getRefId());
    }
}
