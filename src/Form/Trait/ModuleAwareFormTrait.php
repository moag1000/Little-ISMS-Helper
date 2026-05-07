<?php

declare(strict_types=1);

namespace App\Form\Trait;

use App\Service\ModuleConfigurationService;

/**
 * ModuleAwareFormTrait
 *
 * Adds module-gating helpers to Symfony FormTypes.
 *
 * Using class must declare:
 *   private readonly ModuleConfigurationService $moduleConfiguration;
 * (typically via constructor promotion) and then call isModuleActive().
 */
trait ModuleAwareFormTrait
{
    /**
     * Returns true when the given module key is active in config/active_modules.yaml.
     * Requires the using class to have a $moduleConfiguration property of type
     * ModuleConfigurationService.
     */
    protected function isModuleActive(string $moduleKey): bool
    {
        return $this->moduleConfiguration->isModuleActive($moduleKey);
    }
}
