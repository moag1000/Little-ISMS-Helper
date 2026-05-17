<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\BCExercise;
use App\Enum\BCExerciseStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BCExerciseStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('planned', BCExerciseStatus::Planned->value);
        self::assertSame('in_progress', BCExerciseStatus::InProgress->value);
        self::assertSame('completed', BCExerciseStatus::Completed->value);
        self::assertSame('cancelled', BCExerciseStatus::Cancelled->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('bc_exercises.status.planned', BCExerciseStatus::Planned->label());
        self::assertSame('bc_exercises.status.completed', BCExerciseStatus::Completed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('info', BCExerciseStatus::Planned->pillVariant());
        self::assertSame('warning', BCExerciseStatus::InProgress->pillVariant());
        self::assertSame('success', BCExerciseStatus::Completed->pillVariant());
        self::assertSame('neutral', BCExerciseStatus::Cancelled->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new BCExercise();

        $entity->setStatus(BCExerciseStatus::Completed);
        self::assertSame('completed', $entity->getStatus());
        self::assertSame(BCExerciseStatus::Completed, $entity->getStatusEnum());

        $entity->setStatus('cancelled');
        self::assertSame('cancelled', $entity->getStatus());
        self::assertSame(BCExerciseStatus::Cancelled, $entity->getStatusEnum());
    }
}
