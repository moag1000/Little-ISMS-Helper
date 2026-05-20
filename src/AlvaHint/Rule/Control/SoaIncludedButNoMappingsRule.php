<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Control;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Control;
use App\Entity\User;
use App\Repository\ComplianceRequirementRepository;

/**
 * Tier-2 hint: ISO 27001 Cl. 6.1.3 c / 8.3 — applicable controls in
 * the SoA without any compliance-requirement mappings cannot
 * demonstrate that they cover a specific clause or framework control.
 * This is the mappings gap variant: the control itself is linked to
 * risks (OrphanControlRule would fire otherwise) but has zero
 * cross-framework requirement mappings.
 */
final class SoaIncludedButNoMappingsRule extends AbstractAlvaHintRule
{
    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    public function key(): string
    {
        return 'control.soa_no_mappings';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['controls', 'compliance'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Control) {
            return false;
        }
        if ($entity->isApplicable() !== true) {
            return false;
        }

        // Only fire when risks exist (otherwise OrphanControlRule covers it)
        $risks = $entity->getRisks();
        if ($risks === null || $risks->count() === 0) {
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
            titleTranslationKey: 'control.soa_no_mappings.title',
            bodyTranslationKey: 'control.soa_no_mappings.body',
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Control',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'control.soa_no_mappings.action',
            actionRoute: 'app_soa_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
