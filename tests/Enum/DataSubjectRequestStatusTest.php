<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\DataSubjectRequest;
use App\Enum\DataSubjectRequestStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataSubjectRequestStatusTest extends TestCase
{
    #[Test]
    public function allSixStagesAreCovered(): void
    {
        self::assertSame('received', DataSubjectRequestStatus::Received->value);
        self::assertSame('identity_verification', DataSubjectRequestStatus::IdentityVerification->value);
        self::assertSame('in_progress', DataSubjectRequestStatus::InProgress->value);
        self::assertSame('completed', DataSubjectRequestStatus::Completed->value);
        self::assertSame('rejected', DataSubjectRequestStatus::Rejected->value);
        self::assertSame('extended', DataSubjectRequestStatus::Extended->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('data_subject_request.status.received', DataSubjectRequestStatus::Received->label());
        self::assertSame('data_subject_request.status.completed', DataSubjectRequestStatus::Completed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('info', DataSubjectRequestStatus::Received->pillVariant());
        self::assertSame('warning', DataSubjectRequestStatus::IdentityVerification->pillVariant());
        self::assertSame('info', DataSubjectRequestStatus::InProgress->pillVariant());
        self::assertSame('success', DataSubjectRequestStatus::Completed->pillVariant());
        self::assertSame('danger', DataSubjectRequestStatus::Rejected->pillVariant());
        self::assertSame('warning', DataSubjectRequestStatus::Extended->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $dsr = new DataSubjectRequest();

        $dsr->setStatus(DataSubjectRequestStatus::Completed);
        self::assertSame('completed', $dsr->getStatus());
        self::assertSame(DataSubjectRequestStatus::Completed, $dsr->getStatusEnum());

        $dsr->setStatus('in_progress');
        self::assertSame('in_progress', $dsr->getStatus());
        self::assertSame(DataSubjectRequestStatus::InProgress, $dsr->getStatusEnum());
    }
}
