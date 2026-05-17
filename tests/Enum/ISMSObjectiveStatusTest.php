<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\ISMSObjective;
use App\Enum\ISMSObjectiveStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ISMSObjectiveStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('not_started', ISMSObjectiveStatus::NotStarted->value);
        self::assertSame('in_progress', ISMSObjectiveStatus::InProgress->value);
        self::assertSame('achieved', ISMSObjectiveStatus::Achieved->value);
        self::assertSame('delayed', ISMSObjectiveStatus::Delayed->value);
        self::assertSame('cancelled', ISMSObjectiveStatus::Cancelled->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('objective.status.not_started', ISMSObjectiveStatus::NotStarted->label());
        self::assertSame('objective.status.achieved', ISMSObjectiveStatus::Achieved->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', ISMSObjectiveStatus::NotStarted->pillVariant());
        self::assertSame('info', ISMSObjectiveStatus::InProgress->pillVariant());
        self::assertSame('success', ISMSObjectiveStatus::Achieved->pillVariant());
        self::assertSame('warning', ISMSObjectiveStatus::Delayed->pillVariant());
        self::assertSame('neutral', ISMSObjectiveStatus::Cancelled->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new ISMSObjective();

        $entity->setStatus(ISMSObjectiveStatus::Achieved);
        self::assertSame('achieved', $entity->getStatus());
        self::assertSame(ISMSObjectiveStatus::Achieved, $entity->getStatusEnum());

        $entity->setStatus('delayed');
        self::assertSame('delayed', $entity->getStatus());
        self::assertSame(ISMSObjectiveStatus::Delayed, $entity->getStatusEnum());
    }
}
