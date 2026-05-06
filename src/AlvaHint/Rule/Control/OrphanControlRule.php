<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Control;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Control;
use App\Entity\User;
use App\Repository\ComplianceRequirementRepository;

/**
 * Tier-3 hint: ISO 27001 Cl. 6.1.3 b/c — applicable controls without
 * any linked risk or compliance requirement are evidence-orphans:
 * they are claimed as applicable but the SoA cannot show what they
 * actually mitigate or which framework demands them.
 */
final class OrphanControlRule extends AbstractAlvaHintRule
{
    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    public function key(): string
    {
        return 'control.orphan';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['controls'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Control) {
            return false;
        }
        if ($entity->isApplicable() !== true) {
            return false;
        }

        $risks = $entity->getRisks();
        $hasRisk = $risks !== null && $risks->count() > 0;
        if ($hasRisk) {
            return false;
        }

        $requirements = $this->requirementRepository->findByControl($entity->getId() ?? 0);

        return $requirements === [];
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Control);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'control.orphan.title',
            bodyTranslationKey: 'control.orphan.body',
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Control',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'control.orphan.action',
            actionRoute: 'app_soa_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
