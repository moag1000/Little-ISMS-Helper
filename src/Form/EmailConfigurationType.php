<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for email/SMTP configuration during setup wizard.
 *
 * Optional step - users can skip and configure later.
 */
final class EmailConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('transport', ChoiceType::class, [
                'label' => 'setup.email.transport',
                'choices' => [
                    'setup.email.transport.smtp' => 'smtp',
                    'setup.email.transport.sendmail' => 'sendmail',
                    'setup.email.transport.native' => 'native',
                ],
                'data' => 'smtp',
                'required' => true,
                                'help' => 'setup.email.transport_help',
                    'choice_translation_domain' => 'admin',
            ])
            ->add('host', TextType::class, [
                'label' => 'setup.email.host',
                'required' => false,
                'data' => 'localhost',
                'attr' => [
                    'placeholder' => 'smtp.example.com',
                ],
                'help' => 'setup.email.host_help',
            ])
            ->add('port', IntegerType::class, [
                'label' => 'setup.email.port',
                'required' => false,
                'data' => 587,
                'attr' => [
                    'placeholder' => '587',
                ],
                'help' => 'setup.email.port_help',
            ])
            ->add('encryption', ChoiceType::class, [
                'label' => 'setup.email.encryption',
                'choices' => [
                    'setup.email.encryption.none' => null,
                    'setup.email.encryption.tls' => 'tls',
                    'setup.email.encryption.ssl' => 'ssl',
                ],
                'data' => 'tls',
                'required' => false,
                                'help' => 'setup.email.encryption_help',
                    'choice_translation_domain' => 'admin',
            ])
            ->add('username', TextType::class, [
                'label' => 'setup.email.username',
                'required' => false,
                'attr' => [
                    'placeholder' => 'user@example.com',
                ],
                'help' => 'setup.email.username_help',
            ])
            ->add('password', PasswordType::class, [
                'label' => 'setup.email.password',
                'required' => false,
                'attr' => [
                    'placeholder' => '••••••••',
                ],
                'help' => 'setup.email.password_help',
            ])
            ->add('from_address', EmailType::class, [
                'label' => 'setup.email.from_address',
                'required' => false,
                'attr' => [
                    'placeholder' => 'noreply@example.com',
                ],
                'help' => 'setup.email.from_address_help',
            ])
            ->add('from_name', TextType::class, [
                'label' => 'setup.email.from_name',
                'required' => false,
                'data' => 'Little ISMS Helper',
                'attr' => [
                    'placeholder' => 'setup.email.from_name_placeholder',
                ],
                'help' => 'setup.email.from_name_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'email_config',
            'translation_domain' => 'setup',
        ]);
    }
}
