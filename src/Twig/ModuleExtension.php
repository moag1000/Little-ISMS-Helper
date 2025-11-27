<?php

namespace App\Twig;

use Override;
use App\Service\ModuleConfigurationService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

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
class ModuleExtension extends AbstractExtension
{
    public function __construct(
        private readonly ModuleConfigurationService $moduleConfigurationService
    ) {
    }

    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_module_active', $this->isModuleActive(...)),
            new TwigFunction('get_active_modules', $this->getActiveModules(...)),
            new TwigFunction('get_module_info', $this->getModuleInfo(...)),
        ];
    }

    /**
     * Check if a module is active
     */
    public function isModuleActive(string $moduleKey): bool
    {
        return $this->moduleConfigurationService->isModuleActive($moduleKey);
    }

    /**
     * Get all active modules
     */
    public function getActiveModules(): array
    {
        return $this->moduleConfigurationService->getActiveModules();
    }

    /**
     * Get module information
     */
    public function getModuleInfo(string $moduleKey): ?array
    {
        return $this->moduleConfigurationService->getModule($moduleKey);
    }
}
