<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\Training;
use App\Enum\TrainingStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrainingStatusTest extends TestCase
{
    #[Test]
    public function allFiveStagesAreCovered(): void
    {
        self::assertSame('planned', TrainingStatus::Planned->value);
        self::assertSame('scheduled', TrainingStatus::Scheduled->value);
        self::assertSame('in_progress', TrainingStatus::InProgress->value);
        self::assertSame('completed', TrainingStatus::Completed->value);
        self::assertSame('cancelled', TrainingStatus::Cancelled->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('training.status.planned', TrainingStatus::Planned->label());
        self::assertSame('training.status.completed', TrainingStatus::Completed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', TrainingStatus::Planned->pillVariant());
        self::assertSame('info', TrainingStatus::Scheduled->pillVariant());
        self::assertSame('warning', TrainingStatus::InProgress->pillVariant());
        self::assertSame('success', TrainingStatus::Completed->pillVariant());
        self::assertSame('neutral', TrainingStatus::Cancelled->pillVariant());
    }

    #[Test]
    public function trainingSetStatusAcceptsEnumAndString(): void
    {
        $training = new Training();

        $training->setStatus(TrainingStatus::Completed);
        self::assertSame('completed', $training->getStatus());
        self::assertSame(TrainingStatus::Completed, $training->getStatusEnum());

        $training->setStatus('scheduled');
        self::assertSame('scheduled', $training->getStatus());
        self::assertSame(TrainingStatus::Scheduled, $training->getStatusEnum());
    }
}
