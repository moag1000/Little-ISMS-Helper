<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\AuditFinding;
use App\Enum\AuditFindingStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditFindingStatusTest extends TestCase
{
    #[Test]
    public function allFiveStagesAreCovered(): void
    {
        self::assertSame('open', AuditFindingStatus::Open->value);
        self::assertSame('in_progress', AuditFindingStatus::InProgress->value);
        self::assertSame('resolved', AuditFindingStatus::Resolved->value);
        self::assertSame('verified', AuditFindingStatus::Verified->value);
        self::assertSame('closed', AuditFindingStatus::Closed->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('audit_finding.status.open', AuditFindingStatus::Open->label());
        self::assertSame('audit_finding.status.closed', AuditFindingStatus::Closed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('danger', AuditFindingStatus::Open->pillVariant());
        self::assertSame('warning', AuditFindingStatus::InProgress->pillVariant());
        self::assertSame('info', AuditFindingStatus::Resolved->pillVariant());
        self::assertSame('success', AuditFindingStatus::Verified->pillVariant());
        self::assertSame('neutral', AuditFindingStatus::Closed->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new AuditFinding();

        $entity->setStatus(AuditFindingStatus::Verified);
        self::assertSame('verified', $entity->getStatus());
        self::assertSame(AuditFindingStatus::Verified, $entity->getStatusEnum());

        $entity->setStatus('closed');
        self::assertSame('closed', $entity->getStatus());
        self::assertSame(AuditFindingStatus::Closed, $entity->getStatusEnum());
    }
}
