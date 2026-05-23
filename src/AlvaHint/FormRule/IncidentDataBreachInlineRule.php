<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 reference rule — Form-Step-Inline-Hint.
 *
 * Fires while the user is filling out the Incident form and ticks
 * "Datenpanne aufgetreten = Ja". Surfaces an inline notice directly under
 * the `dataBreachOccurred` radio explaining what will happen on save:
 *
 * - A DataBreach record will be auto-created (FollowUpTrigger payload is
 *   prefilled from the incident — see {@see \App\EventListener\IncidentFollowUpListener}).
 * - The DSGVO Art. 33 72-hour clock starts at the `detectedAt` timestamp.
 * - The user can navigate to the DataBreach form straight after save via
 *   the show-page AlvaHint
 *   ({@see \App\AlvaHint\Rule\Incident\RequiresDataBreachRule}).
 *
 * This is an informational pre-save heads-up (tier: warning) — never a
 * regulatory hard-deadline, because no SLA has started yet. The "real"
 * regulatory Tier-1 hint fires on the show page after persistence.
 */
final class IncidentDataBreachInlineRule implements AlvaHintFormRuleInterface
{
    public function key(): string
    {
        return 'incident.form.data_breach_will_be_created';
    }

    public function entityType(): string
    {
        return 'incident';
    }

    public function requiredModules(): array
    {
        return ['incidents', 'privacy'];
    }

    public function requiredRoles(): array
    {
        return [];
    }

    public function supports(array $payload, User $user): bool
    {
        if (!array_key_exists('dataBreachOccurred', $payload)) {
            return false;
        }
        // Form-submitted radios arrive as the literal strings "1" / "0";
        // JSON payloads may arrive as native booleans. Normalize both.
        $value = $payload['dataBreachOccurred'];
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'true';
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'dataBreachOccurred',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.incident_data_breach.title',
            bodyTranslationKey: 'alva_hint.form.incident_data_breach.body',
            translationDomain: 'alva',
            mood: 'warning',
        );
    }
}
