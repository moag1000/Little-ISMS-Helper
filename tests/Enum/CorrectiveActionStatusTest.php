<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\CorrectiveAction;
use App\Enum\CorrectiveActionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CorrectiveActionStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('planned', CorrectiveActionStatus::Planned->value);
        self::assertSame('in_progress', CorrectiveActionStatus::InProgress->value);
        self::assertSame('completed', CorrectiveActionStatus::Completed->value);
        self::assertSame('verified_effective', CorrectiveActionStatus::VerifiedEffective->value);
        self::assertSame('verified_ineffective', CorrectiveActionStatus::VerifiedIneffective->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('corrective_action.status.planned', CorrectiveActionStatus::Planned->label());
        self::assertSame('corrective_action.status.verified_effective', CorrectiveActionStatus::VerifiedEffective->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', CorrectiveActionStatus::Planned->pillVariant());
        self::assertSame('info', CorrectiveActionStatus::InProgress->pillVariant());
        self::assertSame('warning', CorrectiveActionStatus::Completed->pillVariant());
        self::assertSame('success', CorrectiveActionStatus::VerifiedEffective->pillVariant());
        self::assertSame('danger', CorrectiveActionStatus::VerifiedIneffective->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new CorrectiveAction();

        $entity->setStatus(CorrectiveActionStatus::Completed);
        self::assertSame('completed', $entity->getStatus());
        self::assertSame(CorrectiveActionStatus::Completed, $entity->getStatusEnum());

        $entity->setStatus('verified_effective');
        self::assertSame('verified_effective', $entity->getStatus());
        self::assertSame(CorrectiveActionStatus::VerifiedEffective, $entity->getStatusEnum());
    }
}
