<?php

declare(strict_types=1);

namespace App\Service\Planning\Source;

/**
 * Registry of all {@see SourceAdapter} implementations, collected via the
 * `app.source_adapter` tagged iterator. Also resolves a (refType, refId)
 * provenance reference back to its adapter for UI link-out.
 */
final class SourceAdapterRegistry
{
    /** @var array<string, SourceAdapter> */
    private array $bySlug = [];

    /**
     * @param iterable<SourceAdapter> $adapters
     */
    public function __construct(iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $this->bySlug[$adapter->slug()] = $adapter;
        }
    }

    /** @return list<SourceAdapter> */
    public function all(): array
    {
        return array_values($this->bySlug);
    }

    public function get(string $slug): ?SourceAdapter
    {
        return $this->bySlug[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return isset($this->bySlug[$slug]);
    }
}
