<?php

declare(strict_types=1);

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
final class FeatureSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enable_dark_mode', CheckboxType::class, [
                'label' => 'admin.settings.features.dark_mode',
                'required' => false,
                'help' => 'admin.settings.features.dark_mode_help',
            ])
            ->add('enable_global_search', CheckboxType::class, [
                'label' => 'admin.settings.features.global_search',
                'required' => false,
                'help' => 'admin.settings.features.global_search_help',
            ])
            ->add('enable_quick_view', CheckboxType::class, [
                'label' => 'admin.settings.features.quick_view',
                'required' => false,
                'help' => 'admin.settings.features.quick_view_help',
            ])
            ->add('enable_notifications', CheckboxType::class, [
                'label' => 'admin.settings.features.notifications',
                'required' => false,
                'help' => 'admin.settings.features.notifications_help',
            ])
            ->add('enable_audit_log', CheckboxType::class, [
                'label' => 'admin.settings.features.audit_log',
                'required' => false,
                'help' => 'admin.settings.features.audit_log_help',
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
