<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\Tenant;
use App\Exception\InvalidArgument\InvalidArgumentException;
use App\Service\Deadline\Configured\ConfiguredDeadlineSources;
use App\Service\ModuleConfigurationService;

/**
 * All {@see DeadlineSource}s: the classes collected via the
 * `app.deadline_source` tag, then the deadline types declared in
 * `config/deadline_types.yaml` ({@see ConfiguredDeadlineSources}).
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
        ?ConfiguredDeadlineSources $configured = null,
    ) {
        foreach ($sources as $source) {
            $this->bySlug[$source->slug()] = $source;
        }
        foreach ($configured?->all() ?? [] as $source) {
            if (isset($this->bySlug[$source->slug()])) {
                // @intentional-assertion: App\Exception\InvalidArgument (not SPL) — broken deadline config
                throw new InvalidArgumentException(sprintf('Deadline type "%s" is declared as class and in config/deadline_types.yaml.', $source->slug()));
            }
            $this->bySlug[$source->slug()] = $source;
        }
    }

    /** @return list<DeadlineSource> */
    public function all(): array
    {
        return array_values($this->bySlug);
    }

    /**
     * Sources whose module is active for the tenant (current tenant when null).
     *
     * @return list<DeadlineSource>
     */
    public function active(?Tenant $tenant = null): array
    {
        if ($this->modules === null) {
            return $this->all();
        }
        $active = $tenant !== null
            ? $this->modules->getActiveModulesForTenant($tenant)
            : $this->modules->getActiveModules();

        return array_values(array_filter(
            $this->bySlug,
            static fn (DeadlineSource $s): bool => $s->requiredModule() === null || in_array($s->requiredModule(), $active, true),
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

    /**
     * Every source that knows the record (a risk: review, acceptance request, …).
     *
     * @return list<DeadlineSource>
     */
    public function allForRecord(object $record): array
    {
        return array_values(array_filter($this->bySlug, static fn (DeadlineSource $s): bool => $s->supports($record)));
    }
}
