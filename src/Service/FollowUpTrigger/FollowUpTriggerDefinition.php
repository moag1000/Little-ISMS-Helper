<?php

declare(strict_types=1);

namespace App\Service\FollowUpTrigger;

use Closure;

/**
 * Declarative description of a follow-up trigger that fires when a parent
 * entity reaches a specific field state and a follow-up entity is not yet
 * linked.
 *
 * Used by FollowUpTriggerService (Sprint-2 Foundation P-7). The motivating
 * use-case is the regulatory pattern "Incident.dataBreachOccurred = true"
 * which must, per GDPR Art. 33, surface a 72h-deadline follow-up to create
 * a linked DataBreach. The DTO is intentionally minimal: the caller wires
 * the surrounding plumbing (AlvaHint emission, listener integration, audit
 * trail).
 *
 * - `fieldName`: getter-style property name on the parent entity, e.g.
 *   `dataBreachOccurred`. Service resolves it through reflection so legacy
 *   `isXxx()` and modern `getXxx()` accessors both work.
 * - `equals`: expected value of that field for the trigger to fire (strict
 *   equality). Passing `true` and the field returning `1` will not match.
 * - `alvaHintKey`: stable AlvaHint rule key the trigger correlates with.
 *   Mirrors AlvaHintRuleInterface::key() so dismissal + audit-log can
 *   reuse the same identifier.
 * - `followUpRoute`: route name the operator should land on (e.g.
 *   `app_data_breach_new`). Used by the consumer to build action URLs.
 * - `preFiller`: optional Closure(object $parent): array<string,mixed>
 *   producing a name→value map that the follow-up form can hydrate from.
 *   Returning an empty array is allowed.
 */
final readonly class FollowUpTriggerDefinition
{
    public function __construct(
        public string $fieldName,
        public mixed $equals,
        public string $alvaHintKey,
        public ?string $followUpRoute = null,
        public ?Closure $preFiller = null,
    ) {
    }
}
