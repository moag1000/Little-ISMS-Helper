<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\DataBreach;
use App\Enum\DataBreachStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataBreachStatusTest extends TestCase
{
    #[Test]
    public function allFiveStagesAreCovered(): void
    {
        self::assertSame('draft', DataBreachStatus::Draft->value);
        self::assertSame('under_assessment', DataBreachStatus::UnderAssessment->value);
        self::assertSame('authority_notified', DataBreachStatus::AuthorityNotified->value);
        self::assertSame('subjects_notified', DataBreachStatus::SubjectsNotified->value);
        self::assertSame('closed', DataBreachStatus::Closed->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('data_breach.status.draft', DataBreachStatus::Draft->label());
        self::assertSame('data_breach.status.closed', DataBreachStatus::Closed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', DataBreachStatus::Draft->pillVariant());
        self::assertSame('info', DataBreachStatus::UnderAssessment->pillVariant());
        self::assertSame('warning', DataBreachStatus::AuthorityNotified->pillVariant());
        self::assertSame('warning', DataBreachStatus::SubjectsNotified->pillVariant());
        self::assertSame('success', DataBreachStatus::Closed->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $db = new DataBreach();

        $db->setStatus(DataBreachStatus::UnderAssessment);
        self::assertSame('under_assessment', $db->getStatus());
        self::assertSame(DataBreachStatus::UnderAssessment, $db->getStatusEnum());

        $db->setStatus('closed');
        self::assertSame('closed', $db->getStatus());
        self::assertSame(DataBreachStatus::Closed, $db->getStatusEnum());
    }
}
