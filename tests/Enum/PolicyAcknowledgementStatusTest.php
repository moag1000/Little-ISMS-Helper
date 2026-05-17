<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\PolicyAcknowledgement;
use App\Enum\PolicyAcknowledgementStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyAcknowledgementStatusTest extends TestCase
{
    #[Test]
    public function bothStagesAreCovered(): void
    {
        self::assertSame('pending', PolicyAcknowledgementStatus::Pending->value);
        self::assertSame('acknowledged', PolicyAcknowledgementStatus::Acknowledged->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('policy_acknowledgement.status.pending', PolicyAcknowledgementStatus::Pending->label());
        self::assertSame('policy_acknowledgement.status.acknowledged', PolicyAcknowledgementStatus::Acknowledged->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('warning', PolicyAcknowledgementStatus::Pending->pillVariant());
        self::assertSame('success', PolicyAcknowledgementStatus::Acknowledged->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $ack = new PolicyAcknowledgement();

        $ack->setStatus(PolicyAcknowledgementStatus::Pending);
        self::assertSame('pending', $ack->getStatus());
        self::assertSame(PolicyAcknowledgementStatus::Pending, $ack->getStatusEnum());

        $ack->setStatus('acknowledged');
        self::assertSame('acknowledged', $ack->getStatus());
        self::assertSame(PolicyAcknowledgementStatus::Acknowledged, $ack->getStatusEnum());
    }
}
