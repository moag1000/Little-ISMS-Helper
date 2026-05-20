<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Risk;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Risk;
use App\Entity\User;
use App\Repository\RiskTreatmentPlanRepository;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Tier-2 hint: ISO 27001 Cl. 6.1.3 — high-inherent-risk records
 * identified more than THRESHOLD_DAYS ago without any treatment plan
 * are an audit gap: the risk has been known but left untreated.
 */
final class TreatmentPlanOverdueRule extends AbstractAlvaHintRule
{
    private const int HIGH_THRESHOLD = 12;
    private const int OVERDUE_DAYS = 30;

    public function __construct(
        private readonly RiskTreatmentPlanRepository $treatmentRepository,
    ) {
    }

    public function key(): string
    {
        return 'risk.treatment_plan_overdue';
    }

    public function priorityTier(): int
    {
        return 2;
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
        if ($plans !== []) {
            return false;
        }

        $createdAt = $entity->getCreatedAt();
        if (!$createdAt instanceof DateTimeInterface) {
            return false;
        }

        $threshold = new DateTimeImmutable(sprintf('-%d days', self::OVERDUE_DAYS));

        return $createdAt < $threshold;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Risk);

        $createdAt = $entity->getCreatedAt();
        $days = 0;
        if ($createdAt instanceof DateTimeInterface) {
            $days = (int) (new DateTimeImmutable())->diff(
                DateTimeImmutable::createFromInterface($createdAt),
            )->days;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'risk.treatment_overdue.title',
            bodyTranslationKey: 'risk.treatment_overdue.body',
            bodyTranslationParams: [
                '%days%' => $days,
                '%level%' => (string) $entity->getInherentRiskLevel(),
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Risk',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'risk.treatment_overdue.action',
            actionRoute: 'app_risk_treatment_plan_new',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'warning',
        );
    }
}
