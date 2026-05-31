<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;

/**
 * Tier-3 concept primer: disambiguates the two quality numbers + lifecycle.
 *
 * The quality dashboard shows BOTH Confidence and MQS plus a draft /
 * published lifecycle. Junior ISOs routinely conflate the two scores and
 * do not realise that draft mappings do not count towards coverage. This
 * hint explains: Confidence = content certainty, MQS = evidence/provenance
 * quality; and points out that only reviewed (published) mappings are
 * operational.
 *
 * Trigger  : app_mapping_quality_dashboard (page-based)
 * Module   : compliance
 * Role     : ROLE_USER
 * Dismiss  : global.mapping_quality_primer@v1 (cross-device)
 */
final class MappingQualityScorePrimerRule extends AbstractGlobalAlvaHintRule
{
    public function key(): string
    {
        return 'global.mapping_quality_primer';
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
        return ['app_mapping_quality_dashboard'];
    }

    public function evaluate(Tenant $tenant, ?User $user): AlvaHint
    {
        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.mapping_quality_primer.title',
            bodyTranslationKey: 'global.mapping_quality_primer.body',
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
