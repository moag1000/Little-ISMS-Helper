<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\ProcessingActivity;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\ProcessingActivity;
use App\Entity\User;

/**
 * S18 B3 hint: ProcessingActivity has legacy freetext `responsibleDepartment`
 * set but no structured FK to the Department master data.
 *
 * Suggests promoting the freetext value to a Department row so VVT entries
 * become audit-ready (ISO 27001 Cl. 5.3 — Roles, responsibilities, authorities).
 *
 * Module-gated: `privacy` (relates to Art. 30 VVT entries).
 */
final class UnstructuredDepartmentRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'processing_activity.unstructured_department';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['privacy'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof ProcessingActivity) {
            return false;
        }

        $freetext = trim((string) $entity->getResponsibleDepartment());
        $hasFreetext = $freetext !== '';
        $hasEntity = $entity->getResponsibleDepartmentEntity() !== null;

        return $hasFreetext && !$hasEntity;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof ProcessingActivity);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'processing_activity.unstructured_department.title',
            bodyTranslationKey: 'processing_activity.unstructured_department.body',
            bodyTranslationParams: [
                '%department%' => (string) $entity->getResponsibleDepartment(),
            ],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'ProcessingActivity',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'processing_activity.unstructured_department.action',
            actionRoute: 'admin_department_new',
            actionMethod: 'GET',
            mood: 'thinking',
            version: 1,
        );
    }
}
