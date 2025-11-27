<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Application Settings Form Type
 *
 * DTO form for system-wide application settings (locale, timezone, display).
 * Data is stored in SystemSettings entity as key-value pairs.
 */
class ApplicationSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Locale Settings
            ->add('default_locale', ChoiceType::class, [
                'label' => 'admin.settings.application.default_locale',
                'choices' => [
                    'Deutsch (de)' => 'de',
                    'English (en)' => 'en',
                ],
                'required' => true,
                'help' => 'admin.settings.application.default_locale_help',
            ])
            ->add('supported_locales', ChoiceType::class, [
                'label' => 'admin.settings.application.supported_locales',
                'choices' => [
                    'Deutsch (de)' => 'de',
                    'English (en)' => 'en',
                ],
                'multiple' => true,
                'expanded' => true,
                'required' => true,
                'help' => 'admin.settings.application.supported_locales_help',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Count(['min' => 1]),
                ],
            ])

            // Display Settings
            ->add('items_per_page', IntegerType::class, [
                'label' => 'admin.settings.application.items_per_page',
                'required' => true,
                'attr' => [
                    'min' => 10,
                    'max' => 100,
                    'step' => 5,
                ],
                'help' => 'admin.settings.application.items_per_page_help',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 10, 'max' => 100]),
                ],
            ])
            ->add('timezone', ChoiceType::class, [
                'label' => 'admin.settings.application.timezone',
                'choices' => [
                    'Europe/Berlin (GMT+1)' => 'Europe/Berlin',
                    'Europe/London (GMT)' => 'Europe/London',
                    'America/New_York (GMT-5)' => 'America/New_York',
                    'UTC' => 'UTC',
                ],
                'required' => true,
                'help' => 'admin.settings.application.timezone_help',
            ])
            ->add('date_format', ChoiceType::class, [
                'label' => 'admin.settings.application.date_format',
                'choices' => [
                    '31.12.2024 (d.m.Y)' => 'd.m.Y',
                    '2024-12-31 (Y-m-d)' => 'Y-m-d',
                    '12/31/2024 (m/d/Y)' => 'm/d/Y',
                ],
                'required' => true,
                'help' => 'admin.settings.application.date_format_help',
            ])
            ->add('datetime_format', ChoiceType::class, [
                'label' => 'admin.settings.application.datetime_format',
                'choices' => [
                    '31.12.2024 23:59' => 'd.m.Y H:i',
                    '2024-12-31 23:59:59' => 'Y-m-d H:i:s',
                    '12/31/2024 11:59 PM' => 'm/d/Y g:i A',
                ],
                'required' => true,
                'help' => 'admin.settings.application.datetime_format_help',
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
