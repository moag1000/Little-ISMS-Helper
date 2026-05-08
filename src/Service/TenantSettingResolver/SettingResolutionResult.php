<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

/**
 * Policy-Wizard W1 — value object returned by TenantSettingResolver::resolveFor().
 *
 * Carries the resolved value plus enough metadata for callers (and the
 * upcoming wizard UI) to surface "this value comes from the Konzern"
 * banners and "your local override was rejected" warnings.
 */
final class SettingResolutionResult
{
    /**
     * @param mixed                         $value             Effective value after walking the ancestor chain.
     * @param int|string|null               $sourceTenantId    Tenant id whose stored value won the resolution.
     *                                                         null = pure default (no stored value anywhere in chain).
     *                                                         'default' = convenience marker for default fallback.
     * @param OverrideMode                  $effectiveMode     Override mode of the chain step that produced the value.
     * @param bool                          $childRelaxBlocked True when a descendant tried to store a looser value
     *                                                         that violates the override-mode and got clamped here.
     * @param list<RelaxAttempt>            $relaxAttempts     Per-tenant audit trail of blocked relax attempts during
     *                                                         this resolution walk. Empty list when nothing was blocked.
     */
    public function __construct(
        public readonly mixed $value,
        public readonly int|string|null $sourceTenantId,
        public readonly OverrideMode $effectiveMode,
        public readonly bool $childRelaxBlocked = false,
        public readonly array $relaxAttempts = [],
    ) {
    }

    public function getValue(): mixed
    {
        return $this->value;
    }
}
