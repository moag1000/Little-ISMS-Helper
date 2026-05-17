<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\RiskTreatmentPlan;
use App\Enum\RiskTreatmentPlanStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RiskTreatmentPlanStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('planned', RiskTreatmentPlanStatus::Planned->value);
        self::assertSame('in_progress', RiskTreatmentPlanStatus::InProgress->value);
        self::assertSame('completed', RiskTreatmentPlanStatus::Completed->value);
        self::assertSame('cancelled', RiskTreatmentPlanStatus::Cancelled->value);
        self::assertSame('on_hold', RiskTreatmentPlanStatus::OnHold->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('risk_treatment_plan.status.planned', RiskTreatmentPlanStatus::Planned->label());
        self::assertSame('risk_treatment_plan.status.on_hold', RiskTreatmentPlanStatus::OnHold->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('info', RiskTreatmentPlanStatus::Planned->pillVariant());
        self::assertSame('warning', RiskTreatmentPlanStatus::InProgress->pillVariant());
        self::assertSame('success', RiskTreatmentPlanStatus::Completed->pillVariant());
        self::assertSame('neutral', RiskTreatmentPlanStatus::Cancelled->pillVariant());
        self::assertSame('neutral', RiskTreatmentPlanStatus::OnHold->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new RiskTreatmentPlan();

        $entity->setStatus(RiskTreatmentPlanStatus::InProgress);
        self::assertSame('in_progress', $entity->getStatus());
        self::assertSame(RiskTreatmentPlanStatus::InProgress, $entity->getStatusEnum());

        $entity->setStatus('completed');
        self::assertSame('completed', $entity->getStatus());
        self::assertSame(RiskTreatmentPlanStatus::Completed, $entity->getStatusEnum());
    }
}
