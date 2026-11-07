<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Service\ModuleConfigurationService;

/**
 * All {@see DeadlineSource}s, collected via the `app.deadline_source` tag.
 */
final class DeadlineSourceRegistry
{
    /** @var array<string, DeadlineSource> */
    private array $bySlug = [];

    /**
     * @param iterable<DeadlineSource> $sources
     */
    public function __construct(
        iterable $sources,
        private readonly ?ModuleConfigurationService $modules = null,
    ) {
        foreach ($sources as $source) {
            $this->bySlug[$source->slug()] = $source;
        }
    }

    /** @return list<DeadlineSource> */
    public function all(): array
    {
        return array_values($this->bySlug);
    }

    /**
     * Sources whose module is active for the current tenant.
     *
     * @return list<DeadlineSource>
     */
    public function active(): array
    {
        return array_values(array_filter(
            $this->bySlug,
            fn (DeadlineSource $s): bool => $s->requiredModule() === null
                || $this->modules === null
                || $this->modules->isModuleActive($s->requiredModule()),
        ));
    }

    public function get(string $slug): ?DeadlineSource
    {
        return $this->bySlug[$slug] ?? null;
    }

    /** The source a record belongs to (first match). */
    public function forRecord(object $record): ?DeadlineSource
    {
        foreach ($this->bySlug as $source) {
            if ($source->supports($record)) {
                return $source;
            }
        }

        return null;
    }
}
