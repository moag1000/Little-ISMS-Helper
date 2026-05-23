<?php

declare(strict_types=1);

namespace App\AlvaHint;

use App\Entity\User;

/**
 * Contract for an Alva-Fee form-step inline hint rule.
 *
 * One concrete rule per "while you are filling out this form, FYI…"
 * suggestion. Rules are registered as tagged services
 * (`alva.form_hint_rule`) and discovered by
 * {@see \App\Service\AlvaHint\AlvaHintFormEvaluator}. They are stateless —
 * no caching, no DB writes — so they remain safe to evaluate on every
 * form-field-change AJAX call.
 *
 * Distinction vs. {@see AlvaHintRuleInterface}:
 *
 * - Show-page rule (`AlvaHintRuleInterface`) evaluates an entity AFTER
 *   persistence and surfaces a card on the show template.
 * - Form-step rule (this interface) evaluates a draft entity / form payload
 *   BEFORE persistence and surfaces an inline alert next to a specific
 *   form field. No DB writes ever happen in the rule itself.
 *
 * Foundation pattern P-19: Form-Step-Inline-Hint.
 */
interface AlvaHintFormRuleInterface
{
    /**
     * Stable identifier — used for DOM ids, telemetry, log lines.
     * Convention: `<module>.form.<short_trigger>`,
     * e.g. "incident.form.data_breach_will_be_created".
     */
    public function key(): string;

    /**
     * Slug of the entity type this rule reacts to. Matches the slug
     * sent in by the Stimulus controller (`incident`, `risk`, etc.). The
     * evaluator uses this to filter the rule iterator before invoking
     * supports() / evaluate() — keeps the per-request rule fan-out cheap.
     */
    public function entityType(): string;

    /**
     * Modules that must be active in the tenant for this rule to make
     * sense. Empty array = unconditional.
     *
     * @return array<int, string>
     */
    public function requiredModules(): array;

    /**
     * Roles the current user must hold for the hint to be shown. Empty
     * array = unconditional.
     *
     * @return array<int, string>
     */
    public function requiredRoles(): array;

    /**
     * Whether this rule can produce a hint for the given form payload
     * snapshot. Implementations must keep this cheap — no DB queries
     * unless absolutely required.
     *
     * The payload is a normalized array (string keys, scalar/array values)
     * derived from the live form state on the client. Missing keys are
     * normal — the rule should treat them as "user has not entered a
     * value yet" and short-circuit.
     *
     * @param array<string, mixed> $payload
     */
    public function supports(array $payload, User $user): bool;

    /**
     * Build the inline hint. Only called when supports() returned true.
     *
     * @param array<string, mixed> $payload
     */
    public function evaluate(array $payload, User $user): AlvaFormHint;
}
