<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\EvidenceReverificationTask;
use App\Enum\EvidenceReverificationTaskStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EvidenceReverificationTaskStatusTest extends TestCase
{
    #[Test]
    public function allFourStagesAreCovered(): void
    {
        self::assertSame('pending', EvidenceReverificationTaskStatus::Pending->value);
        self::assertSame('in_progress', EvidenceReverificationTaskStatus::InProgress->value);
        self::assertSame('completed', EvidenceReverificationTaskStatus::Completed->value);
        self::assertSame('skipped', EvidenceReverificationTaskStatus::Skipped->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('evidence_reverification_task.status.pending', EvidenceReverificationTaskStatus::Pending->label());
        self::assertSame('evidence_reverification_task.status.completed', EvidenceReverificationTaskStatus::Completed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('warning', EvidenceReverificationTaskStatus::Pending->pillVariant());
        self::assertSame('info', EvidenceReverificationTaskStatus::InProgress->pillVariant());
        self::assertSame('success', EvidenceReverificationTaskStatus::Completed->pillVariant());
        self::assertSame('neutral', EvidenceReverificationTaskStatus::Skipped->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $task = new EvidenceReverificationTask();

        $task->setStatus(EvidenceReverificationTaskStatus::InProgress);
        self::assertSame('in_progress', $task->getStatus());
        self::assertSame(EvidenceReverificationTaskStatus::InProgress, $task->getStatusEnum());

        $task->setStatus('completed');
        self::assertSame('completed', $task->getStatus());
        self::assertSame(EvidenceReverificationTaskStatus::Completed, $task->getStatusEnum());
    }
}
