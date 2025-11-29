<?php

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;
use App\Service\ModuleConfigurationService;

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
 */
class ModuleExtension
{
    public function __construct(
        private readonly ModuleConfigurationService $moduleConfigurationService
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
}
