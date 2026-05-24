<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — Document form: acknowledgement required
 * but no audience selected.
 *
 * Fires when the user ticks `requiresAcknowledgement = true` without
 * selecting any users in `acknowledgementAudience`. The Document entity
 * treats an empty audience as "fan out to everyone in the tenant" —
 * which is rarely the intent and floods all users with a forced
 * acknowledgement task. ISO 27001 A.6.3 ("Information security
 * awareness, education and training") expects targeted role-based
 * communication.
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class DocumentAcknowledgementWithoutAudienceInlineRule implements AlvaHintFormRuleInterface
{
    public function key(): string
    {
        return 'document.form.acknowledgement_without_audience';
    }

    public function entityType(): string
    {
        return 'document';
    }

    public function requiredModules(): array
    {
        return [];
    }

    public function requiredRoles(): array
    {
        return [];
    }

    public function supports(array $payload, User $user): bool
    {
        $requires = $payload['requiresAcknowledgement'] ?? null;
        $isTruthy = $requires === true
            || $requires === 1
            || $requires === '1'
            || $requires === 'true';
        if (!$isTruthy) {
            return false;
        }

        // Only fire when the form actually exposes the audience field — on
        // a brand-new document without the toggle yet enabled the audience
        // input may not be rendered.
        if (!array_key_exists('acknowledgementAudience', $payload)) {
            // Toggle is on, but audience field not in payload → assume
            // empty (worst-case) and fire — better one false-positive
            // heads-up than a silent miss.
            return true;
        }

        $audience = $payload['acknowledgementAudience'];

        if ($audience === null || $audience === '' || $audience === false) {
            return true;
        }
        if (is_array($audience) && $audience === []) {
            return true;
        }

        return false;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'acknowledgementAudience',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.document_acknowledgement_without_audience.title',
            bodyTranslationKey: 'alva_hint.form.document_acknowledgement_without_audience.body',
            translationDomain: 'alva',
            mood: 'warning',
        );
    }
}
