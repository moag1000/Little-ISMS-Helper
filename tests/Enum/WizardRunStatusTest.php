<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\WizardRun;
use App\Enum\WizardRunStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WizardRunStatusTest extends TestCase
{
    #[Test]
    public function allFiveStagesAreCovered(): void
    {
        self::assertSame('in_progress', WizardRunStatus::InProgress->value);
        self::assertSame('completed', WizardRunStatus::Completed->value);
        self::assertSame('cancelled', WizardRunStatus::Cancelled->value);
        self::assertSame('failed', WizardRunStatus::Failed->value);
        self::assertSame('sandbox', WizardRunStatus::Sandbox->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('wizard_run.status.in_progress', WizardRunStatus::InProgress->label());
        self::assertSame('wizard_run.status.completed', WizardRunStatus::Completed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('info', WizardRunStatus::InProgress->pillVariant());
        self::assertSame('success', WizardRunStatus::Completed->pillVariant());
        self::assertSame('neutral', WizardRunStatus::Cancelled->pillVariant());
        self::assertSame('danger', WizardRunStatus::Failed->pillVariant());
        self::assertSame('warning', WizardRunStatus::Sandbox->pillVariant());
    }

    #[Test]
    public function wizardRunSetStatusAcceptsEnumAndString(): void
    {
        $run = new WizardRun();

        $run->setStatus(WizardRunStatus::Completed);
        self::assertSame('completed', $run->getStatus());
        self::assertSame(WizardRunStatus::Completed, $run->getStatusEnum());

        $run->setStatus('failed');
        self::assertSame('failed', $run->getStatus());
        self::assertSame(WizardRunStatus::Failed, $run->getStatusEnum());
    }
}
