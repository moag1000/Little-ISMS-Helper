<?php

declare(strict_types=1);

namespace App\Lifecycle;

/**
 * Maps URL slugs (e.g. "document") to entity FQCN + workflow name.
 * Foundation pilot ships only the Document mapping; future sprints
 * add rows.
 */
final class EntityTypeRegistry
{
    /** @var array<string, array{class: class-string, workflow: string}> */
    private const array MAP = [
        'document' => [
            'class' => \App\Entity\Document::class,
            'workflow' => 'document_lifecycle',
        ],
    ];

    /** @return array{class: class-string, workflow: string}|null */
    public function lookup(string $slug): ?array
    {
        return self::MAP[$slug] ?? null;
    }

    /** @return string[] */
    public function knownSlugs(): array
    {
        return array_keys(self::MAP);
    }
}
