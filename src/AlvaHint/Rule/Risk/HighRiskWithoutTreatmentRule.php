<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Risk;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Risk;
use App\Entity\User;
use App\Repository\RiskTreatmentPlanRepository;

/**
 * Tier-3 hint: ISO 27001 Cl. 6.1.3 — high / critical inherent risks
 * need a documented treatment plan. Hint fires when the risk score
 * (probability * impact) is ≥ 15 and no treatment plan points back.
 */
final class HighRiskWithoutTreatmentRule extends AbstractAlvaHintRule
{
    private const int HIGH_THRESHOLD = 15;

    public function __construct(
        private readonly RiskTreatmentPlanRepository $treatmentRepository,
    ) {
    }

    public function key(): string
    {
        return 'risk.high_without_treatment';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['risks'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Risk) {
            return false;
        }
        if ($entity->getInherentRiskLevel() < self::HIGH_THRESHOLD) {
            return false;
        }

        $plans = $this->treatmentRepository->findByRisk($entity);

        return $plans === [];
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Risk);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'risk.no_treatment.title',
            bodyTranslationKey: 'risk.no_treatment.body',
            bodyTranslationParams: [
                '%level%' => (string) $entity->getInherentRiskLevel(),
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Risk',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'risk.no_treatment.action',
            actionRoute: 'app_risk_treatment_plan_new',
            actionRouteParams: [],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
