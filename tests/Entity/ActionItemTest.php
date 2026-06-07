<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ActionItem;
use App\Entity\ActionItemReference;
use App\Entity\Team;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActionItemTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $item = new ActionItem();

        $this->assertInstanceOf(\DateTimeImmutable::class, $item->getCreatedAt());
        $this->assertSame(ActionItem::STATUS_OPEN, $item->getStatus());
        $this->assertSame(ActionItem::ORIGIN_INTERNAL, $item->getOrigin());
        $this->assertSame([], $item->getScopes());
        $this->assertCount(0, $item->getTeams());
        $this->assertCount(0, $item->getReferences());
        $this->assertFalse($item->isClosed());
    }

    #[Test]
    public function closedReflectsTerminalStatuses(): void
    {
        $this->assertTrue((new ActionItem())->setStatus(ActionItem::STATUS_DONE)->isClosed());
        $this->assertTrue((new ActionItem())->setStatus(ActionItem::STATUS_DISMISSED)->isClosed());
        $this->assertFalse((new ActionItem())->setStatus(ActionItem::STATUS_PLANNED)->isClosed());
    }

    #[Test]
    public function teamsAddRemove(): void
    {
        $item = new ActionItem();
        $team = new Team();

        $item->addTeam($team);
        $item->addTeam($team);
        $this->assertCount(1, $item->getTeams());

        $item->removeTeam($team);
        $this->assertCount(0, $item->getTeams());
    }

    #[Test]
    public function referenceBackReferenceIsWired(): void
    {
        $item = new ActionItem();
        $ref = (new ActionItemReference())->setRefType('incident')->setRefId(7);

        $item->addReference($ref);
        $this->assertCount(1, $item->getReferences());
        $this->assertSame($item, $ref->getActionItem());

        $item->removeReference($ref);
        $this->assertCount(0, $item->getReferences());
        $this->assertNull($ref->getActionItem());
    }

    #[Test]
    public function scopesAreReindexed(): void
    {
        $item = new ActionItem();
        $item->setScopes([2 => 'A', 5 => 'B']);
        $this->assertSame(['A', 'B'], $item->getScopes());
    }
}
