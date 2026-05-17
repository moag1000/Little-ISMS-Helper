<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\ThreatIntelligence;
use App\Enum\ThreatIntelligenceStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThreatIntelligenceStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('new', ThreatIntelligenceStatus::New->value);
        self::assertSame('analyzing', ThreatIntelligenceStatus::Analyzing->value);
        self::assertSame('mitigated', ThreatIntelligenceStatus::Mitigated->value);
        self::assertSame('monitoring', ThreatIntelligenceStatus::Monitoring->value);
        self::assertSame('closed', ThreatIntelligenceStatus::Closed->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('status_type.new', ThreatIntelligenceStatus::New->label());
        self::assertSame('status_type.mitigated', ThreatIntelligenceStatus::Mitigated->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('danger', ThreatIntelligenceStatus::New->pillVariant());
        self::assertSame('info', ThreatIntelligenceStatus::Analyzing->pillVariant());
        self::assertSame('success', ThreatIntelligenceStatus::Mitigated->pillVariant());
        self::assertSame('warning', ThreatIntelligenceStatus::Monitoring->pillVariant());
        self::assertSame('neutral', ThreatIntelligenceStatus::Closed->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new ThreatIntelligence();

        $entity->setStatus(ThreatIntelligenceStatus::Analyzing);
        self::assertSame('analyzing', $entity->getStatus());
        self::assertSame(ThreatIntelligenceStatus::Analyzing, $entity->getStatusEnum());

        $entity->setStatus('closed');
        self::assertSame('closed', $entity->getStatus());
        self::assertSame(ThreatIntelligenceStatus::Closed, $entity->getStatusEnum());
    }
}
