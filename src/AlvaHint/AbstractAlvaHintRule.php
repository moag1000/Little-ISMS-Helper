<?php

declare(strict_types=1);

namespace App\AlvaHint;

/**
 * Convenience base class for AlvaHintRule implementations.
 *
 * Concrete rules typically only override appliesTo() + build() and
 * keep priorityTier()/requiredModules() as one-liners. Defaults: tier 3
 * (efficiency), no module gating.
 */
abstract class AbstractAlvaHintRule implements AlvaHintRuleInterface
{
    public function priorityTier(): int
    {
        return 3;
    }

    /**
     * @return array<int, string>
     */
    public function requiredModules(): array
    {
        return [];
    }
}
