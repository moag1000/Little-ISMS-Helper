<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\ProcessingActivity;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\ProcessingActivity;
use App\Entity\User;

/**
 * Tier-1 hint: GDPR Art. 44–49 third-country transfer without
 * documented safeguards (SCC, BCR, adequacy decision).
 *
 * Active processing activities exporting personal data outside the
 * EEA must record a transfer mechanism. Missing safeguards on a
 * non-draft PA is a Schrems-II-grade finding.
 */
final class ThirdCountryWithoutSafeguardsRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'processing_activity.third_country_no_safeguards';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        return ['compliance'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof ProcessingActivity) {
            return false;
        }
        if (!$entity->getHasThirdCountryTransfer()) {
            return false;
        }
        if ($entity->getStatus() === 'draft') {
            return false;
        }

        $safeguards = trim((string) $entity->getTransferSafeguards());

        return $safeguards === '';
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof ProcessingActivity);
        $countries = $entity->getThirdCountries() ?? [];

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'processing_activity.third_country.title',
            bodyTranslationKey: 'processing_activity.third_country.body',
            bodyTranslationParams: [
                '%countries%' => $countries === [] ? '—' : implode(', ', $countries),
            ],
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'ProcessingActivity',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'processing_activity.third_country.action',
            actionRoute: 'app_processing_activity_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            mood: 'warning',
        );
    }
}
