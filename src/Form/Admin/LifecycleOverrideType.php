<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Lifecycle\Config\LifecycleOverridePolicy;
use App\Service\ModuleConfigurationService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for editing a single transition's lifecycle_config overrides.
 *
 * The four editable keys are submitted as a flat DTO-style form:
 *   - roles         : roles from {@see LifecycleOverridePolicy::assignableRoles()} (S15: no free
 *                     text — ROLE_USER, PUBLIC_ACCESS or a typo used to be accepted); disabled
 *                     where the YAML roles are locked (Leitung, DSB)
 *   - reason_required (nullable bool tri-state via hidden checkbox pattern)
 *   - four_eyes      (nullable bool tri-state)
 *   - module         : module-gate string (nullable)
 *
 * The controller validates every value with {@see LifecycleOverridePolicy::violations()};
 * empty == "use YAML baseline".
 */
final class LifecycleOverrideType extends AbstractType
{
    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('roles', ChoiceType::class, [
                'label' => 'admin.lifecycle_overrides.form.roles',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                // S28: only roles that narrow the YAML circle (the controller passes them).
                'choices' => array_combine($options['role_choices'], $options['role_choices']),
                'choice_translation_domain' => false,
                'disabled' => $options['roles_locked'],
                'help' => $options['roles_locked']
                    ? 'admin.lifecycle_overrides.form.roles_locked_help'
                    : 'admin.lifecycle_overrides.form.roles_help',
            ])
            ->add('reason_required', CheckboxType::class, [
                'label' => 'admin.lifecycle_overrides.form.reason_required',
                'required' => false,
            ])
            ->add('reason_required_override', CheckboxType::class, [
                'label' => 'admin.lifecycle_overrides.form.override_enabled',
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => 'lifecycle-override-toggle'],
            ])
            ->add('four_eyes', CheckboxType::class, [
                'label' => 'admin.lifecycle_overrides.form.four_eyes',
                'required' => false,
            ])
            ->add('four_eyes_override', CheckboxType::class, [
                'label' => 'admin.lifecycle_overrides.form.override_enabled',
                'required' => false,
                'mapped' => false,
                'attr' => ['class' => 'lifecycle-override-toggle'],
            ])
            ->add('module', ChoiceType::class, [
                'label' => 'admin.lifecycle_overrides.form.module',
                'required' => false,
                // Only keys from config/modules.yaml are selectable: an unknown key
                // would make ModuleGateGuard block the transition permanently.
                'choices' => $this->moduleChoices(),
                'choice_translation_domain' => false,
                'placeholder' => '—',
                'help' => 'admin.lifecycle_overrides.form.module_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'admin',
            'roles_locked' => false,
            'role_choices' => LifecycleOverridePolicy::assignableRoles(),
        ]);
        $resolver->setAllowedTypes('roles_locked', 'bool');
        $resolver->setAllowedTypes('role_choices', 'string[]');
    }

    /**
     * @return array<string, string> label => module key
     */
    private function moduleChoices(): array
    {
        $choices = [];
        foreach ($this->moduleConfiguration->getAllModules() as $key => $module) {
            $name = is_array($module) && isset($module['name']) ? (string) $module['name'] : (string) $key;
            $choices[sprintf('%s (%s)', $name, $key)] = (string) $key;
        }

        return $choices;
    }
}
