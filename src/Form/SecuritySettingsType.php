<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Security Settings Form Type
 *
 * DTO form for system-wide security settings (session, password, 2FA, login).
 * Data is stored in SystemSettings entity as key-value pairs.
 */
class SecuritySettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Session Settings
            ->add('session_lifetime', IntegerType::class, [
                'label' => 'admin.settings.security.session_lifetime',
                'required' => true,
                'attr' => [
                    'min' => 300,
                    'max' => 86400,
                ],
                'help' => 'admin.settings.security.session_lifetime_help',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 300, 'max' => 86400]),
                ],
            ])
            ->add('remember_me_lifetime', IntegerType::class, [
                'label' => 'admin.settings.security.remember_me_lifetime',
                'required' => true,
                'attr' => [
                    'min' => 86400,
                    'max' => 7776000,
                ],
                'help' => 'admin.settings.security.remember_me_lifetime_help',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 86400, 'max' => 7776000]),
                ],
            ])

            // Password Policy
            ->add('password_min_length', IntegerType::class, [
                'label' => 'admin.settings.security.password_min_length',
                'required' => true,
                'attr' => [
                    'min' => 8,
                    'max' => 128,
                ],
                'help' => 'admin.settings.security.password_min_length_help',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 8, 'max' => 128]),
                ],
            ])

            // 2FA Settings
            ->add('require_2fa', CheckboxType::class, [
                'label' => 'admin.settings.security.require_2fa',
                'required' => false,
                'help' => 'admin.settings.security.require_2fa_help',
            ])

            // Login Protection
            ->add('max_login_attempts', IntegerType::class, [
                'label' => 'admin.settings.security.max_login_attempts',
                'required' => true,
                'attr' => [
                    'min' => 3,
                    'max' => 10,
                ],
                'help' => 'admin.settings.security.max_login_attempts_help',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 3, 'max' => 10]),
                ],
            ])
            ->add('lockout_duration', IntegerType::class, [
                'label' => 'admin.settings.security.lockout_duration',
                'required' => true,
                'attr' => [
                    'min' => 300,
                    'max' => 3600,
                ],
                'help' => 'admin.settings.security.lockout_duration_help',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(['min' => 300, 'max' => 3600]),
                ],
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
