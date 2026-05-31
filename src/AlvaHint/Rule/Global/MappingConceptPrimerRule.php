<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;

/**
 * Tier-3 concept primer: explains WHAT a mapping is + the data-reuse benefit.
 *
 * Targets junior ISOs with an IT / ISO-9001 background who land on the
 * mapping hub without knowing what a mapping is or why it exists. The hint
 * delivers the one-sentence mental model (bridge between frameworks →
 * fulfil once, counts many times via inheritance) and offers the full
 * guided mapping tour for the deeper walkthrough.
 *
 * Trigger  : app_compliance_mapping_hub (page-based, no data threshold)
 * Module   : compliance
 * Role     : ROLE_USER (default — everyone who can reach the hub)
 * Dismiss  : global.mapping_concept_primer@v1 (cross-device)
 */
final class MappingConceptPrimerRule extends AbstractGlobalAlvaHintRule
{
    public function key(): string
    {
        return 'global.mapping_concept_primer';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['compliance'];
    }

    public function appliesToPages(): array
    {
        return ['app_compliance_mapping_hub'];
    }

    public function evaluate(Tenant $tenant, ?User $user): AlvaHint
    {
        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.mapping_concept_primer.title',
            bodyTranslationKey: 'global.mapping_concept_primer.body',
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            mood: 'thinking',
            version: 1,
        );
    }
}
