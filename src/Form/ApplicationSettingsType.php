<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Application Settings Form Type
 *
 * DTO form for system-wide application settings (locale, timezone, display).
 * Data is stored in SystemSettings entity as key-value pairs.
 */
final class ApplicationSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder

            // Display Settings
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
