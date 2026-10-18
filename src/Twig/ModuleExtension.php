<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;
use App\Service\ModuleConfigurationService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Twig Extension for Module Management
 *
 * Provides Twig functions to check module activation status and filter navigation/features
 * based on active modules configuration.
 *
 * Available Twig Functions:
 * - is_module_active(moduleKey): Check if a specific module is active
 * - get_active_modules(): Get list of all active module keys
 * - get_module_info(moduleKey): Get detailed module information
 * - module_name(moduleKey, fallback): Translated module name
 * - module_description(moduleKey, fallback): Translated module description
 * - module_data_text(entry, field): Translated name/description of a
 *   base_data / sample_data entry from config/modules.yaml
 *
 * Module names and descriptions live in config/modules.yaml (German source
 * text). The translated variants are looked up under
 * `admin.modules.catalog.<key>.name|description` in the `admin` domain; when
 * no translation exists the configured text is returned unchanged.
 */
final class ModuleExtension
{
    private const string CATALOG_PREFIX = 'admin.modules.catalog.';
    private const string CATALOG_DOMAIN = 'admin';
    private const string DATA_CATALOG_PREFIX = 'admin.modules.data_catalog.';

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Check if a module is active
     */
    #[AsTwigFunction('is_module_active')]
    public function isModuleActive(string $moduleKey): bool
    {
        return $this->moduleConfigurationService->isModuleActive($moduleKey);
    }

    /**
     * Get all active modules
     */
    #[AsTwigFunction('get_active_modules')]
    public function getActiveModules(): array
    {
        return $this->moduleConfigurationService->getActiveModules();
    }

    /**
     * Get module information
     */
    #[AsTwigFunction('get_module_info')]
    public function getModuleInfo(string $moduleKey): ?array
    {
        return $this->moduleConfigurationService->getModule($moduleKey);
    }

    /**
     * Translated module name; falls back to the configured name.
     */
    #[AsTwigFunction('module_name')]
    public function moduleName(string $moduleKey, ?string $fallback = null): string
    {
        return $this->translateCatalogField($moduleKey, 'name', $fallback);
    }

    /**
     * Translated module description; falls back to the configured description.
     */
    #[AsTwigFunction('module_description')]
    public function moduleDescription(string $moduleKey, ?string $fallback = null): string
    {
        return $this->translateCatalogField($moduleKey, 'description', $fallback);
    }

    /**
     * Translated name/description of a base_data or sample_data entry.
     *
     * Entries are identified by their fixture file basename (e.g.
     * `sample_assets`) or, for command-based entries, by the normalised
     * command name (e.g. `app_load_tisax_requirements`). Falls back to the
     * configured text.
     *
     * @param array<string, mixed> $entry
     */
    #[AsTwigFunction('module_data_text')]
    public function moduleDataText(array $entry, string $field = 'name'): string
    {
        $fallback = (string) ($entry[$field] ?? '');
        $source = null;
        if (isset($entry['file']) && is_string($entry['file'])) {
            $source = (string) preg_replace('/\.ya?ml$/', '', basename($entry['file']));
        } elseif (isset($entry['command']) && is_string($entry['command'])) {
            $source = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($entry['command'])), '_');
        }
        if ($source === null || $source === '') {
            return $fallback;
        }
        $id = self::DATA_CATALOG_PREFIX . $source . '.' . $field;
        $translated = $this->translator->trans($id, [], self::CATALOG_DOMAIN);

        return ($translated !== $id && $translated !== '') ? $translated : $fallback;
    }

    private function translateCatalogField(string $moduleKey, string $field, ?string $fallback): string
    {
        $id = self::CATALOG_PREFIX . $moduleKey . '.' . $field;
        $translated = $this->translator->trans($id, [], self::CATALOG_DOMAIN);
        if ($translated !== $id && $translated !== '') {
            return $translated;
        }
        if ($fallback !== null) {
            return $fallback;
        }
        $module = $this->moduleConfigurationService->getModule($moduleKey);

        return (string) ($module[$field] ?? $moduleKey);
    }
}
