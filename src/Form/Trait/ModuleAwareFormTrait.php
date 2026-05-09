<?php

declare(strict_types=1);

namespace App\Form\Trait;

use App\Service\ModuleConfigurationService;

/**
 * ModuleAwareFormTrait
 *
 * Provides module-awareness to FormTypes — gate fields/sections by active modules.
 *
 * Using class must declare:
 *   private readonly ModuleConfigurationService $moduleConfiguration;
 * (typically via constructor promotion) and then call isModuleActive().
 *
 * Usage:
 *   class MyFormType extends AbstractType {
 *       use ModuleAwareFormTrait;
 *
 *       public function __construct(
 *           private readonly ModuleConfigurationService $moduleConfiguration,
 *       ) {}
 *
 *       public function buildForm(FormBuilderInterface $builder, array $options): void {
 *           $this->addCoreFields($builder);
 *           if ($this->isModuleActive('privacy')) {
 *               $this->addGdprFields($builder);
 *           }
 *       }
 *   }
 *
 * Module-Keys see config/modules.yaml. ModuleConfigurationService is autowired.
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

    /**
     * Check if any of the given modules is active. Useful for OR-conditional sections.
     *
     * Example: $this->isAnyModuleActive('nis2_dora', 'marisk') — show field if either is on.
     */
    protected function isAnyModuleActive(string ...$moduleKeys): bool
    {
        foreach ($moduleKeys as $key) {
            if ($this->moduleConfiguration->isModuleActive($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if ALL given modules are active. Useful for AND-conditional sections.
     */
    protected function areAllModulesActive(string ...$moduleKeys): bool
    {
        foreach ($moduleKeys as $key) {
            if (!$this->moduleConfiguration->isModuleActive($key)) {
                return false;
            }
        }
        return true;
    }
}
