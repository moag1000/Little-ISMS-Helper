<?php

declare(strict_types=1);

namespace App\AlvaHint;

use App\Entity\User;

/**
 * Contract for an Alva-Fee proactive hint rule.
 *
 * One concrete rule per "hey, wir haben da was…" suggestion. Rules are
 * registered as tagged services (`alva.hint_rule`) and discovered by
 * AlvaHintService. They are stateless — no caching, no DB writes — so
 * they remain safe to evaluate on every page load.
 */
interface AlvaHintRuleInterface
{
    /**
     * Stable identifier, also used for dismissal + audit log lookup.
     * Convention: `<module>.<short_action>`, e.g. "asset.protection_inheritance".
     */
    public function key(): string;

    /**
     * Hint priority. Lower wins when multiple rules match the same page.
     * 1 = regulatory / hard deadline
     * 2 = audit gap closer
     * 3 = efficiency / data reuse
     */
    public function priorityTier(): int;

    /**
     * Modules that must be active in the tenant for this rule to make
     * sense. The service skips evaluation entirely when any of these is
     * inactive — prevents hints that propose actions in modules the
     * tenant has not even enabled. Empty array = unconditional.
     *
     * @return array<int, string>  module keys, e.g. ['risks', 'controls']
     */
    public function requiredModules(): array;

    /**
     * Whether this rule applies to the given entity in the given user's
     * context. Implementations must keep this cheap — no DB writes,
     * minimal queries.
     */
    public function appliesTo(object $entity, User $user): bool;

    /**
     * Build the renderable hint. Only called when appliesTo() returned true.
     */
    public function build(object $entity, User $user): AlvaHint;
}
