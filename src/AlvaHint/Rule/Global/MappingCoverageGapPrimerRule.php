<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;

/**
 * Tier-3 concept primer: explains how to READ the coverage percentage.
 *
 * On the transitive / cross-framework coverage view a junior ISO sees a
 * number like "87%" without knowing what it is a percentage OF, or what
 * the remaining 13% means. This hint clarifies: % of the framework already
 * covered by fulfilled controls; the gap is the to-do list.
 *
 * Trigger  : app_compliance_transitive (page-based)
 * Module   : compliance
 * Role     : ROLE_USER
 * Dismiss  : global.mapping_coverage_primer@v1 (cross-device)
 */
final class MappingCoverageGapPrimerRule extends AbstractGlobalAlvaHintRule
{
    public function key(): string
    {
        return 'global.mapping_coverage_primer';
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
        return ['app_compliance_transitive'];
    }

    public function evaluate(Tenant $tenant, ?User $user): AlvaHint
    {
        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.mapping_coverage_primer.title',
            bodyTranslationKey: 'global.mapping_coverage_primer.body',
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
