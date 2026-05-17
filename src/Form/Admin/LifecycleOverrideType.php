<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for editing a single transition's lifecycle_config overrides.
 *
 * The four editable keys are submitted as a flat DTO-style form:
 *   - roles_raw     : comma-separated string of ROLE_* values (nullable)
 *   - reason_required (nullable bool tri-state via hidden checkbox pattern)
 *   - four_eyes      (nullable bool tri-state)
 *   - module         : module-gate string (nullable)
 *
 * Validation happens in the controller; null == "use YAML baseline".
 */
final class LifecycleOverrideType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('roles_raw', TextType::class, [
                'label' => 'admin.lifecycle_overrides.form.roles',
                'required' => false,
                'attr' => [
                    'placeholder' => 'ROLE_MANAGER, ROLE_ADMIN',
                    'autocomplete' => 'off',
                ],
                'help' => 'admin.lifecycle_overrides.form.roles_help',
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
            ->add('module', TextType::class, [
                'label' => 'admin.lifecycle_overrides.form.module',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g. privacy, documents',
                    'autocomplete' => 'off',
                ],
                'help' => 'admin.lifecycle_overrides.form.module_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'admin',
        ]);
    }
}
