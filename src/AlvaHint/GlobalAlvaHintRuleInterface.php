<?php

declare(strict_types=1);

namespace App\AlvaHint;

use App\Entity\Tenant;
use App\Entity\User;

/**
 * Contract for tenant-scoped cross-module Alva-Fee hint rules.
 *
 * Unlike entity-bound AlvaHintRuleInterface, global rules do not receive
 * a specific entity — they run once per page-load and inspect the tenant's
 * aggregate state (e.g. "any high-CIA asset without a linked Risk").
 *
 * Rules live in src/AlvaHint/Rule/Global/ and are tagged alva.global_hint_rule.
 * AlvaHintService::getTenantGlobalHints() collects and filters them.
 */
interface GlobalAlvaHintRuleInterface
{
    /**
     * Stable identifier, also used for dismissal + audit log lookup.
     * Convention: `global.<short_action>`, e.g. "global.asset_ohne_risk".
     */
    public function key(): string;

    /**
     * Hint priority. Lower wins when multiple rules fire on the same page.
     * 1 = regulatory / hard deadline
     * 2 = audit gap closer
     * 3 = efficiency / data reuse
     */
    public function priorityTier(): int;

    /**
     * Modules that must be active in the tenant for this rule to fire.
     * Empty array = unconditional.
     *
     * @return array<int, string>
     */
    public function requiredModules(): array;

    /**
     * Page keys where this hint is relevant.
     * E.g. ['asset_index', 'dashboard_ciso'].
     * Empty array = shown on ALL pages.
     *
     * @return array<int, string>
     */
    public function appliesToPages(): array;

    /**
     * Evaluate tenant state and return a hint if the rule fires, or null.
     * Keep cheap — no writes, minimal queries (use COUNT subqueries).
     */
    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint;
}
