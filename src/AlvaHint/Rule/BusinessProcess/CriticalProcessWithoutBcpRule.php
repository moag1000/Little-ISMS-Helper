<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\BusinessProcess;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\BusinessProcess;
use App\Entity\User;
use App\Repository\BusinessContinuityPlanRepository;

/**
 * Tier-2 hint: ISO 22301 Cl. 8.4 — every critical/high business process
 * needs at least one BC plan. Hint fires when the process classifies
 * itself as critical or high but no BCP points back.
 */
final class CriticalProcessWithoutBcpRule extends AbstractAlvaHintRule
{
    public function __construct(
        private readonly BusinessContinuityPlanRepository $bcpRepository,
    ) {
    }

    public function key(): string
    {
        return 'business_process.critical_without_bcp';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['bcm'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof BusinessProcess) {
            return false;
        }
        if (!in_array($entity->getCriticality(), ['critical', 'high'], true)) {
            return false;
        }

        $existing = $this->bcpRepository->findBy(['businessProcess' => $entity]);

        return $existing === [];
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof BusinessProcess);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'business_process.no_bcp.title',
            bodyTranslationKey: 'business_process.no_bcp.body',
            bodyTranslationParams: [
                '%criticality%' => (string) $entity->getCriticality(),
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'BusinessProcess',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'business_process.no_bcp.action',
            actionRoute: 'app_bc_plan_new',
            actionRouteParams: [],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
