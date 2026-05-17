<?php

declare(strict_types=1);

namespace App\Lifecycle;

/**
 * Maps URL slugs (e.g. "document") to entity FQCN + workflow name.
 *
 * Foundation pilot (X.0) shipped the Document mapping.
 * Sprint X.1 adds ProcessingActivity and ISMSObjective.
 *
 * Deferred (blocked — see X.1 PR description):
 *   - PolicyTemplate: no `status` field (uses `isActive: bool`) — needs entity refactor.
 *   - Asset: domain-specific status model (active/inactive/in_use/returned/retired/disposed)
 *     incompatible with standard 5-stage flow — needs a custom workflow design.
 */
final class EntityTypeRegistry
{
    /** @var array<string, array{class: class-string, workflow: string}> */
    private const array MAP = [
        'document' => [
            'class' => \App\Entity\Document::class,
            'workflow' => 'document_lifecycle',
        ],
        'processing-activity' => [
            'class' => \App\Entity\ProcessingActivity::class,
            'workflow' => 'processing_activity_lifecycle',
        ],
        'isms-objective' => [
            'class' => \App\Entity\ISMSObjective::class,
            'workflow' => 'isms_objective_lifecycle',
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
