<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\WizardSession;
use App\Enum\WizardSessionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WizardSessionStatusTest extends TestCase
{
    #[Test]
    public function allThreeStagesAreCovered(): void
    {
        self::assertSame('in_progress', WizardSessionStatus::InProgress->value);
        self::assertSame('completed', WizardSessionStatus::Completed->value);
        self::assertSame('abandoned', WizardSessionStatus::Abandoned->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('wizard_session.status.in_progress', WizardSessionStatus::InProgress->label());
        self::assertSame('wizard_session.status.abandoned', WizardSessionStatus::Abandoned->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('info', WizardSessionStatus::InProgress->pillVariant());
        self::assertSame('success', WizardSessionStatus::Completed->pillVariant());
        self::assertSame('neutral', WizardSessionStatus::Abandoned->pillVariant());
    }

    #[Test]
    public function wizardSessionSetStatusAcceptsEnumAndString(): void
    {
        $session = new WizardSession();

        $session->setStatus(WizardSessionStatus::Completed);
        self::assertSame('completed', $session->getStatus());
        self::assertSame(WizardSessionStatus::Completed, $session->getStatusEnum());

        $session->setStatus('abandoned');
        self::assertSame('abandoned', $session->getStatus());
        self::assertSame(WizardSessionStatus::Abandoned, $session->getStatusEnum());
    }
}
