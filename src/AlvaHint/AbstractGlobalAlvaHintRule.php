<?php

declare(strict_types=1);

namespace App\AlvaHint;

/**
 * Convenience base class for global (tenant-scoped) AlvaHint rules.
 *
 * Concrete rules override appliesToPages(), evaluate(), and
 * optionally priorityTier() / requiredModules(). Defaults: tier 3,
 * no module gate, fires on all pages.
 */
abstract class AbstractGlobalAlvaHintRule implements GlobalAlvaHintRuleInterface
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

    /**
     * @return array<int, string>
     */
    public function appliesToPages(): array
    {
        return [];
    }
}
