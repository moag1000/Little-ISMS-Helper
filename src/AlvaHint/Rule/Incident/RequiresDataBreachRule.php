<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Incident;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\User;
use App\Repository\DataBreachRepository;

/**
 * Sprint-2 Foundation P-7 — Tier-1 regulatory hint.
 *
 * Trigger: `Incident.dataBreachOccurred === true` and no DataBreach is
 * linked back to this incident yet. Surfaces the GDPR Art. 33 72h
 * documentation gap: an Incident flagged as a data breach must produce a
 * DataBreach record so the 72h supervisory-authority notification window
 * is tracked and the BfDI / 16 LfDI workflow can engage.
 *
 * Tier 1 + non-dismissible: a 10 Mio. € / 2 % Gesamtumsatz fine risk
 * (Art. 83 (4) GDPR) cannot be "Nicht jetzt"-clicked away. The hint
 * persists until the operator follows the action route to the prefilled
 * DataBreach form and persists the skeleton, at which point the
 * `linkedDataBreach` lookup returns the record and the rule disengages.
 */
final class RequiresDataBreachRule extends AbstractAlvaHintRule
{
    public function __construct(
        private readonly ?DataBreachRepository $dataBreachRepository = null,
    ) {
    }

    public function key(): string
    {
        return 'incident.requires_data_breach';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        return ['incidents', 'privacy'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Incident) {
            return false;
        }
        if ($entity->isDataBreachOccurred() !== true) {
            return false;
        }
        return !$this->hasLinkedDataBreach($entity);
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Incident);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'incident.requires_data_breach.title',
            bodyTranslationKey: 'incident.requires_data_breach.body',
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Incident',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'incident.requires_data_breach.action',
            actionRoute: 'app_data_breach_new',
            actionRouteParams: ['from_incident' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            mood: 'warning',
        );
    }

    private function hasLinkedDataBreach(Incident $incident): bool
    {
        if ($this->dataBreachRepository === null) {
            return false;
        }
        $linked = $this->dataBreachRepository->findOneBy(['incident' => $incident]);
        return $linked instanceof DataBreach;
    }
}
