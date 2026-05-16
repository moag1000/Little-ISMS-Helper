<?php

declare(strict_types=1);

namespace App\Template;

use App\Service\ModuleConfigurationService;

/**
 * Central registry for SystemTemplates supplied by `TemplateProviderInterface`.
 *
 * Foundation P-14 (S5 Junior-ISB-Audit). Providers are tagged services,
 * collected via tagged_iterator and lazily harvested on first lookup. The
 * registry is stateless beyond its own indexed cache; tenant-isolation +
 * Apply-Permissions live in the controller, not here.
 *
 * Lookup affordances:
 *  - `get($key)`                — direct fetch by stable key
 *  - `all()`                    — full inventory
 *  - `findByEntity($cls, ...)`  — filter by entity FQCN, optional module + language
 *  - `findActive($cls, $lang)`  — same as above but auto-filter by active modules
 */
final class SystemTemplateRegistry
{
    /** @var array<string, SystemTemplate>|null Lazy index by template key. */
    private ?array $templates = null;

    /**
     * @param iterable<TemplateProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
        private readonly ?ModuleConfigurationService $moduleConfiguration = null,
    ) {
    }

    /**
     * Explicit registration. Useful for tests and seed scripts that want to
     * inject a single template without a full provider class.
     */
    public function register(string $key, SystemTemplate $template): void
    {
        $this->boot();
        $this->templates[$key] = $template;
    }

    public function get(string $key): ?SystemTemplate
    {
        $this->boot();
        return $this->templates[$key] ?? null;
    }

    /**
     * @return array<string, SystemTemplate>
     */
    public function all(): array
    {
        $this->boot();
        return $this->templates;
    }

    /**
     * @param class-string $entityClass
     * @return list<SystemTemplate>
     */
    public function findByEntity(string $entityClass, ?string $module = null, ?string $language = null): array
    {
        $this->boot();
        $out = [];
        foreach ($this->templates as $tpl) {
            if ($tpl->entityClass !== $entityClass) {
                continue;
            }
            if ($module !== null && $tpl->module !== null && $tpl->module !== $module) {
                continue;
            }
            if ($language !== null && $tpl->language !== $language) {
                continue;
            }
            $out[] = $tpl;
        }

        return $out;
    }

    /**
     * Same as findByEntity() but additionally filters out templates whose
     * required module is not active for the current tenant. Templates with
     * `module === null` (e.g. ModuleProfile, cross-cutting) always pass.
     *
     * @param class-string $entityClass
     * @return list<SystemTemplate>
     */
    public function findActive(string $entityClass, ?string $language = null): array
    {
        $candidates = $this->findByEntity($entityClass, null, $language);
        if ($this->moduleConfiguration === null) {
            return $candidates;
        }

        $out = [];
        foreach ($candidates as $tpl) {
            if ($tpl->module === null || $this->moduleConfiguration->isModuleActive($tpl->module)) {
                $out[] = $tpl;
            }
        }

        return $out;
    }

    /**
     * @param iterable<SystemTemplate>|callable $filter Either an iterable of
     *        templates to include, or a callable(SystemTemplate): bool. Used
     *        primarily by the `app:seed-templates` command for pattern filters.
     * @return list<SystemTemplate>
     */
    public function filter(callable $filter): array
    {
        $this->boot();
        $out = [];
        foreach ($this->templates as $tpl) {
            if ($filter($tpl)) {
                $out[] = $tpl;
            }
        }

        return $out;
    }

    private function boot(): void
    {
        if ($this->templates !== null) {
            return;
        }

        $this->templates = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->provide() as $template) {
                $this->templates[$template->key] = $template;
            }
        }
    }
}
