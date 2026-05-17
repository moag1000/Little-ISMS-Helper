<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\TrainingParticipation;
use App\Enum\TrainingParticipationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrainingParticipationStatusTest extends TestCase
{
    #[Test]
    public function allFiveStagesAreCovered(): void
    {
        self::assertSame('pending', TrainingParticipationStatus::Pending->value);
        self::assertSame('in_progress', TrainingParticipationStatus::InProgress->value);
        self::assertSame('completed', TrainingParticipationStatus::Completed->value);
        self::assertSame('failed', TrainingParticipationStatus::Failed->value);
        self::assertSame('waived', TrainingParticipationStatus::Waived->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('training_participation.status.pending', TrainingParticipationStatus::Pending->label());
        self::assertSame('training_participation.status.completed', TrainingParticipationStatus::Completed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', TrainingParticipationStatus::Pending->pillVariant());
        self::assertSame('info', TrainingParticipationStatus::InProgress->pillVariant());
        self::assertSame('success', TrainingParticipationStatus::Completed->pillVariant());
        self::assertSame('danger', TrainingParticipationStatus::Failed->pillVariant());
        self::assertSame('neutral', TrainingParticipationStatus::Waived->pillVariant());
    }

    #[Test]
    public function trainingParticipationSetStatusAcceptsEnumAndString(): void
    {
        $participation = new TrainingParticipation();

        $participation->setStatus(TrainingParticipationStatus::Completed);
        self::assertSame('completed', $participation->getStatus());
        self::assertSame(TrainingParticipationStatus::Completed, $participation->getStatusEnum());

        $participation->setStatus('failed');
        self::assertSame('failed', $participation->getStatus());
        self::assertSame(TrainingParticipationStatus::Failed, $participation->getStatusEnum());
    }
}
