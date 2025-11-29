<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form for creating the initial admin user during setup wizard.
 */
class AdminUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'setup.admin.email',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(message: 'setup.admin.email_required'),
                    new Assert\Email(message: 'setup.admin.email_invalid'),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'admin@example.com',
                    'autocomplete' => 'email',
                ],
                'help' => 'setup.admin.email_help',
            ])
            ->add('firstName', TextType::class, [
                'label' => 'setup.admin.first_name',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(message: 'setup.admin.first_name_required'),
                    new Assert\Length(min: 2, max: 50, minMessage: 'setup.admin.first_name_min', maxMessage: 'setup.admin.first_name_max'),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'setup.admin.first_name_placeholder',
                    'autocomplete' => 'given-name',
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'setup.admin.last_name',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(message: 'setup.admin.last_name_required'),
                    new Assert\Length(min: 2, max: 50, minMessage: 'setup.admin.last_name_min', maxMessage: 'setup.admin.last_name_max'),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'setup.admin.last_name_placeholder',
                    'autocomplete' => 'family-name',
                ],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'setup.admin.password_mismatch',
                'required' => true,
                'first_options' => [
                    'label' => 'setup.admin.password',
                    'attr' => [
                        'class' => 'form-control',
                        'placeholder' => '••••••••',
                        'autocomplete' => 'new-password',
                    ],
                    'help' => 'setup.admin.password_help',
                ],
                'second_options' => [
                    'label' => 'setup.admin.password_confirm',
                    'attr' => [
                        'class' => 'form-control',
                        'placeholder' => '••••••••',
                        'autocomplete' => 'new-password',
                    ],
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'setup.admin.password_required'),
                    new Assert\Length(min: 8, max: 255, minMessage: 'setup.admin.password_min', maxMessage: 'setup.admin.password_max'),
                    new Assert\Regex(
                        pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/',
                        message: 'setup.admin.password_strength'
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'admin_user',
            'translation_domain' => 'admin',
        ]);
    }
}
