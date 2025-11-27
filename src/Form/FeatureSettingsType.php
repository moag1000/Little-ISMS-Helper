<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Feature Settings Form Type
 *
 * DTO form for system-wide feature toggles.
 * Data is stored in SystemSettings entity as key-value pairs.
 */
class FeatureSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enable_dark_mode', CheckboxType::class, [
                'label' => 'admin.settings.features.enable_dark_mode',
                'required' => false,
                'help' => 'admin.settings.features.enable_dark_mode_help',
            ])
            ->add('enable_global_search', CheckboxType::class, [
                'label' => 'admin.settings.features.enable_global_search',
                'required' => false,
                'help' => 'admin.settings.features.enable_global_search_help',
            ])
            ->add('enable_quick_view', CheckboxType::class, [
                'label' => 'admin.settings.features.enable_quick_view',
                'required' => false,
                'help' => 'admin.settings.features.enable_quick_view_help',
            ])
            ->add('enable_notifications', CheckboxType::class, [
                'label' => 'admin.settings.features.enable_notifications',
                'required' => false,
                'help' => 'admin.settings.features.enable_notifications_help',
            ])
            ->add('enable_audit_log', CheckboxType::class, [
                'label' => 'admin.settings.features.enable_audit_log',
                'required' => false,
                'help' => 'admin.settings.features.enable_audit_log_help',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'admin',
        ]);
    }
}
