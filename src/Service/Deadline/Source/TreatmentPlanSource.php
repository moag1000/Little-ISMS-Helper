<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\RiskTreatmentPlan;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Behandlungsplan: getTargetCompletionDate() of RiskTreatmentPlan. */
final class TreatmentPlanSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return RiskTreatmentPlan::class;
    }

    public function slug(): string
    {
        return 'risk_treatment_plan';
    }

    public function label(): string
    {
        return 'Behandlungsplan';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof RiskTreatmentPlan);

        return $item->getTargetCompletionDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof RiskTreatmentPlan);

        return (string) ($item->getTitle() ?? 'Treatment plan #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof RiskTreatmentPlan);

        return in_array($item->getStatus(), ['completed', 'cancelled'], true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof RiskTreatmentPlan);

        return Responsibility::holders($item->getResponsiblePersonUser(), $item->getResponsiblePerson())
            ->orRoles('ROLE_RISK_MANAGER');
    }

    public function linkOf(object $item): ?RecordLink
    {
        assert($item instanceof RiskTreatmentPlan);

        return new RecordLink('app_risk_treatment_plan_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'treatment_plans_due', leadDays: 14, normReference: 'ISO 27001 6.1.3 / 8.3');
    }
}
