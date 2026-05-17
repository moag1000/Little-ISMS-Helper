<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\InternalAudit;
use App\Enum\InternalAuditStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InternalAuditStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('planned', InternalAuditStatus::Planned->value);
        self::assertSame('conducted', InternalAuditStatus::Conducted->value);
        self::assertSame('reported', InternalAuditStatus::Reported->value);
        self::assertSame('approved', InternalAuditStatus::Approved->value);
        self::assertSame('rejected', InternalAuditStatus::Rejected->value);
        self::assertSame('closed', InternalAuditStatus::Closed->value);
        self::assertSame('cancelled', InternalAuditStatus::Cancelled->value);
        self::assertSame('in_progress', InternalAuditStatus::InProgress->value);
        self::assertSame('completed', InternalAuditStatus::Completed->value);
        self::assertSame('postponed', InternalAuditStatus::Postponed->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('audits.status.planned', InternalAuditStatus::Planned->label());
        self::assertSame('audits.status.closed', InternalAuditStatus::Closed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('info', InternalAuditStatus::Planned->pillVariant());
        self::assertSame('success', InternalAuditStatus::Approved->pillVariant());
        self::assertSame('danger', InternalAuditStatus::Rejected->pillVariant());
        self::assertSame('neutral', InternalAuditStatus::Closed->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new InternalAudit();

        $entity->setStatus(InternalAuditStatus::Approved);
        self::assertSame('approved', $entity->getStatus());
        self::assertSame(InternalAuditStatus::Approved, $entity->getStatusEnum());

        $entity->setStatus('closed');
        self::assertSame('closed', $entity->getStatus());
        self::assertSame(InternalAuditStatus::Closed, $entity->getStatusEnum());
    }
}
