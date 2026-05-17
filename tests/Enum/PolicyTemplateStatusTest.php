<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\PolicyTemplate;
use App\Enum\PolicyTemplateStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolicyTemplateStatusTest extends TestCase
{
    #[Test]
    public function allFiveStagesAreCovered(): void
    {
        self::assertSame('draft', PolicyTemplateStatus::Draft->value);
        self::assertSame('in_review', PolicyTemplateStatus::InReview->value);
        self::assertSame('approved', PolicyTemplateStatus::Approved->value);
        self::assertSame('published', PolicyTemplateStatus::Published->value);
        self::assertSame('archived', PolicyTemplateStatus::Archived->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('policy_template.status.draft', PolicyTemplateStatus::Draft->label());
        self::assertSame('policy_template.status.published', PolicyTemplateStatus::Published->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', PolicyTemplateStatus::Draft->pillVariant());
        self::assertSame('info', PolicyTemplateStatus::InReview->pillVariant());
        self::assertSame('warning', PolicyTemplateStatus::Approved->pillVariant());
        self::assertSame('success', PolicyTemplateStatus::Published->pillVariant());
        self::assertSame('neutral', PolicyTemplateStatus::Archived->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $tpl = new PolicyTemplate();

        $tpl->setStatus(PolicyTemplateStatus::Approved);
        self::assertSame('approved', $tpl->getStatus());
        self::assertSame(PolicyTemplateStatus::Approved, $tpl->getStatusEnum());

        $tpl->setStatus('published');
        self::assertSame('published', $tpl->getStatus());
        self::assertSame(PolicyTemplateStatus::Published, $tpl->getStatusEnum());
        // setStatus('published') keeps isActive in sync per legacy contract.
        self::assertTrue($tpl->isActive());
    }
}
