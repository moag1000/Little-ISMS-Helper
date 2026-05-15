<?php

declare(strict_types=1);

namespace App\Form\Trait;

use App\Service\ModuleConfigurationService;
use Symfony\Component\Form\FormBuilderInterface;

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

    /**
     * Add a field to the form builder only when the given module is active.
     *
     * Returns the trait-using instance (for fluent chaining inside buildForm()).
     * Use this for single-field gates inside otherwise core sections; prefer
     * dedicated `if ($this->isModuleActive('X')) { $this->addXFields($b); }`
     * helper-method blocks when many fields share the same gate.
     *
     * Example:
     *   $this
     *       ->addModuleGatedField($builder, 'privacy', 'hasDPA', CheckboxType::class, [...])
     *       ->addModuleGatedField($builder, 'privacy', 'dpaSignedDate', DateType::class, [...]);
     *
     * @param array<string, mixed> $options
     */
    protected function addModuleGatedField(
        FormBuilderInterface $builder,
        string $moduleKey,
        string $field,
        string $type,
        array $options = [],
    ): self {
        if ($this->isModuleActive($moduleKey)) {
            $builder->add($field, $type, $options);
        }
        return $this;
    }
}
