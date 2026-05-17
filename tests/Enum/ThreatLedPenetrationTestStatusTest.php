<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\ThreatLedPenetrationTest;
use App\Enum\ThreatLedPenetrationTestStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThreatLedPenetrationTestStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('planned', ThreatLedPenetrationTestStatus::Planned->value);
        self::assertSame('scoping', ThreatLedPenetrationTestStatus::Scoping->value);
        self::assertSame('red_team', ThreatLedPenetrationTestStatus::RedTeam->value);
        self::assertSame('reporting', ThreatLedPenetrationTestStatus::Reporting->value);
        self::assertSame('closed', ThreatLedPenetrationTestStatus::Closed->value);
        self::assertSame('cancelled', ThreatLedPenetrationTestStatus::Cancelled->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('tlpt.status.planned', ThreatLedPenetrationTestStatus::Planned->label());
        self::assertSame('tlpt.status.red_team', ThreatLedPenetrationTestStatus::RedTeam->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('info', ThreatLedPenetrationTestStatus::Planned->pillVariant());
        self::assertSame('info', ThreatLedPenetrationTestStatus::Scoping->pillVariant());
        self::assertSame('warning', ThreatLedPenetrationTestStatus::RedTeam->pillVariant());
        self::assertSame('warning', ThreatLedPenetrationTestStatus::Reporting->pillVariant());
        self::assertSame('success', ThreatLedPenetrationTestStatus::Closed->pillVariant());
        self::assertSame('neutral', ThreatLedPenetrationTestStatus::Cancelled->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new ThreatLedPenetrationTest();

        $entity->setStatus(ThreatLedPenetrationTestStatus::RedTeam);
        self::assertSame('red_team', $entity->getStatus());
        self::assertSame(ThreatLedPenetrationTestStatus::RedTeam, $entity->getStatusEnum());

        $entity->setStatus('closed');
        self::assertSame('closed', $entity->getStatus());
        self::assertSame(ThreatLedPenetrationTestStatus::Closed, $entity->getStatusEnum());
    }
}
